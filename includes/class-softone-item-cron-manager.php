<?php
/**
 * Coordinates WP-Cron scheduling for the Softone item sync.
 *
 * @package Softone_Woocommerce_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Softone_Item_Cron_Manager' ) ) {
	/**
	 * Provides scheduling helpers and cron callbacks for the item sync workflow.
	 */
	class Softone_Item_Cron_Manager {

		/** @var Softone_Item_Sync */
		protected $item_sync;

		/** @var WC_Logger|Psr\Log\LoggerInterface|null */
		protected $logger;

		/**
		 * @param Softone_Item_Sync                             $item_sync Item synchronisation service.
		 * @param WC_Logger|Psr\Log\LoggerInterface|null $logger    Optional logger instance.
		 */
		public function __construct( Softone_Item_Sync $item_sync, $logger = null ) {
			$this->item_sync = $item_sync;
			$this->logger    = $logger;
		}

		/**
		 * Register WordPress hooks for scheduling the cron event.
		 *
		 * @param Softone_Woocommerce_Integration_Loader $loader Hook loader.
		 * @return void
		 */
		public function register_hooks( Softone_Woocommerce_Integration_Loader $loader ) {
			$loader->add_action( 'init', $this, 'ensure_schedule' );
			$loader->add_action( Softone_Item_Sync::CRON_HOOK, $this, 'run_scheduled_sync', 10, 0 );
		}

		/** @return void */
		public function ensure_schedule() {
			self::schedule_event();
		}

		/**
		 * Schedule the Softone item sync cron event.
		 *
		 * @return void
		 */
		public static function schedule_event() {
			if ( wp_next_scheduled( Softone_Item_Sync::CRON_HOOK ) ) {
				return;
			}

			$interval = apply_filters( 'softone_wc_integration_item_sync_interval', Softone_Item_Sync::DEFAULT_CRON_EVENT );
			if ( ! is_string( $interval ) || '' === $interval ) {
				$interval = Softone_Item_Sync::DEFAULT_CRON_EVENT;
			}

			wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, Softone_Item_Sync::CRON_HOOK );
		}

		/**
		 * Clear any scheduled Softone item sync cron events.
		 *
		 * @return void
		 */
		public static function clear_scheduled_event() {
			if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
				wp_clear_scheduled_hook( Softone_Item_Sync::CRON_HOOK );
				return;
			}

			$timestamp = wp_next_scheduled( Softone_Item_Sync::CRON_HOOK );

			while ( false !== $timestamp ) {
				wp_unschedule_event( $timestamp, Softone_Item_Sync::CRON_HOOK );
				$timestamp = wp_next_scheduled( Softone_Item_Sync::CRON_HOOK );
			}
		}

		/**
		 * Cron callback used to trigger the item synchronisation run.
		 *
		 * @return void
		 */
		public function run_scheduled_sync() {
			try {
				$result = $this->item_sync->sync();
				if ( isset( $result['processed'] ) ) {
					$timestamp = isset( $result['started_at'] ) ? (int) $result['started_at'] : time();
					update_option( Softone_Item_Sync::OPTION_LAST_RUN, $timestamp );
				}
			} catch ( Exception $exception ) {
				$this->log( 'error', $exception->getMessage(), array( 'exception' => $exception ) );
			}
		}

		/**
		 * Proxy logging helper that mirrors the sync service behaviour.
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
				$context['source'] = Softone_Item_Sync::LOGGER_SOURCE;
			}

			$this->logger->log( $level, $message, $context );
		}
	}
}
