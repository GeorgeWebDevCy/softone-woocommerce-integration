<?php
/**
 * Category synchronisation logger helper.
 *
 * @package    Softone_Woocommerce_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Softone_Category_Sync_Logger' ) ) {
    /**
     * Provides structured logging for category synchronisation events.
     */
    class Softone_Category_Sync_Logger {

        const LOGGER_SOURCE = 'softone-category-sync';

        /**
         * Underlying logger instance.
         *
         * @var WC_Logger|Psr\Log\LoggerInterface|null
         */
        protected $logger;

        /**
         * Constructor.
         *
         * @param WC_Logger|Psr\Log\LoggerInterface|null $logger Optional logger instance.
         */
        public function __construct( $logger = null ) {
            if ( null === $logger && function_exists( 'wc_get_logger' ) ) {
                $logger = wc_get_logger();
            }

            $this->logger = $logger;
        }

        /**
         * Log a category assignment event.
         *
         * @param int   $product_id   Product identifier.
         * @param array $category_ids Assigned category identifiers.
         * @param array $context      Additional context details.
         *
         * @return void
         */
        public function log_assignment( $product_id, array $category_ids, array $context = array() ) {
            $product_id   = (int) $product_id;
            $category_ids = $this->sanitize_category_ids( $category_ids );

            if ( $product_id <= 0 || empty( $category_ids ) ) {
                return;
            }

            $context = $this->prepare_context( $product_id, $category_ids, $context );

            $this->log( 'info', 'SOFTONE_CAT_SYNC_011 Assigned product categories.', $context );
        }

        /**
         * Ensure category identifiers are integers.
         *
         * @param array $category_ids Raw category identifiers.
         *
         * @return array<int>
         */
        protected function sanitize_category_ids( array $category_ids ) {
            $sanitized = array();

            foreach ( $category_ids as $category_id ) {
                $category_id = (int) $category_id;

                if ( $category_id > 0 ) {
                    $sanitized[] = $category_id;
                }
            }

            return array_values( array_unique( $sanitized ) );
        }

        /**
         * Build the logging context payload.
         *
         * @param int   $product_id   Product identifier.
         * @param array $category_ids Assigned category identifiers.
         * @param array $context      Additional context data.
         *
         * @return array<string,mixed>
         */
        protected function prepare_context( $product_id, array $category_ids, array $context ) {
            $base_context = array(
                'product_id'     => $product_id,
                'category_ids'   => $category_ids,
                'category_count' => count( $category_ids ),
            );

            $context = array_merge( (array) $context, $base_context );

            return $this->enrich_context_with_terms( $context, $category_ids );
        }

        /**
         * Append human-readable category information when available.
         *
         * @param array $context      Current context payload.
         * @param array $category_ids Assigned category identifiers.
         *
         * @return array<string,mixed>
         */
        protected function enrich_context_with_terms( array $context, array $category_ids ) {
            if ( ! function_exists( 'get_term' ) ) {
                return $context;
            }

            $names = array();
            $slugs = array();

            foreach ( $category_ids as $category_id ) {
                $term = get_term( $category_id, 'product_cat' );

                if ( function_exists( 'is_wp_error' ) && is_wp_error( $term ) ) {
                    continue;
                }

                if ( class_exists( 'WP_Error' ) && $term instanceof WP_Error ) {
                    continue;
                }

                $name = $this->extract_term_property( $term, 'name' );
                $slug = $this->extract_term_property( $term, 'slug' );

                if ( '' !== $name ) {
                    $names[] = $name;
                }

                if ( '' !== $slug ) {
                    $slugs[] = $slug;
                }
            }

            if ( ! empty( $names ) ) {
                $context['category_names'] = array_values( array_unique( $names ) );
            }

            if ( ! empty( $slugs ) ) {
                $context['category_slugs'] = array_values( array_unique( $slugs ) );
            }

            return $context;
        }

        /**
         * Extract a property from a term-like structure.
         *
         * @param mixed  $term     Term object or array.
         * @param string $property Property name.
         *
         * @return string
         */
        protected function extract_term_property( $term, $property ) {
            if ( class_exists( 'WP_Term' ) && $term instanceof WP_Term && isset( $term->$property ) ) {
                return (string) $term->$property;
            }

            if ( is_object( $term ) && isset( $term->$property ) ) {
                return (string) $term->$property;
            }

            if ( is_array( $term ) && isset( $term[ $property ] ) ) {
                return (string) $term[ $property ];
            }

            return '';
        }

        /**
         * Proxy log calls to the underlying logger.
         *
         * @param string $level   Log level.
         * @param string $message Log message.
         * @param array  $context Context payload.
         *
         * @return void
         */
        protected function log( $level, $message, array $context = array() ) {
            if ( ! $this->logger || ! method_exists( $this->logger, 'log' ) ) {
                return;
            }

            if ( ! isset( $context['source'] ) ) {
                $context['source'] = self::LOGGER_SOURCE;
            }

            $this->logger->log( $level, $message, $context );
        }
    }
}
