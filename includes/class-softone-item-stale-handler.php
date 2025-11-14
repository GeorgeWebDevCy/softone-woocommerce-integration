<?php
/**
* Handles Softone-managed product stale state updates.
*
* @package Softone_Woocommerce_Integration
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Softone_Item_Stale_Handler' ) ) {
	/**
	 * Applies post-run actions to WooCommerce products that were not touched
	 * during the latest Softone import cycle.
	 */
	class Softone_Item_Stale_Handler {

		/** @var WC_Logger|Psr\Log\LoggerInterface|null */
		protected $logger;

		/**
		 * @param WC_Logger|Psr\Log\LoggerInterface|null $logger Optional logger instance.
		 */
		public function __construct( $logger = null ) {
			$this->logger = $logger;
		}

		/**
		 * Mark Softone managed products as stale when they were not processed during the run.
		 *
		 * @param int $run_timestamp Sync run start timestamp.
		 * @return int Number of products processed.
		 */
		public function handle( $run_timestamp ) {
			if ( ! is_numeric( $run_timestamp ) || $run_timestamp <= 0 ) {
				return 0;
			}

			$action = apply_filters( 'softone_wc_integration_stale_item_action', 'stock_out' );
			if ( ! in_array( $action, array( 'draft', 'stock_out' ), true ) ) {
				$action = 'stock_out';
			}

			$batch_size = (int) apply_filters( 'softone_wc_integration_stale_item_batch_size', 50 );
			if ( $batch_size <= 0 ) {
				$batch_size = 50;
			}

			$processed = 0;

			do {
				$query = new WP_Query(
					array(
						'post_type'      => 'product',
						'post_status'    => 'any',
						'fields'         => 'ids',
						'posts_per_page' => $batch_size,
						'orderby'        => 'ID',
						'order'          => 'ASC',
						'meta_query'     => array(
							'relation' => 'AND',
							array(
								'key'     => Softone_Item_Sync::META_MTRL,
								'compare' => 'EXISTS',
							),
							array(
								'relation' => 'OR',
								array(
									'key'     => Softone_Item_Sync::META_LAST_SYNC,
									'compare' => 'NOT EXISTS',
								),
								array(
									'key'     => Softone_Item_Sync::META_LAST_SYNC,
									'value'   => (int) $run_timestamp,
									'type'    => 'NUMERIC',
									'compare' => '<',
								),
							),
						),
					)
				);

				if ( ! $query->have_posts() ) {
					wp_reset_postdata();
					break;
				}

				foreach ( $query->posts as $product_id ) {
					$processed++;

					$product = wc_get_product( $product_id );
					if ( ! $product ) {
						$this->log( 'warning', 'Unable to load product while marking as stale.', array( 'product_id' => $product_id ) );
						update_post_meta( $product_id, Softone_Item_Sync::META_LAST_SYNC, (int) $run_timestamp );
						continue;
					}

					$sku = '';
					if ( method_exists( $product, 'get_sku' ) ) {
						$sku = (string) $product->get_sku();
					}

					if ( 'draft' === $action ) {
						if ( 'draft' !== $product->get_status() ) {
							$product->set_status( 'draft' );
						}
					} else {
						if ( 'publish' !== $product->get_status() ) {
							$product->set_status( 'publish' );
						}
						$product->set_stock_status( 'outofstock' );
					}

					$product->save();

					if ( '' !== $sku && class_exists( 'Softone_Sku_Image_Attacher' ) ) {
						Softone_Sku_Image_Attacher::attach_gallery_from_sku( (int) $product_id, $sku );
					}

					update_post_meta( $product_id, Softone_Item_Sync::META_LAST_SYNC, (int) $run_timestamp );

					$this->log(
						'info',
						'Marked product as stale following Softone sync run.',
						array(
							'product_id' => $product_id,
							'action'     => $action,
						)
					);
				}

				wp_reset_postdata();
			} while ( true );

			if ( $processed > 0 ) {
				$this->log(
					'notice',
					sprintf( 'Handled %d stale Softone products.', $processed ),
					array(
						'action'     => $action,
						'timestamp'  => $run_timestamp,
						'batch_size' => $batch_size,
					)
				);
			}

			return $processed;
		}

		/**
		 * Proxy logging helper that mirrors the behaviour used by the sync class.
		 *
		 * @param string $level   Log level.
		 * @param string $message Message.
		 * @param array  $context Context array.
		 * @return void
		 */
		protected function log( $level, $message, array $context = array() ) {
			if ( ! $this->logger || ! method_exists( $this->logger, 'log' ) ) {
				return;
			}

			if ( class_exists( 'WC_Logger' ) && $this->logger instanceof WC_Logger ) {
				$context['source'] = class_exists( 'Softone_Item_Sync' ) ? Softone_Item_Sync::LOGGER_SOURCE : 'softone-item-sync';
			}

			$this->logger->log( $level, $message, $context );
		}
	}
}
