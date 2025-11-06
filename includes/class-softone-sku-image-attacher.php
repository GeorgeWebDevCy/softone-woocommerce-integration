<?php
/**
 * Attach images to a WooCommerce product based on its SKU.
 * Matches media filenames that begin with the SKU and optional separators/spaces:
 *   523000829.jpg
 *   523000829_1.jpeg
 *   523000829 _2.png
 *   523000829-03.webp
 *
 * Featured image priority: _1 > no suffix > smallest suffix
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Softone_Sku_Image_Attacher' ) ) {

    class Softone_Sku_Image_Attacher {

        /**
         * Public entrypoint.
         *
         * @param int    $product_id Woo product ID.
         * @param string $sku        Product SKU (non-empty).
         * @return void
         */
        public static function attach_gallery_from_sku( $product_id, $sku ) {
            $sku = trim( (string) $sku );
            $product_id = (int) $product_id;

            if ( $product_id <= 0 || $sku === '' ) {
                return;
            }

            $attachments = self::find_attachments_for_sku( $sku );

            if ( empty( $attachments ) ) {
                self::log( sprintf( 'No images found for SKU %s', $sku ) );
                return;
            }

            // Sort: feature priority to _1 if present, else plain (no numeric suffix), then ascending by suffix number.
            $ordered = self::order_attachments( $attachments, $sku );

            $featured = null;
            $gallery  = [];

            if ( ! empty( $ordered ) ) {
                $featured = array_shift( $ordered );
                $gallery  = $ordered;
            }

            // Set featured image.
            if ( $featured ) {
                set_post_thumbnail( $product_id, $featured->ID );
                self::log( sprintf( 'Set featured image #%d (%s) for product %d', $featured->ID, $featured->guid, $product_id ) );
            }

            // Build gallery IDs (comma-separated).
            $gallery_ids = [];
            foreach ( $gallery as $g ) {
                $gallery_ids[] = (string) $g->ID;
            }

            // Replace existing gallery.
            update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
            self::log( sprintf( 'Set gallery [%s] for product %d', implode( ',', $gallery_ids ), $product_id ) );

            // Attach each image to the product as parent (nice-to-have).
            foreach ( array_merge( $featured ? [ $featured ] : [], $gallery ) as $img ) {
                if ( (int) $img->post_parent !== $product_id ) {
                    wp_update_post( [
                        'ID'          => (int) $img->ID,
                        'post_parent' => $product_id,
                    ] );
                }
            }
        }

        /**
         * Find attachment posts whose filenames start with the SKU and are images.
         *
         * @param string $sku
         * @return array of WP_Post rows (ID, guid, post_title, post_parent).
         */
        protected static function find_attachments_for_sku( $sku ) {
            global $wpdb;

            // We’ll match by GUID (URL) or _wp_attached_file (path). GUID is sufficient in most installs.
            // Allow separators after the SKU: '', '_', ' _', '-', ' -', etc. and any extension.
            // Example WHERE: guid RLIKE '/523000829([[:space:]_-]|\\.)'
            $sku_escaped = preg_quote( $sku, '/' );

            // REGEXP for filename part:
            //   (^|/)SKU(\s|_|-)*([0-9]+)?\.(jpg|jpeg|png|webp|gif)$
            // Using MySQL RLIKE syntax:
            $regexp = sprintf(
                '(^|/)%s([[:space:]]|_|-)*([0-9]+)?\\.(jpg|jpeg|png|webp|gif)$',
                $sku_escaped
            );

            // Query attachments likely to match (restrict to images).
            $sql = $wpdb->prepare(
                "
                SELECT p.ID, p.post_title, p.post_parent, p.guid
                FROM {$wpdb->posts} p
                WHERE p.post_type = 'attachment'
                  AND p.post_mime_type LIKE 'image/%%'
                  AND (p.guid RLIKE %s OR EXISTS (
                        SELECT 1 FROM {$wpdb->postmeta} pm
                        WHERE pm.post_id = p.ID
                          AND pm.meta_key = '_wp_attached_file'
                          AND pm.meta_value RLIKE %s
                  ))
                ",
                $regexp,
                $regexp
            );

            $rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

            // Filter again with PHP to be strict and handle odd spaces.
            $filtered = [];
            foreach ( $rows as $row ) {
                $fn = self::filename_from_url( $row->guid );
                if ( $fn && self::filename_starts_with_sku( $fn, $sku ) ) {
                    $filtered[] = $row;
                }
            }

            return $filtered;
        }

        /**
         * Convert a URL to just the filename.ext
         */
        protected static function filename_from_url( $url ) {
            if ( ! is_string( $url ) || $url === '' ) {
                return null;
            }
            $parts = wp_parse_url( $url );
            if ( empty( $parts['path'] ) ) {
                return null;
            }
            $base = basename( $parts['path'] );
            return $base ?: null;
        }

        /**
         * Return true if filename starts with SKU and optional separators/spaces.
         */
        protected static function filename_starts_with_sku( $filename, $sku ) {
            // Normalize weird spaces like "523000829 _1.jpeg"
            $pattern = '/^' . preg_quote( $sku, '/' ) . '(?:[ _-]*)/i';
            return (bool) preg_match( $pattern, $filename );
        }

        /**
         * Sort attachments with priority:
         * 1) explicit _1 (or -1, or " 1") is FIRST
         * 2) no numeric suffix is SECOND
         * 3) then _2, _3, ... by ascending number
         */
        protected static function order_attachments( array $attachments, $sku ) {
            $decorated = [];

            foreach ( $attachments as $att ) {
                $fn = self::filename_from_url( $att->guid );
                $suffix_num = self::extract_suffix_number( $fn, $sku ); // int|null
                $has_number = is_int( $suffix_num );

                // Compute a tuple to sort on.
                // Lower tuple sorts earlier.
                // (_1) => (0, 1)
                // (no number) => (1, 0)
                // (_2) => (2, 2), (_3) => (2, 3), etc.
                if ( $has_number ) {
                    if ( $suffix_num === 1 ) {
                        $bucket = 0;
                        $order  = 1;
                    } else {
                        $bucket = 2;
                        $order  = $suffix_num;
                    }
                } else {
                    $bucket = 1;
                    $order  = 0;
                }

                $decorated[] = [ $bucket, $order, $att ];
            }

            usort( $decorated, function( $a, $b ) {
                if ( $a[0] !== $b[0] ) {
                    return $a[0] - $b[0];
                }
                if ( $a[1] !== $b[1] ) {
                    return $a[1] - $b[1];
                }
                return 0;
            } );

            return array_map( fn( $row ) => $row[2], $decorated );
        }

        /**
         * Extract numeric suffix after the SKU, e.g. "523000829_3.jpg" => 3.
         * Returns null if no explicit numeric suffix is found.
         */
        protected static function extract_suffix_number( $filename, $sku ) {
            if ( ! is_string( $filename ) || $filename === '' ) {
                return null;
            }
            // Allow separators/spaces between SKU and number. The number must be right before extension.
            // Examples matched: 523000829_1.jpg, 523000829 - 2.png, 5230008293.jpg (this last should NOT match as suffix).
            $pattern = '/^' . preg_quote( $sku, '/' ) . '(?:[ _-]+)(\d+)\.[a-z0-9]+$/i';
            if ( preg_match( $pattern, $filename, $m ) ) {
                return (int) $m[1];
            }
            return null;
        }

        /**
         * Optional logging to your existing logger if present.
         */
        protected static function log( $message ) {
            if ( class_exists( 'Softone_Sync_Activity_Logger' ) ) {
                Softone_Sync_Activity_Logger::log( '[SKU Image Attacher] ' . $message );
            } elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // Fallback debug_log
                error_log( '[SKU Image Attacher] ' . $message );
            }
        }
    }
}
