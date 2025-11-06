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
            $product_id = (int) $product_id;
            $sku        = self::safe_utf8( trim( (string) $sku ) );

            if ( $product_id <= 0 || $sku === '' ) {
                return;
            }

            $attachments = self::find_attachments_for_sku( $sku );

            if ( empty( $attachments ) ) {
                self::log( 'info', sprintf( 'No images found for SKU %s', $sku ) );
                return;
            }

            // Sort: _1 first, then no number, then ascending numeric suffix.
            $ordered  = self::order_attachments( $attachments, $sku );
            $featured = null;
            $gallery  = array();

            if ( ! empty( $ordered ) ) {
                $featured = array_shift( $ordered );
                $gallery  = $ordered;
            }

            // Set featured image.
            if ( $featured && function_exists( 'set_post_thumbnail' ) ) {
                set_post_thumbnail( $product_id, (int) $featured->ID );
                self::log( 'info', sprintf( 'Set featured image #%d for product %d', (int) $featured->ID, $product_id ) );
            }

            // Build gallery IDs (comma-separated).
            $gallery_ids = array();
            foreach ( $gallery as $g ) {
                $gallery_ids[] = (string) (int) $g->ID;
            }

            // Replace existing gallery.
            update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
            self::log( 'info', sprintf( 'Set gallery [%s] for product %d', implode( ',', $gallery_ids ), $product_id ) );

            // Attach each image to the product as parent (tidy media library).
            if ( ! empty( $featured ) ) {
                $gallery = array_merge( array( $featured ), $gallery );
            }
            foreach ( $gallery as $img ) {
                $img_id = (int) $img->ID;
                if ( $img_id > 0 && (int) $img->post_parent !== $product_id ) {
                    wp_update_post(
                        array(
                            'ID'          => $img_id,
                            'post_parent' => $product_id,
                        )
                    );
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

            // Escape for regex; also keep it safe UTF-8.
            $sku       = self::safe_utf8( $sku );
            $sku_esc   = preg_quote( $sku, '/' );

            // MySQL RLIKE regex for common image extensions; filename starts with SKU then optional separators then optional number.
            // (^|/)<SKU>(\s|_|-)*([0-9]+)?\.(jpg|jpeg|png|webp|gif)$
            $regexp = sprintf(
                '(^|/)%s([[:space:]]|_|-)*([0-9]+)?\\.(jpg|jpeg|png|webp|gif)$',
                $sku_esc
            );

            // Query attachments likely to match (restrict to images).
            $sql = $wpdb->prepare(
                "
                SELECT
                    p.ID,
                    p.post_title,
                    p.post_parent,
                    p.guid,
                    MAX(pm.meta_value) AS attached_file
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm
                    ON pm.post_id = p.ID
                    AND pm.meta_key = '_wp_attached_file'
                WHERE p.post_type = 'attachment'
                  AND p.post_mime_type LIKE 'image/%%'
                  AND (p.guid RLIKE %s OR pm.meta_value RLIKE %s)
                GROUP BY p.ID, p.post_title, p.post_parent, p.guid
                ",
                $regexp,
                $regexp
            );

            $rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

            // Extra pass with PHP to be strict and handle odd spaces.
            $filtered = array();
            foreach ( (array) $rows as $row ) {
                $candidates = array();

                if ( ! empty( $row->attached_file ) ) {
                    $candidate = self::filename_from_url( $row->attached_file );
                    if ( $candidate ) {
                        $candidates[] = $candidate;
                    }
                }

                $from_guid = self::filename_from_url( $row->guid );
                if ( $from_guid ) {
                    $candidates[] = $from_guid;
                }

                $candidates = array_values( array_unique( array_filter( $candidates ) ) );

                foreach ( $candidates as $candidate ) {
                    $candidate = self::safe_utf8( $candidate );
                    if ( self::filename_starts_with_sku( $candidate, $sku ) ) {
                        $row->softone_filename = $candidate;
                        $filtered[]             = $row;
                        break;
                    }
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
            $path  = '';

            if ( false === $parts ) {
                $path = $url;
            } elseif ( isset( $parts['path'] ) ) {
                $path = $parts['path'];
            }

            if ( '' === $path ) {
                $path = $url;
            }

            $base = basename( $path );
            return $base ?: null;
        }

        /**
         * Return true if filename starts with SKU and optional separators/spaces.
         */
        protected static function filename_starts_with_sku( $filename, $sku ) {
            $filename = self::safe_utf8( $filename );
            $sku      = self::safe_utf8( $sku );
            $pattern  = '/^' . preg_quote( $sku, '/' ) . '(?:[ _-]*)/iu';
            return (bool) preg_match( $pattern, $filename );
        }

        /**
         * Sort attachments with priority:
         * 1) explicit _1 (or -1, or space 1) is FIRST
         * 2) no numeric suffix is SECOND
         * 3) then _2, _3, ... by ascending number
         */
        protected static function order_attachments( array $attachments, $sku ) {
            $decorated = array();

            foreach ( $attachments as $att ) {
                $fn         = self::attachment_filename( $att );
                $suffix_num = self::extract_suffix_number( $fn, $sku ); // int|null
                $has_number = is_int( $suffix_num );

                // Compute a tuple to sort on.
                // Lower tuple sorts earlier.
                // (_1) => (0, 1)
                // (no number) => (1, 0)
                // (_2) => (2, 2), (_3) => (2, 3), etc.
                if ( $has_number ) {
                    if ( 1 === $suffix_num ) {
                        $bucket = 0;
                        $order  = 1;
                    } else {
                        $bucket = 2;
                        $order  = (int) $suffix_num;
                    }
                } else {
                    $bucket = 1;
                    $order  = 0;
                }

                $decorated[] = array( $bucket, $order, $att );
            }

            usort(
                $decorated,
                function( $a, $b ) {
                    if ( $a[0] !== $b[0] ) {
                        return $a[0] - $b[0];
                    }
                    if ( $a[1] !== $b[1] ) {
                        return $a[1] - $b[1];
                    }
                    return 0;
                }
            );

            return array_map(
                static function( $row ) {
                    return $row[2];
                },
                $decorated
            );
        }

        /**
         * Return the best-effort filename for an attachment row.
         *
         * @param object $attachment WP_Post-like attachment row.
         * @return string
         */
        protected static function attachment_filename( $attachment ) {
            if ( ! is_object( $attachment ) ) {
                return '';
            }

            if ( ! empty( $attachment->softone_filename ) ) {
                return self::safe_utf8( (string) $attachment->softone_filename );
            }

            if ( ! empty( $attachment->attached_file ) ) {
                $candidate = self::filename_from_url( $attachment->attached_file );
                if ( $candidate ) {
                    return self::safe_utf8( $candidate );
                }
            }

            if ( isset( $attachment->guid ) ) {
                $candidate = self::filename_from_url( $attachment->guid );
                if ( $candidate ) {
                    return self::safe_utf8( $candidate );
                }
            }

            return '';
        }

        /**
         * Extract numeric suffix after the SKU, e.g. "523000829_3.jpg" => 3.
         * Returns null if no explicit numeric suffix is found.
         */
        protected static function extract_suffix_number( $filename, $sku ) {
            if ( ! is_string( $filename ) || $filename === '' ) {
                return null;
            }
            $filename = self::safe_utf8( $filename );
            $sku      = self::safe_utf8( $sku );

            // Remove the extension to make matching duplicate-safe, e.g. `_1-1`.
            $base = $filename;
            $dot  = strrpos( $base, '.' );
            if ( false !== $dot ) {
                $base = substr( $base, 0, $dot );
            }

            $pattern = '/^' . preg_quote( $sku, '/' ) . '(?:[ _-]+)(\d+)(?:\D.*)?$/iu';
            if ( preg_match( $pattern, $base, $m ) ) {
                return (int) $m[1];
            }

            return null;
        }

        /**
         * Minimal, defensive UTF-8 normalizer to reduce iconv notices downstream.
         * Returns a UTF-8 string with invalid sequences stripped when possible.
         */
        protected static function safe_utf8( $str ) {
            $str = (string) $str;

            // If WordPress helper exists, rely on it first.
            if ( function_exists( 'wp_check_invalid_utf8' ) ) {
                $checked = wp_check_invalid_utf8( $str, true );
                if ( $checked !== $str ) {
                    return (string) $checked;
                }
            }

            // Try mbstring conversion if available.
            if ( function_exists( 'mb_convert_encoding' ) ) {
                // Attempt to detect/convert common Greek encodings as well.
                $converted = @mb_convert_encoding( $str, 'UTF-8', 'UTF-8, ISO-8859-7, Windows-1253, ISO-8859-1' );
                if ( is_string( $converted ) && $converted !== '' ) {
                    return $converted;
                }
            }

            // Last resort: strip invalid bytes.
            return preg_replace( '/[^\x09\x0A\x0D\x20-\x7E\xC2-\xF4][^\x80-\xBF]*/', '', $str );
        }

        /**
         * Lightweight logger: WC logger if available, else error_log.
         *
         * @param string $level  'info'|'debug'|'warning'|'error'|'notice'
         * @param string $message
         * @param array  $context
         * @return void
         */
        protected static function log( $level, $message, array $context = array() ) {
            // Prefer WooCommerce logger.
            if ( function_exists( 'wc_get_logger' ) ) {
                $logger  = wc_get_logger();
                $context = array_merge( array( 'source' => 'softone-sku-image-attacher' ), $context );
                // Guard against invalid levels on older WC.
                $valid_levels = array( 'emergency','alert','critical','error','warning','notice','info','debug' );
                if ( ! in_array( $level, $valid_levels, true ) ) {
                    $level = 'info';
                }
                $logger->log( $level, $message, $context );
                return;
            }

            // Fallback to PHP error_log when WP_DEBUG is on.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                $prefix = '[SKU Image Attacher][' . strtoupper( (string) $level ) . '] ';
                $line   = $prefix . $message;
                if ( ! empty( $context ) ) {
                    $line .= ' ' . wp_json_encode( $context );
                }
                error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }
    }
}
