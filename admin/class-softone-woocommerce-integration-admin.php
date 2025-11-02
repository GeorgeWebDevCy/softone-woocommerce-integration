<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://www.georgenicolaou.me/
 * @since      1.0.0
 *
 * @package    Softone_Woocommerce_Integration
 * @subpackage Softone_Woocommerce_Integration/admin
 */

if ( ! class_exists( 'Softone_Sync_Activity_Logger' ) ) {
        require_once dirname( __DIR__ ) . '/includes/class-softone-sync-activity-logger.php';
}

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and the admin settings page.
 *
 * @package    Softone_Woocommerce_Integration
 * @subpackage Softone_Woocommerce_Integration/admin
 * @author     George Nicolaou <orionas.elite@gmail.com>
 */
class Softone_Woocommerce_Integration_Admin {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
        private $menu_slug = 'softone-woocommerce-integration-settings';

        /**
         * Category log submenu slug.
         *
         * @var string
         */
        private $category_logs_slug = 'softone-woocommerce-integration-category-logs';

        /**
         * Sync activity viewer submenu slug.
         *
         * @var string
         */
        private $sync_activity_slug = 'softone-woocommerce-integration-sync-activity';

        /**
         * API tester submenu slug.
         *
         * @var string
         */
        private $api_tester_slug = 'softone-woocommerce-integration-api-tester';

        /**
         * Maximum number of category log entries to display.
         *
         * @var int
         */
        private $category_log_limit = 200;

        /**
         * Capability required to manage plugin settings.
         *
         * @var string
         */
	private $capability = 'manage_options';

        /**
         * Action name for the API tester handler.
         *
         * @var string
         */
        private $api_tester_action = 'softone_wc_integration_api_tester';

        /**
         * Action name for clearing the sync activity log.
         *
         * @var string
         */
        private $clear_activity_action = 'softone_wc_integration_clear_sync_activity';

        /**
         * Base transient key for connection test notices.
         *
         * @var string
         */
	private $test_notice_transient = 'softone_wc_integration_test_notice_';

        /**
         * Base transient key for API tester responses.
         *
         * @var string
         */
	private $api_tester_transient = 'softone_wc_integration_api_tester_';

        /**
         * Base transient key for import notices.
         *
         * @var string
         */
	private $import_notice_transient = 'softone_wc_integration_import_notice_';

        /**
         * Item synchronisation service.
         *
         * @var Softone_Item_Sync
         */
        private $item_sync;

        /**
         * File-based sync activity logger.
         *
         * @var Softone_Sync_Activity_Logger|null
         */
        private $activity_logger;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param string $plugin_name The name of this plugin.
	 * @param string $version     The version of this plugin.
	 */
        public function __construct( $plugin_name, $version, Softone_Item_Sync $item_sync, ?Softone_Sync_Activity_Logger $activity_logger = null ) {

                $this->plugin_name = $plugin_name;
                $this->version     = $version;
                $this->item_sync   = $item_sync;
                $this->activity_logger = $activity_logger ?: new Softone_Sync_Activity_Logger();

        }

	/**
	 * Register the plugin submenu.
	 */
	public function register_menu() {

		if ( ! current_user_can( $this->capability ) ) {
			return;
		}

                $page_title = __( 'Softone Integration', 'softone-woocommerce-integration' );

                add_menu_page(
                        $page_title,
                        $page_title,
                        $this->capability,
                        $this->menu_slug,
                        array( $this, 'render_settings_page' ),
                        'dashicons-update-alt',
                        56
                );

                add_submenu_page(
                        $this->menu_slug,
                        __( 'Settings', 'softone-woocommerce-integration' ),
                        __( 'Settings', 'softone-woocommerce-integration' ),
                        $this->capability,
                        $this->menu_slug,
                        array( $this, 'render_settings_page' )
                );

                add_submenu_page(
                        $this->menu_slug,
                        __( 'Category Sync Logs', 'softone-woocommerce-integration' ),
                        __( 'Category Sync Logs', 'softone-woocommerce-integration' ),
                        $this->capability,
                        $this->category_logs_slug,
                        array( $this, 'render_category_logs_page' )
                );

                add_submenu_page(
                        $this->menu_slug,
                        __( 'Sync Activity', 'softone-woocommerce-integration' ),
                        __( 'Sync Activity', 'softone-woocommerce-integration' ),
                        $this->capability,
                        $this->sync_activity_slug,
                        array( $this, 'render_sync_activity_page' )
                );

                add_submenu_page(
                        $this->menu_slug,
                        __( 'API Tester', 'softone-woocommerce-integration' ),
                        __( 'API Tester', 'softone-woocommerce-integration' ),
                        $this->capability,
                        $this->api_tester_slug,
                        array( $this, 'render_api_tester_page' )
                );

                add_submenu_page(
                        'woocommerce',
                        $page_title,
                        $page_title,
                        $this->capability,
                        $this->menu_slug,
                        array( $this, 'render_settings_page' )
                );

                add_submenu_page(
                        'woocommerce',
                        __( 'Category Sync Logs', 'softone-woocommerce-integration' ),
                        __( 'Category Sync Logs', 'softone-woocommerce-integration' ),
                        $this->capability,
                        $this->category_logs_slug,
                        array( $this, 'render_category_logs_page' )
                );

                add_submenu_page(
                        'woocommerce',
                        __( 'Sync Activity', 'softone-woocommerce-integration' ),
                        __( 'Sync Activity', 'softone-woocommerce-integration' ),
                        $this->capability,
                        $this->sync_activity_slug,
                        array( $this, 'render_sync_activity_page' )
                );

        }

	/**
	 * Register plugin settings and fields.
	 */
	public function register_settings() {

		register_setting(
			'softone_wc_integration',
			Softone_API_Client::OPTION_SETTINGS_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);

		add_settings_section(
			'softone_wc_integration_api',
			__( 'Softone API Credentials', 'softone-woocommerce-integration' ),
			array( $this, 'render_settings_section_intro' ),
			'softone_wc_integration'
		);

		$this->add_text_field( 'endpoint', __( 'Endpoint URL', 'softone-woocommerce-integration' ) );
		$this->add_text_field( 'username', __( 'Username', 'softone-woocommerce-integration' ) );
		$this->add_text_field( 'password', __( 'Password', 'softone-woocommerce-integration' ), 'password' );
		$this->add_text_field( 'app_id', __( 'App ID', 'softone-woocommerce-integration' ) );
		$this->add_text_field( 'company', __( 'Company', 'softone-woocommerce-integration' ) );
		$this->add_text_field( 'branch', __( 'Branch', 'softone-woocommerce-integration' ) );
		$this->add_text_field( 'module', __( 'Module', 'softone-woocommerce-integration' ) );
                $this->add_text_field( 'refid', __( 'Ref ID', 'softone-woocommerce-integration' ) );
                $this->add_text_field( 'default_saldoc_series', __( 'Default SALDOC Series', 'softone-woocommerce-integration' ) );
                $this->add_text_field( 'warehouse', __( 'Default Warehouse', 'softone-woocommerce-integration' ) );
                $this->add_text_field( 'areas', __( 'Default AREAS', 'softone-woocommerce-integration' ) );
                $this->add_text_field( 'socurrency', __( 'Default SOCURRENCY', 'softone-woocommerce-integration' ) );
                $this->add_text_field( 'trdcategory', __( 'Default TRDCATEGORY', 'softone-woocommerce-integration' ) );
                add_settings_field(
                        'softone_wc_integration_country_mappings',
                        __( 'Country Mappings', 'softone-woocommerce-integration' ),
                        array( $this, 'render_country_mapping_field' ),
                        'softone_wc_integration',
                        'softone_wc_integration_api',
                        array(
                                'key' => 'country_mappings',
                        )
                );

                add_settings_section(
                        'softone_wc_integration_stock_behaviour',
                        __( 'Stock Behaviour', 'softone-woocommerce-integration' ),
                        array( $this, 'render_stock_settings_section_intro' ),
                        'softone_wc_integration'
                );

                $this->add_checkbox_field(
                        'zero_stock_quantity_fallback',
                        __( 'Treat zero Softone stock as one', 'softone-woocommerce-integration' ),
                        __( 'When Softone reports zero quantity the product will be saved with a quantity of one.', 'softone-woocommerce-integration' )
                );

                $this->add_checkbox_field(
                        'backorder_out_of_stock_products',
                        __( 'Mark out of stock products as available on backorder', 'softone-woocommerce-integration' ),
                        __( 'Products that remain out of stock after sync will allow backorders and display as pre-order/backorder.', 'softone-woocommerce-integration' )
                );

        }

	/**
	 * Sanitize plugin settings.
	 *
	 * @param array $settings Raw settings array.
	 *
	 * @return array
	 */
	public function sanitize_settings( $settings ) {

		$settings = is_array( $settings ) ? $settings : array();

		$sanitized = array();

		$endpoint = isset( $settings['endpoint'] ) ? esc_url_raw( trim( (string) $settings['endpoint'] ) ) : '';

		if ( '' !== $endpoint && false === strpos( $endpoint, '?' ) ) {
			$endpoint = untrailingslashit( $endpoint );
		}

		$sanitized['endpoint']              = $endpoint;
		$sanitized['username']              = isset( $settings['username'] ) ? $this->sanitize_text_value( $settings['username'] ) : '';
		$sanitized['password']              = isset( $settings['password'] ) ? $this->sanitize_password_value( $settings['password'] ) : '';
		$sanitized['app_id']                = isset( $settings['app_id'] ) ? $this->sanitize_text_value( $settings['app_id'] ) : '';
		$sanitized['company']               = isset( $settings['company'] ) ? $this->sanitize_text_value( $settings['company'] ) : '';
		$sanitized['branch']                = isset( $settings['branch'] ) ? $this->sanitize_text_value( $settings['branch'] ) : '';
		$sanitized['module']                = isset( $settings['module'] ) ? $this->sanitize_text_value( $settings['module'] ) : '';
                $sanitized['refid']                 = isset( $settings['refid'] ) ? $this->sanitize_text_value( $settings['refid'] ) : '';
                $sanitized['default_saldoc_series'] = isset( $settings['default_saldoc_series'] ) ? $this->sanitize_text_value( $settings['default_saldoc_series'] ) : '';
                $sanitized['warehouse']             = isset( $settings['warehouse'] ) ? $this->sanitize_text_value( $settings['warehouse'] ) : '';
                $sanitized['areas']                 = isset( $settings['areas'] ) ? $this->sanitize_text_value( $settings['areas'] ) : '';
                $sanitized['socurrency']            = isset( $settings['socurrency'] ) ? $this->sanitize_text_value( $settings['socurrency'] ) : '';
                $sanitized['trdcategory']           = isset( $settings['trdcategory'] ) ? $this->sanitize_text_value( $settings['trdcategory'] ) : '';
                $sanitized['country_mappings']      = isset( $settings['country_mappings'] ) ? $this->sanitize_country_mappings( $settings['country_mappings'] ) : array();

                $zero_stock_fallback = $this->sanitize_checkbox_flag( $settings, 'zero_stock_quantity_fallback' );
                $backorder_out_stock = $this->sanitize_checkbox_flag( $settings, 'backorder_out_of_stock_products' );

                if ( 'yes' === $zero_stock_fallback && 'yes' === $backorder_out_stock ) {
                        $backorder_out_stock = 'no';

                        add_settings_error(
                                'softone_wc_integration',
                                'softone_wc_integration_stock_mode_conflict',
                                __( 'Please select only one stock behaviour option at a time.', 'softone-woocommerce-integration' ),
                                'error'
                        );
                }

                $sanitized['zero_stock_quantity_fallback']    = $zero_stock_fallback;
                $sanitized['backorder_out_of_stock_products'] = $backorder_out_stock;

                return $sanitized;

        }

        /**
        * Output introductory copy for the API credential settings section.
        *
        * This helper is registered with the Settings API to precede the
        * Softone connection fields, giving administrators context about the
        * credentials the integration requires.
        *
        * @return void
        */
        public function render_settings_section_intro() {

               echo '<p>' . esc_html__( 'Configure the credentials used to communicate with Softone.', 'softone-woocommerce-integration' ) . '</p>';

        }

        /**
        * Print the lead-in text for the stock behaviour settings section.
        *
        * Registered as the section callback so the WooCommerce stock handling
        * controls are framed before the individual checkboxes and options are
        * displayed.
        *
        * @return void
        */
        public function render_stock_settings_section_intro() {

               echo '<p>' . esc_html__( 'Control how products should be handled when Softone reports little or no stock.', 'softone-woocommerce-integration' ) . '</p>';

        }

        /**
        * Render the main Softone settings screen within the WordPress admin.
        *
        * The page contains the Settings API form for saving credentials,
        * connection tests, and manual import tools, and it surfaces transient
        * notices about recent operations before the forms. Access is guarded by
        * the `$capability` property and the layout relies on private helpers for
        * connection/import notices and {@see Softone_Item_Sync::ADMIN_ACTION} to
        * trigger imports.
        *
        * @see self::maybe_render_connection_notice()
        * @see self::maybe_render_import_notice()
        *
        * @return void
        */
        public function render_settings_page() {

                if ( ! current_user_can( $this->capability ) ) {
                        return;
                }

?>
<div class="wrap">
<h1><?php esc_html_e( 'Softone Integration', 'softone-woocommerce-integration' ); ?></h1>
<?php $this->maybe_render_connection_notice(); ?>
<?php $this->maybe_render_import_notice(); ?>
<?php settings_errors( 'softone_wc_integration' ); ?>
<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
<?php
settings_fields( 'softone_wc_integration' );
do_settings_sections( 'softone_wc_integration' );
submit_button();
?>
</form>

<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin-top: 1.5em;">
<?php wp_nonce_field( 'softone_wc_integration_test_connection' ); ?>
<input type="hidden" name="action" value="softone_wc_integration_test_connection" />
<?php submit_button( __( 'Test Connection', 'softone-woocommerce-integration' ), 'secondary', 'softone_wc_integration_test', false ); ?>
</form>

<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin-top: 1.5em;">
<?php wp_nonce_field( Softone_Item_Sync::ADMIN_ACTION ); ?>
<input type="hidden" name="action" value="<?php echo esc_attr( Softone_Item_Sync::ADMIN_ACTION ); ?>" />
<?php
$last_run = get_option( Softone_Item_Sync::OPTION_LAST_RUN );
if ( $last_run ) {
printf(
'<p><em>%s</em></p>',
esc_html( sprintf( __( 'Last import completed on %s.', 'softone-woocommerce-integration' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last_run ) ) )
);
}
submit_button( __( 'Run Item Import', 'softone-woocommerce-integration' ), 'secondary', 'softone_wc_integration_run_item_import', false );
?>
</form>

<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin-top: 1.5em;">
<?php wp_nonce_field( Softone_Item_Sync::ADMIN_ACTION ); ?>
<input type="hidden" name="action" value="<?php echo esc_attr( Softone_Item_Sync::ADMIN_ACTION ); ?>" />
<input type="hidden" name="softone_wc_integration_force_full" value="1" />
<input type="hidden" name="softone_wc_integration_force_taxonomy_refresh" value="1" />
<p class="description"><?php esc_html_e( 'Force a full item import and refresh category and menu assignments for every product.', 'softone-woocommerce-integration' ); ?></p>
<?php submit_button( __( 'Re-sync Categories & Menus', 'softone-woocommerce-integration' ), 'secondary', 'softone_wc_integration_resync_taxonomies', false ); ?>
</form>
</div>
<?php

        }

        /**
         * Handle requests to delete the sync activity log file.
         *
         * @return void
         */
        public function handle_clear_sync_activity() {

                if ( ! current_user_can( $this->capability ) ) {
                        wp_die( esc_html__( 'You do not have permission to manage this plugin.', 'softone-woocommerce-integration' ) );
                }

                check_admin_referer( $this->clear_activity_action );

                if ( $this->activity_logger && method_exists( $this->activity_logger, 'clear' ) ) {
                        $this->activity_logger->clear();
                }

                $redirect = add_query_arg(
                        array(
                                'page'    => $this->sync_activity_slug,
                                'cleared' => 1,
                        ),
                        admin_url( 'admin.php' )
                );

                wp_safe_redirect( $redirect );
                exit;

        }

        /**
        * Display the category synchronisation log viewer interface.
        *
        * Outputs a paginated-style summary of recent category sync events,
        * including contextual details about log files scanned and any issues
        * accessing WooCommerce logs. Access checks rely on the `$capability`
        * property and entries are sourced through {@see self::get_category_log_entries()},
        * honouring the `$category_log_limit` display cap.
        *
        * @return void
        */
        public function render_category_logs_page() {

                if ( ! current_user_can( $this->capability ) ) {
                        return;
                }

                $result          = $this->get_category_log_entries();
                $entries         = isset( $result['entries'] ) && is_array( $result['entries'] ) ? $result['entries'] : array();
                $scanned_files   = isset( $result['files'] ) && is_array( $result['files'] ) ? $result['files'] : array();
                $log_directory   = isset( $result['directory'] ) ? (string) $result['directory'] : '';
                $error_message   = isset( $result['error'] ) ? (string) $result['error'] : '';
                $displayed_limit = (int) $this->category_log_limit;

                $log_file_names = array();
                foreach ( $scanned_files as $file_path ) {
                        $log_file_names[] = basename( (string) $file_path );
                }

                $entries_heading = esc_attr__( 'Latest category synchronisation events', 'softone-woocommerce-integration' );

?>
<div class="wrap softone-category-logs">
<h1><?php esc_html_e( 'Category Sync Logs', 'softone-woocommerce-integration' ); ?></h1>
<p class="description"><?php esc_html_e( 'Review the SoftOne category creation and assignment activity captured during catalogue imports.', 'softone-woocommerce-integration' ); ?></p>

<?php if ( '' !== $error_message ) : ?>
<div class="notice notice-error"><p><?php echo esc_html( $error_message ); ?></p></div>
<?php endif; ?>

<section class="softone-log-section" aria-label="<?php echo esc_attr( $entries_heading ); ?>">
<p class="softone-log-section__intro"><?php echo esc_html( sprintf( __( 'Displaying up to %d recent entries containing category synchronisation activity.', 'softone-woocommerce-integration' ), $displayed_limit ) ); ?></p>

<?php if ( empty( $entries ) ) : ?>
<p><?php esc_html_e( 'No category synchronisation entries were found in the WooCommerce logs.', 'softone-woocommerce-integration' ); ?></p>
<?php else : ?>
<ul class="softone-log-list">
<?php foreach ( $entries as $entry ) :
$level         = isset( $entry['level'] ) && '' !== $entry['level'] ? (string) $entry['level'] : 'info';
$level_class   = 'softone-log-entry--' . sanitize_html_class( $level );
$level_label   = strtoupper( $level );
$timestamp     = isset( $entry['timestamp'] ) ? (int) $entry['timestamp'] : 0;
$timestamp_raw = isset( $entry['timestamp_raw'] ) ? (string) $entry['timestamp_raw'] : '';
$message       = isset( $entry['message'] ) ? (string) $entry['message'] : '';
$context       = isset( $entry['context'] ) ? (string) $entry['context'] : '';
$source        = isset( $entry['source'] ) ? (string) $entry['source'] : '';
$file_name     = isset( $entry['file'] ) ? (string) $entry['file'] : '';

$display_time = '';
if ( $timestamp > 0 ) {
$format = get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' );
if ( function_exists( 'wp_date' ) ) {
$display_time = wp_date( $format, $timestamp );
} else {
$display_time = date_i18n( $format, $timestamp );
}
} elseif ( '' !== $timestamp_raw ) {
$display_time = $timestamp_raw;
} else {
$display_time = __( 'Unknown time', 'softone-woocommerce-integration' );
}
?>
<li class="softone-log-entry <?php echo esc_attr( $level_class ); ?>">
<header class="softone-log-entry__header">
<span class="softone-log-entry__timestamp"><?php echo esc_html( $display_time ); ?></span>
<span class="softone-log-entry__level"><?php echo esc_html( $level_label ); ?></span>
</header>
<div class="softone-log-entry__message"><?php echo esc_html( $message ); ?></div>
<?php if ( '' !== $context ) : ?>
<pre class="softone-log-entry__context"><?php echo esc_html( $context ); ?></pre>
<?php endif; ?>
<footer class="softone-log-entry__meta">
<?php if ( '' !== $source ) : ?>
<span class="softone-log-entry__meta-item"><?php echo esc_html( sprintf( __( 'Source: %s', 'softone-woocommerce-integration' ), $source ) ); ?></span>
<?php endif; ?>
<?php if ( '' !== $file_name ) : ?>
<span class="softone-log-entry__meta-item"><?php echo esc_html( sprintf( __( 'File: %s', 'softone-woocommerce-integration' ), $file_name ) ); ?></span>
<?php endif; ?>
</footer>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>

<section class="softone-log-footnote" aria-label="<?php esc_attr_e( 'Log sources', 'softone-woocommerce-integration' ); ?>">
<?php if ( ! empty( $log_file_names ) ) : ?>
<p><?php echo esc_html( sprintf( __( 'Log files scanned: %s', 'softone-woocommerce-integration' ), implode( ', ', $log_file_names ) ) ); ?></p>
<?php endif; ?>
<?php if ( '' !== $log_directory ) : ?>
<p><?php echo esc_html( sprintf( __( 'WooCommerce log directory: %s', 'softone-woocommerce-integration' ), $log_directory ) ); ?></p>
<?php endif; ?>
</section>
</div>
<?php

}

        /**
         * Render the file-based synchronisation activity viewer.
         *
         * @return void
         */
        public function render_sync_activity_page() {

                if ( ! current_user_can( $this->capability ) ) {
                        return;
                }

                $entries     = array();
                $metadata    = array();
                $error_state = '';
                $limit       = 300;

                if ( $this->activity_logger && method_exists( $this->activity_logger, 'get_entries' ) ) {
                        $entries  = $this->activity_logger->get_entries( $limit );
                        $metadata = $this->activity_logger->get_metadata();
                } else {
                        $error_state = __( 'The sync activity logger is not available.', 'softone-woocommerce-integration' );
                }

                $file_path   = isset( $metadata['file_path'] ) ? (string) $metadata['file_path'] : '';
                $file_exists = ! empty( $metadata['exists'] );
                $file_size   = isset( $metadata['size'] ) ? (int) $metadata['size'] : 0;

                $file_size_display = $file_exists && function_exists( 'size_format' ) ? size_format( $file_size ) : sprintf( __( '%d bytes', 'softone-woocommerce-integration' ), $file_size );

                $cleared = isset( $_GET['cleared'] ) ? (int) $_GET['cleared'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display notice only.

?>
<div class="wrap softone-sync-activity">
<h1><?php esc_html_e( 'Sync Activity', 'softone-woocommerce-integration' ); ?></h1>
<p class="description"><?php esc_html_e( 'Review the latest product category, attribute, and menu sync operations captured by the plugin.', 'softone-woocommerce-integration' ); ?></p>

<?php if ( $cleared ) : ?>
<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The sync activity log has been cleared.', 'softone-woocommerce-integration' ); ?></p></div>
<?php endif; ?>

<?php if ( '' !== $error_state ) : ?>
<div class="notice notice-error"><p><?php echo esc_html( $error_state ); ?></p></div>
<?php endif; ?>

<section class="softone-sync-activity__meta" aria-label="<?php esc_attr_e( 'Log file details', 'softone-woocommerce-integration' ); ?>">
<p><?php echo esc_html( sprintf( __( 'Entries displayed: up to %d most recent events.', 'softone-woocommerce-integration' ), $limit ) ); ?></p>
<?php if ( '' !== $file_path ) : ?>
<p><?php echo esc_html( sprintf( __( 'Log file location: %s', 'softone-woocommerce-integration' ), $file_path ) ); ?></p>
<?php endif; ?>
<?php if ( $file_exists ) : ?>
<p><?php echo esc_html( sprintf( __( 'Log file size: %s', 'softone-woocommerce-integration' ), $file_size_display ) ); ?></p>
<?php else : ?>
<p><?php esc_html_e( 'A log file will be created automatically when new sync events occur.', 'softone-woocommerce-integration' ); ?></p>
<?php endif; ?>
</section>

<?php if ( empty( $entries ) ) : ?>
<p><?php esc_html_e( 'No sync activity has been recorded yet.', 'softone-woocommerce-integration' ); ?></p>
<?php else : ?>
<table class="widefat fixed striped softone-sync-activity__table">
<thead>
<tr>
<th scope="col"><?php esc_html_e( 'Time', 'softone-woocommerce-integration' ); ?></th>
<th scope="col"><?php esc_html_e( 'Channel', 'softone-woocommerce-integration' ); ?></th>
<th scope="col"><?php esc_html_e( 'Action', 'softone-woocommerce-integration' ); ?></th>
<th scope="col"><?php esc_html_e( 'Message', 'softone-woocommerce-integration' ); ?></th>
<th scope="col"><?php esc_html_e( 'Context', 'softone-woocommerce-integration' ); ?></th>
</tr>
</thead>
<tbody>
<?php foreach ( $entries as $entry ) :
$timestamp = isset( $entry['timestamp'] ) ? (int) $entry['timestamp'] : 0;
$channel   = isset( $entry['channel'] ) ? (string) $entry['channel'] : '';
$action    = isset( $entry['action'] ) ? (string) $entry['action'] : '';
$message   = isset( $entry['message'] ) ? (string) $entry['message'] : '';
$context   = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();

$display_time = '';
if ( $timestamp > 0 ) {
        $format = get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' );
        if ( function_exists( 'wp_date' ) ) {
                $display_time = wp_date( $format, $timestamp );
        } else {
                $display_time = date_i18n( $format, $timestamp );
        }
} else {
        $display_time = __( 'Unknown time', 'softone-woocommerce-integration' );
}

$context_output = '';
if ( ! empty( $context ) ) {
        $encoded_context = wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( false === $encoded_context ) {
                $encoded_context = json_encode( $context, JSON_PRETTY_PRINT );
        }

        if ( false !== $encoded_context ) {
                $context_output = $encoded_context;
        }
}
?>
<tr>
<td><?php echo esc_html( $display_time ); ?></td>
<td><?php echo esc_html( $channel ); ?></td>
<td><?php echo esc_html( $action ); ?></td>
<td><?php echo esc_html( $message ); ?></td>
<td><?php if ( '' !== $context_output ) : ?><pre><?php echo esc_html( $context_output ); ?></pre><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin-top:1.5em;">
<?php wp_nonce_field( $this->clear_activity_action ); ?>
<input type="hidden" name="action" value="<?php echo esc_attr( $this->clear_activity_action ); ?>" />
<?php submit_button( __( 'Delete Sync Activity Log', 'softone-woocommerce-integration' ), 'delete', 'softone_wc_integration_delete_sync_activity', false ); ?>
</form>
</div>
<?php

        }

        /**
        * Retrieve category synchronisation log entries from WooCommerce logs.
        *
        * @return array<string,mixed> {
        *     @type array $entries   Structured log entries.
        *     @type array $files     Log file paths that were scanned.
        *     @type string $directory Log directory path when available.
        *     @type string $error    Error message when logs are unavailable.
        * }
        */
       private function get_category_log_entries() {

               $result = array(
                       'entries'   => array(),
                       'files'     => array(),
                       'directory' => '',
                       'error'     => '',
               );

               if ( ! defined( 'WC_LOG_DIR' ) ) {
                       $result['error'] = __( 'WooCommerce logging is not available on this site.', 'softone-woocommerce-integration' );
                       return $result;
               }

               $log_directory          = (string) WC_LOG_DIR;
               $result['directory'] = $log_directory;

               if ( ! is_dir( $log_directory ) || ! is_readable( $log_directory ) ) {
                       $result['error'] = __( 'The WooCommerce log directory could not be accessed.', 'softone-woocommerce-integration' );
                       return $result;
               }

               $files = $this->locate_category_log_files( $log_directory );

               if ( empty( $files ) ) {
                       return $result;
               }

               $result['files'] = $files;

               $entries = array();
               $order   = 0;

               foreach ( $files as $file_path ) {
                       if ( ! is_readable( $file_path ) ) {
                               continue;
                       }

                       $lines = @file( $file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Avoid warnings when log files are not readable.

                       if ( false === $lines ) {
                               continue;
                       }

                       foreach ( $lines as $line ) {
                               if ( false === strpos( (string) $line, 'SOFTONE_CAT_SYNC' ) ) {
                                       continue;
                               }

                               $order++;
                               $entries[] = $this->parse_category_log_line( (string) $line, (string) $file_path, $order );
                       }
               }

               if ( empty( $entries ) ) {
                       return $result;
               }

               usort(
                       $entries,
                       function ( $a, $b ) {
                               if ( $a['timestamp'] === $b['timestamp'] ) {
                                       return $b['order'] <=> $a['order'];
                               }

                               return $b['timestamp'] <=> $a['timestamp'];
                       }
               );

               $result['entries'] = array_slice( $entries, 0, $this->category_log_limit );

               return $result;
       }

       /**
        * Determine the most relevant WooCommerce log files to scan for category sync entries.
        *
        * @param string $log_directory Absolute path to the WooCommerce log directory.
        *
        * @return string[] Array of absolute file paths ordered by most recent first.
        */
       private function locate_category_log_files( $log_directory ) {

               $log_directory = (string) $log_directory;
               $files         = array();
               $seen          = array();

               if ( class_exists( 'WC_Log_Handler_File' ) && method_exists( 'WC_Log_Handler_File', 'get_log_files' ) ) {
                       $log_files = WC_Log_Handler_File::get_log_files();

                       if ( is_array( $log_files ) ) {
                               foreach ( $log_files as $log_file ) {
                                       $full_path = $this->join_path( $log_directory, (string) $log_file );

                                       if ( '' === $full_path || isset( $seen[ $full_path ] ) ) {
                                               continue;
                                       }

                                       $files[]             = $full_path;
                                       $seen[ $full_path ] = true;
                               }
                       }
               }

               if ( empty( $files ) ) {
                       $pattern = $this->join_path( $log_directory, '*.log' );
                       if ( '' !== $pattern ) {
                               $matched = glob( $pattern );
                               if ( is_array( $matched ) ) {
                                       foreach ( $matched as $match ) {
                                               $match = (string) $match;
                                               if ( '' === $match || isset( $seen[ $match ] ) ) {
                                                       continue;
                                               }

                                               $files[]         = $match;
                                               $seen[ $match ] = true;
                                       }
                               }
                       }
               }

               if ( empty( $files ) ) {
                       return array();
               }

               usort(
                       $files,
                       function ( $a, $b ) {
                               $a_time = @filemtime( (string) $a );
                               $b_time = @filemtime( (string) $b );

                               if ( false === $a_time ) {
                                       $a_time = 0;
                               }

                               if ( false === $b_time ) {
                                       $b_time = 0;
                               }

                               if ( $a_time === $b_time ) {
                                       return strnatcasecmp( (string) $b, (string) $a );
                               }

                               return $b_time <=> $a_time;
                       }
               );

               return array_slice( $files, 0, 10 );
       }

       /**
        * Join path segments without relying on WordPress helper functions.
        *
        * @param string $base Base path.
        * @param string $path Additional path or glob pattern.
        *
        * @return string
        */
       private function join_path( $base, $path ) {

               $base = (string) $base;
               $path = (string) $path;

               if ( '' === $base ) {
                       return $path;
               }

               $separator = defined( 'DIRECTORY_SEPARATOR' ) ? DIRECTORY_SEPARATOR : '/';

               return rtrim( $base, '/\\' ) . $separator . ltrim( $path, '/\\' );
       }

       /**
        * Parse a WooCommerce log line into a structured array.
        *
        * @param string $line      Raw log line.
        * @param string $file_path Source file path.
        * @param int    $order     Incremental order value for stable sorting.
        *
        * @return array<string,mixed>
        */
       private function parse_category_log_line( $line, $file_path, $order ) {

               $entry = array(
                       'timestamp_raw' => '',
                       'timestamp'     => 0,
                       'level'         => '',
                       'source'        => '',
                       'message'       => trim( (string) $line ),
                       'context'       => '',
                       'file'          => basename( $file_path ),
                       'order'         => (int) $order,
               );

               if ( preg_match( '/^\[(?P<timestamp>[^\]]+)\]\s+(?P<level>[A-Z]+)\s+(?P<source>[^:]+):\s+(?P<message>.*)$/', $line, $matches ) ) {
                       $entry['timestamp_raw'] = trim( $matches['timestamp'] );
                       $entry['timestamp']     = $this->parse_log_timestamp( $entry['timestamp_raw'] );
                       $entry['level']         = strtolower( trim( $matches['level'] ) );
                       $entry['source']        = trim( $matches['source'] );
                       $entry['message']       = trim( $matches['message'] );
               }

               list( $message, $context ) = $this->split_log_context( $entry['message'] );

               $entry['message'] = $message;
               $entry['context'] = $context;

               return $entry;
       }

       /**
        * Convert a WooCommerce log timestamp into a Unix timestamp.
        *
        * @param string $timestamp_string Raw timestamp from the log.
        *
        * @return int
        */
       private function parse_log_timestamp( $timestamp_string ) {

               $timestamp_string = trim( (string) $timestamp_string );

               if ( '' === $timestamp_string ) {
                       return 0;
               }

               $normalized = str_replace( 'T', ' ', $timestamp_string );

               if ( false !== stripos( $normalized, 'UTC' ) ) {
                       $normalized = str_ireplace( 'UTC', '+00:00', $normalized );
               }

               $timestamp = strtotime( $normalized );

               if ( false === $timestamp ) {
                       $stripped = preg_replace( '/\s+[+-]\d{2}:\d{2}$/', '', $normalized );
                       if ( is_string( $stripped ) ) {
                               $timestamp = strtotime( $stripped );
                       }
               }

               if ( false === $timestamp ) {
                       return 0;
               }

               return (int) $timestamp;
       }

       /**
        * Separate the log message body from its context payload.
        *
        * @param string $message Raw log message.
        *
        * @return array{0:string,1:string}
        */
       private function split_log_context( $message ) {

               $message = (string) $message;

               $position = strpos( $message, 'Context:' );

               if ( false === $position ) {
                       return array( $message, '' );
               }

               $clean_message = trim( substr( $message, 0, $position ) );
               $context       = trim( substr( $message, $position + strlen( 'Context:' ) ) );

               return array( $clean_message, $context );
       }

       /**
        * Output the interactive API tester workspace for administrators.
        *
        * Presents the credential summary, request builder form (with preset
        * helpers), and response viewer so store managers can trial Softone API
        * calls directly from the dashboard. Availability is guarded by the
        * `$capability` property and the layout is populated using
        * {@see self::get_api_tester_result()}, {@see self::prepare_api_tester_form_data()}
        * and {@see self::get_api_tester_presets()}. The response panel formats
        * payloads with {@see self::format_api_tester_output()} and submissions
        * post back via the `$api_tester_action` hook.
        *
        * @return void
        */
        public function render_api_tester_page() {

                if ( ! current_user_can( $this->capability ) ) {
                        return;
                }

                $result    = $this->get_api_tester_result();
                $form_data = $this->prepare_api_tester_form_data( $result );
                $presets   = $this->get_api_tester_presets();

                $default_preset_description = __( 'Choose a preset to automatically populate the form fields.', 'softone-woocommerce-integration' );
                $preset_description         = $default_preset_description;

                if ( ! empty( $form_data['preset'] ) && isset( $presets[ $form_data['preset'] ] ) && ! empty( $presets[ $form_data['preset'] ]['description'] ) ) {
                        $preset_description = $presets[ $form_data['preset'] ]['description'];
                }


                $settings = get_option( Softone_API_Client::OPTION_SETTINGS_KEY, array() );

                if ( ! is_array( $settings ) ) {
                        $settings = array();
                }

                $settings_fields = array(
                        'endpoint'              => __( 'Endpoint URL', 'softone-woocommerce-integration' ),
                        'username'              => __( 'Username', 'softone-woocommerce-integration' ),
                        'password'              => __( 'Password', 'softone-woocommerce-integration' ),
                        'app_id'                => __( 'App ID', 'softone-woocommerce-integration' ),
                        'company'               => __( 'Company', 'softone-woocommerce-integration' ),
                        'branch'                => __( 'Branch', 'softone-woocommerce-integration' ),
                        'module'                => __( 'Module', 'softone-woocommerce-integration' ),
                        'refid'                 => __( 'Ref ID', 'softone-woocommerce-integration' ),
                        'default_saldoc_series' => __( 'Default SALDOC Series', 'softone-woocommerce-integration' ),
                        'warehouse'             => __( 'Default Warehouse', 'softone-woocommerce-integration' ),
                        'areas'                 => __( 'Default AREAS', 'softone-woocommerce-integration' ),
                        'socurrency'            => __( 'Default SOCURRENCY', 'softone-woocommerce-integration' ),
                        'trdcategory'           => __( 'Default TRDCATEGORY', 'softone-woocommerce-integration' ),
                );

                $settings_summary = array();
                foreach ( $settings_fields as $key => $label ) {
                        $value = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';

                        if ( '' === $value ) {
                                $value = __( 'Not set', 'softone-woocommerce-integration' );
                        } elseif ( 'password' === $key ) {
                                $value = str_repeat( '•', min( 12, max( 4, strlen( $value ) ) ) );
                        }

                        $settings_summary[ $key ] = array(
                                'label' => $label,
                                'value' => $value,
                        );
                }

                $request_heading_id  = 'softone-api-request-heading';
                $response_heading_id = 'softone-api-response-heading';
                $credentials_heading = 'softone-api-credentials-heading';

?>
<div class="wrap softone-api-tester">
        <h1><?php esc_html_e( 'Softone API Tester', 'softone-woocommerce-integration' ); ?></h1>

        <?php if ( ! empty( $result ) && ! empty( $result['message'] ) ) : ?>
                <?php
                $status  = ( isset( $result['status'] ) && 'error' === $result['status'] ) ? 'error' : 'success';
                $classes = array( 'notice', 'notice-' . $status );
                ?>
                <div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
                        <p><?php echo esc_html( $result['message'] ); ?></p>
                </div>
        <?php endif; ?>

        <div class="softone-api-layout" role="region" aria-label="<?php echo esc_attr__( 'Softone API testing tools', 'softone-woocommerce-integration' ); ?>">
                <section class="softone-api-card softone-api-card--credentials" aria-labelledby="<?php echo esc_attr( $credentials_heading ); ?>">
                        <h2 id="<?php echo esc_attr( $credentials_heading ); ?>"><?php esc_html_e( 'Current Softone Settings', 'softone-woocommerce-integration' ); ?></h2>
                        <p class="softone-api-card__intro"><?php esc_html_e( 'These values come from the main Softone settings and are provided for context. Update them from the Settings page if changes are required.', 'softone-woocommerce-integration' ); ?></p>
                        <dl class="softone-api-settings-summary">
                                <?php foreach ( $settings_summary as $key => $setting ) : ?>
                                        <div class="softone-api-settings-summary__row">
                                                <dt><?php echo esc_html( $setting['label'] ); ?></dt>
                                                <dd><?php echo esc_html( $setting['value'] ); ?></dd>
                                        </div>
                                <?php endforeach; ?>
                        </dl>
                </section>

                <section class="softone-api-card softone-api-card--request" aria-labelledby="<?php echo esc_attr( $request_heading_id ); ?>">
                        <h2 id="<?php echo esc_attr( $request_heading_id ); ?>"><?php esc_html_e( 'Build Request', 'softone-woocommerce-integration' ); ?></h2>
                        <p class="softone-api-card__intro"><?php esc_html_e( 'Configure the request payload and optionally use one of the provided presets to speed things up.', 'softone-woocommerce-integration' ); ?></p>
                        <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="softone-api-form" aria-describedby="softone_api_preset_description">
                                <?php wp_nonce_field( $this->api_tester_action ); ?>
                                <input type="hidden" name="action" value="<?php echo esc_attr( $this->api_tester_action ); ?>" />

                                <div class="softone-api-form-grid">
                                        <div class="softone-api-field" data-field="preset">
                                                <label for="softone_api_preset"><?php esc_html_e( 'Preset', 'softone-woocommerce-integration' ); ?></label>
                                                <select name="softone_preset" id="softone_api_preset">
                                                        <option value="" <?php selected( $form_data['preset'], '' ); ?>><?php esc_html_e( 'Manual selection', 'softone-woocommerce-integration' ); ?></option>
                                                        <?php foreach ( $presets as $preset_key => $preset_config ) : ?>
                                                                <option value="<?php echo esc_attr( $preset_key ); ?>" <?php selected( $form_data['preset'], $preset_key ); ?>><?php echo esc_html( $preset_config['label'] ); ?></option>
                                                        <?php endforeach; ?>
                                                </select>
                                                <p class="description" id="softone_api_preset_description" data-default-description="<?php echo esc_attr( $default_preset_description ); ?>"><?php echo esc_html( $preset_description ); ?></p>
                                        </div>

                                        <div class="softone-api-field" data-field="service_type">
                                                <label for="softone_service_type"><?php esc_html_e( 'Service', 'softone-woocommerce-integration' ); ?></label>
                                                <select name="softone_service_type" id="softone_service_type">
                                                        <option value="sql_data" <?php selected( $form_data['service_type'], 'sql_data' ); ?>><?php esc_html_e( 'SqlData', 'softone-woocommerce-integration' ); ?></option>
                                                        <option value="set_data" <?php selected( $form_data['service_type'], 'set_data' ); ?>><?php esc_html_e( 'setData', 'softone-woocommerce-integration' ); ?></option>
                                                        <option value="custom" <?php selected( $form_data['service_type'], 'custom' ); ?>><?php esc_html_e( 'Custom', 'softone-woocommerce-integration' ); ?></option>
                                                </select>
                                                <p class="description"><?php esc_html_e( 'Choose a Softone service to call.', 'softone-woocommerce-integration' ); ?></p>
                                        </div>

                                        <div class="softone-api-field" data-field="sql_name">
                                                <label for="softone_sql_name"><?php esc_html_e( 'SqlData name', 'softone-woocommerce-integration' ); ?></label>
                                                <input type="text" id="softone_sql_name" name="softone_sql_name" value="<?php echo esc_attr( $form_data['sql_name'] ); ?>" />
                                                <p class="description"><?php esc_html_e( 'Required when calling SqlData.', 'softone-woocommerce-integration' ); ?></p>
                                        </div>

                                        <div class="softone-api-field" data-field="object">
                                                <label for="softone_object"><?php esc_html_e( 'setData object', 'softone-woocommerce-integration' ); ?></label>
                                                <input type="text" id="softone_object" name="softone_object" value="<?php echo esc_attr( $form_data['object'] ); ?>" />
                                                <p class="description"><?php esc_html_e( 'Required when calling setData.', 'softone-woocommerce-integration' ); ?></p>
                                        </div>

                                        <div class="softone-api-field" data-field="custom_service">
                                                <label for="softone_custom_service"><?php esc_html_e( 'Custom service name', 'softone-woocommerce-integration' ); ?></label>
                                                <input type="text" id="softone_custom_service" name="softone_custom_service" value="<?php echo esc_attr( $form_data['custom_service'] ); ?>" />
                                                <p class="description"><?php esc_html_e( 'Specify the Softone service when using the Custom option.', 'softone-woocommerce-integration' ); ?></p>
                                        </div>

                                        <div class="softone-api-field softone-api-field--checkbox" data-field="requires_client_id">
                                                <span class="softone-api-field__label"><?php esc_html_e( 'Requires client ID', 'softone-woocommerce-integration' ); ?></span>
                                                <label for="softone_requires_client_id" class="softone-api-checkbox">
                                                        <input type="checkbox" id="softone_requires_client_id" name="softone_requires_client_id" value="1" <?php checked( $form_data['requires_client_id'] ); ?> />
                                                        <span><?php esc_html_e( 'Include the cached client ID for this request.', 'softone-woocommerce-integration' ); ?></span>
                                                </label>
                                        </div>

                                        <div class="softone-api-field softone-api-field--full" data-field="payload">
                                                <label for="softone_payload"><?php esc_html_e( 'JSON payload', 'softone-woocommerce-integration' ); ?></label>
                                                <textarea rows="10" id="softone_payload" name="softone_payload" class="softone-api-textarea"><?php echo esc_textarea( $form_data['payload'] ); ?></textarea>
                                                <p class="description"><?php esc_html_e( 'Provide additional parameters as JSON. The data will be merged with the base payload for the selected service.', 'softone-woocommerce-integration' ); ?></p>
                                        </div>
                                </div>

                                <div class="softone-api-form__actions">
                                        <?php submit_button( __( 'Send Request', 'softone-woocommerce-integration' ), 'primary', 'softone_api_send', false ); ?>
                                </div>
                        </form>
                </section>

                <section class="softone-api-card softone-api-card--response" aria-labelledby="<?php echo esc_attr( $response_heading_id ); ?>" aria-live="polite">
                        <h2 id="<?php echo esc_attr( $response_heading_id ); ?>"><?php esc_html_e( 'Latest Response', 'softone-woocommerce-integration' ); ?></h2>
                        <?php if ( ! empty( $result ) && ( isset( $result['service'] ) || isset( $result['request'] ) || isset( $result['response'] ) ) ) : ?>
                                <div class="softone-api-response__content">
                                        <?php if ( ! empty( $result['service'] ) ) : ?>
                                                <h3 class="softone-api-response__subtitle"><?php echo esc_html( sprintf( __( 'Service: %s', 'softone-woocommerce-integration' ), (string) $result['service'] ) ); ?></h3>
                                        <?php endif; ?>

                                        <?php if ( array_key_exists( 'request', $result ) ) : ?>
                                                <h4><?php esc_html_e( 'Request Payload', 'softone-woocommerce-integration' ); ?></h4>
                                                <pre class="softone-api-response__code"><?php echo esc_html( $this->format_api_tester_output( $result['request'] ) ); ?></pre>
                                        <?php endif; ?>

                                        <?php if ( array_key_exists( 'response', $result ) ) : ?>
                                                <h4><?php esc_html_e( 'Response', 'softone-woocommerce-integration' ); ?></h4>
                                                <pre class="softone-api-response__code"><?php echo esc_html( $this->format_api_tester_output( $result['response'] ) ); ?></pre>
                                        <?php endif; ?>
                                </div>
                        <?php else : ?>
                                <p class="softone-api-card__intro"><?php esc_html_e( 'Run a request to see the payload and response details here.', 'softone-woocommerce-integration' ); ?></p>
                        <?php endif; ?>
                </section>
        </div>
</div>
<?php

        }

        /**
         * Retrieve the available API tester presets.
         *
         * @return array<string,array<string,mixed>>
         */
        private function get_api_tester_presets() {

                return array(
                        'sql_get_customers'   => array(
                                'label'       => __( 'SqlData → getCustomers', 'softone-woocommerce-integration' ),
                                'description' => __( 'Fetch customer records with the getCustomers SqlData query. Update the payload to filter by email, code, or other columns.', 'softone-woocommerce-integration' ),
                                'form'        => array(
                                        'service_type'       => 'sql_data',
                                        'sql_name'           => 'getCustomers',
                                        'requires_client_id' => true,
                                        'payload'            => $this->encode_api_tester_payload(
                                                array(
                                                        'params'   => array(
                                                                'EMAIL' => 'customer@example.com',
                                                        ),
                                                        'pagesize' => 25,
                                                )
                                        ),
                                ),
                        ),
                        'sql_get_items'       => array(
                                'label'       => __( 'SqlData → getItems', 'softone-woocommerce-integration' ),
                                'description' => __( 'Retrieve product data using the getItems SqlData query. Adjust the payload parameters to target specific items or categories.', 'softone-woocommerce-integration' ),
                                'form'        => array(
                                        'service_type'       => 'sql_data',
                                        'sql_name'           => 'getItems',
                                        'requires_client_id' => true,
                                        'payload'            => $this->encode_api_tester_payload(
                                                array(
                                                        'params'   => array(
                                                                'CODE' => 'ITEM-CODE',
                                                        ),
                                                        'pagesize' => 25,
                                                )
                                        ),
                                ),
                        ),
                        'set_create_customer' => array(
                                'label'       => __( 'setData → CUSTOMER', 'softone-woocommerce-integration' ),
                                'description' => __( 'Create or update a SoftOne customer record. Replace the sample values with real customer information before sending.', 'softone-woocommerce-integration' ),
                                'form'        => array(
                                        'service_type'       => 'set_data',
                                        'object'             => 'CUSTOMER',
                                        'requires_client_id' => true,
                                        'payload'            => $this->encode_api_tester_payload(
                                                array(
                                                        'data' => array(
                                                                'CUSTOMER' => array(
                                                                        array(
                                                                                'CODE'    => 'WEB000001',
                                                                                'NAME'    => 'Customer Name',
                                                                                'EMAIL'   => 'customer@example.com',
                                                                                'PHONE01' => '+35712345678',
                                                                        ),
                                                                ),
                                                        ),
                                                )
                                        ),
                                ),
                        ),
                        'set_create_saldoc'   => array(
                                'label'       => __( 'setData → SALDOC', 'softone-woocommerce-integration' ),
                                'description' => __( 'Create a SoftOne sales document (SALDOC). Provide a valid customer (TRDR), series, and item lines before testing.', 'softone-woocommerce-integration' ),
                                'form'        => array(
                                        'service_type'       => 'set_data',
                                        'object'             => 'SALDOC',
                                        'requires_client_id' => true,
                                        'payload'            => $this->encode_api_tester_payload(
                                                array(
                                                        'data' => array(
                                                                'SALDOC' => array(
                                                                        array(
                                                                                'SERIES'   => 'A1',
                                                                                'TRDR'     => '1234',
                                                                                'TRNDATE'  => '2023-01-01',
                                                                                'COMMENTS' => 'Sample order created from the API tester.',
                                                                                'ITELINES' => array(
                                                                                        array(
                                                                                                'MTRL'  => 'ITEM-CODE',
                                                                                                'QTY1'  => 1,
                                                                                                'PRICE' => 10,
                                                                                        ),
                                                                                ),
                                                                        ),
                                                                ),
                                                        ),
                                                )
                                        ),
                                ),
                        ),
                        'auth_login'          => array(
                                'label'       => __( 'Custom → login', 'softone-woocommerce-integration' ),
                                'description' => __( 'Call the login service without using the cached client ID. Replace the placeholder credentials with real values.', 'softone-woocommerce-integration' ),
                                'form'        => array(
                                        'service_type'       => 'custom',
                                        'custom_service'     => 'login',
                                        'requires_client_id' => false,
                                        'payload'            => $this->encode_api_tester_payload(
                                                array(
                                                        'username' => 'your-softone-username',
                                                        'password' => 'your-softone-password',
                                                )
                                        ),
                                ),
                        ),
                        'auth_authenticate'   => array(
                                'label'       => __( 'Custom → authenticate', 'softone-woocommerce-integration' ),
                                'description' => __( 'Authenticate an existing login session. Provide the clientID returned from login and optionally override the company, branch, module, or refid.', 'softone-woocommerce-integration' ),
                                'form'        => array(
                                        'service_type'       => 'custom',
                                        'custom_service'     => 'authenticate',
                                        'requires_client_id' => false,
                                        'payload'            => $this->encode_api_tester_payload(
                                                array(
                                                        'clientID' => 'REPLACE-WITH-CLIENT-ID',
                                                        'company'  => '1000',
                                                        'branch'   => '1000',
                                                        'module'   => '0',
                                                        'refid'    => 'WEB',
                                                )
                                        ),
                                ),
                        ),
                );

        }

        /**
         * Prepare preset configuration for the admin script.
         *
         * @return array<string,array<string,mixed>>
         */
        private function get_api_tester_presets_for_script() {

                $presets = array();

                foreach ( $this->get_api_tester_presets() as $preset_key => $preset_config ) {
                        $presets[ $preset_key ] = array(
                                'label'       => isset( $preset_config['label'] ) ? $preset_config['label'] : '',
                                'description' => isset( $preset_config['description'] ) ? $preset_config['description'] : '',
                                'form'        => array(
                                        'service_type'       => isset( $preset_config['form']['service_type'] ) ? $preset_config['form']['service_type'] : '',
                                        'sql_name'           => isset( $preset_config['form']['sql_name'] ) ? $preset_config['form']['sql_name'] : '',
                                        'object'             => isset( $preset_config['form']['object'] ) ? $preset_config['form']['object'] : '',
                                        'custom_service'     => isset( $preset_config['form']['custom_service'] ) ? $preset_config['form']['custom_service'] : '',
                                        'requires_client_id' => isset( $preset_config['form']['requires_client_id'] ) ? (bool) $preset_config['form']['requires_client_id'] : true,
                                        'payload'            => isset( $preset_config['form']['payload'] ) ? $preset_config['form']['payload'] : '',
                                ),
                        );
                }

                return $presets;

        }

        /**
         * Encode a preset payload for display in the API tester.
         *
         * @param array $payload Payload to encode.
         *
         * @return string
         */
        private function encode_api_tester_payload( array $payload ) {

                $encoded = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

                return false === $encoded ? '' : $encoded;

        }

        /**
         * Prepare default form values for the API tester.
         *
         * @param array $result Stored tester result.
         *
         * @return array
         */
        private function prepare_api_tester_form_data( $result ) {

               $presets  = $this->get_api_tester_presets();
               $defaults = array(
                       'preset'             => '',
                       'service_type'       => 'sql_data',
                       'sql_name'           => '',
                       'object'             => '',
                       'custom_service'     => '',
                       'requires_client_id' => true,
                       'payload'            => '',
               );

               $request_preset = '';

               if ( isset( $_GET['softone_api_preset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only.
                       $request_preset = sanitize_key( wp_unslash( $_GET['softone_api_preset'] ) );
               } elseif ( isset( $result['form']['preset'] ) ) {
                       $request_preset = sanitize_key( $result['form']['preset'] );
               }

               if ( '' !== $request_preset && isset( $presets[ $request_preset ]['form'] ) && is_array( $presets[ $request_preset ]['form'] ) ) {
                       $defaults = wp_parse_args(
                               array_merge(
                                       $presets[ $request_preset ]['form'],
                                       array( 'preset' => $request_preset )
                               ),
                               $defaults
                       );
               }

               if ( isset( $result['form'] ) && is_array( $result['form'] ) ) {
                       $form = wp_parse_args( $result['form'], $defaults );
               } else {
                       $form = $defaults;
               }

               $form['preset'] = isset( $form['preset'] ) ? sanitize_key( $form['preset'] ) : '';

               if ( '' !== $form['preset'] && ! isset( $presets[ $form['preset'] ] ) ) {
                       $form['preset'] = '';
               }

               $form['service_type']       = in_array( $form['service_type'], array( 'sql_data', 'set_data', 'custom' ), true ) ? $form['service_type'] : 'sql_data';
               $form['requires_client_id'] = ! empty( $form['requires_client_id'] );
               $form['payload']            = is_string( $form['payload'] ) ? $form['payload'] : '';

               return $form;

        }

        /**
         * Format values for display in the API tester output.
         *
         * @param mixed $value Output value.
         *
         * @return string
         */
	private function format_api_tester_output( $value ) {

               if ( is_array( $value ) || is_object( $value ) ) {
                       $encoded = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

                       if ( false !== $encoded ) {
                               return $encoded;
                       }
               }

               if ( is_bool( $value ) ) {
                       return $value ? 'true' : 'false';
               }

               if ( null === $value ) {
                       return 'null';
               }

               return (string) $value;

       }

        /**
         * Handle API tester submissions.
         */
	public function handle_api_tester_request() {

               if ( ! current_user_can( $this->capability ) ) {
                       wp_die( esc_html__( 'You do not have permission to perform this action.', 'softone-woocommerce-integration' ) );
               }

               check_admin_referer( $this->api_tester_action );

               $service_type = isset( $_POST['softone_service_type'] ) ? sanitize_key( wp_unslash( $_POST['softone_service_type'] ) ) : 'sql_data';

               if ( ! in_array( $service_type, array( 'sql_data', 'set_data', 'custom' ), true ) ) {
                       $service_type = 'sql_data';
               }

               $available_presets = $this->get_api_tester_presets();
               $preset            = isset( $_POST['softone_preset'] ) ? sanitize_key( wp_unslash( $_POST['softone_preset'] ) ) : '';

               if ( '' !== $preset && ! isset( $available_presets[ $preset ] ) ) {
                       $preset = '';
               }

               $form_data = array(
                       'preset'             => $preset,
                       'service_type'       => $service_type,
                       'sql_name'           => isset( $_POST['softone_sql_name'] ) ? sanitize_text_field( wp_unslash( $_POST['softone_sql_name'] ) ) : '',
                       'object'             => isset( $_POST['softone_object'] ) ? sanitize_text_field( wp_unslash( $_POST['softone_object'] ) ) : '',
                       'custom_service'     => isset( $_POST['softone_custom_service'] ) ? sanitize_text_field( wp_unslash( $_POST['softone_custom_service'] ) ) : '',
                       'requires_client_id' => isset( $_POST['softone_requires_client_id'] ) ? true : false,
                       'payload'            => isset( $_POST['softone_payload'] ) ? wp_unslash( $_POST['softone_payload'] ) : '',
               );

               $payload_data = array();

               if ( '' !== $form_data['payload'] ) {
                       $decoded = json_decode( $form_data['payload'], true );

                       if ( JSON_ERROR_NONE !== json_last_error() ) {
                               $this->store_api_tester_result(
                                       array(
                                               'status'  => 'error',
                                               'message' => sprintf( __( '[SO-ADM-001] Invalid JSON payload: %s', 'softone-woocommerce-integration' ), json_last_error_msg() ),
                                               'service' => '',
                                               'request' => array(),
                                               'response' => null,
                                               'form'    => $form_data,
                                       )
                               );
                               wp_safe_redirect( $this->get_api_tester_page_url() );
                               exit;
                       }

                       if ( ! is_array( $decoded ) ) {
                               $this->store_api_tester_result(
                                       array(
                                               'status'  => 'error',
                                               'message' => __( '[SO-ADM-002] JSON payload must decode to an array or object.', 'softone-woocommerce-integration' ),
                                               'service' => '',
                                               'request' => array(),
                                               'response' => null,
                                               'form'    => $form_data,
                                       )
                               );
                               wp_safe_redirect( $this->get_api_tester_page_url() );
                               exit;
                       }

                       $payload_data = $decoded;
               }

               $service_name    = '';
               $request_payload = $payload_data;

               switch ( $service_type ) {
                       case 'set_data':
                               $service_name = 'setData';

                               if ( '' === $form_data['object'] ) {
                                       $this->store_api_tester_result(
                                               array(
                                                       'status'  => 'error',
                                                       'message' => __( '[SO-ADM-003] A setData object name is required.', 'softone-woocommerce-integration' ),
                                                       'service' => $service_name,
                                                       'request' => array(),
                                                       'response' => null,
                                                       'form'    => $form_data,
                                               )
                                       );
                                       wp_safe_redirect( $this->get_api_tester_page_url() );
                                       exit;
                               }

                               $request_payload['object'] = $form_data['object'];
                               break;

                       case 'custom':
                               $service_name = $form_data['custom_service'];

                               if ( '' === $service_name ) {
                                       $this->store_api_tester_result(
                                               array(
                                                       'status'  => 'error',
                                                       'message' => __( '[SO-ADM-004] A custom service name is required.', 'softone-woocommerce-integration' ),
                                                       'service' => '',
                                                       'request' => array(),
                                                       'response' => null,
                                                       'form'    => $form_data,
                                               )
                                       );
                                       wp_safe_redirect( $this->get_api_tester_page_url() );
                                       exit;
                               }
                               break;

                       case 'sql_data':
                       default:
                               $service_name = 'SqlData';

                               if ( '' === $form_data['sql_name'] ) {
                                       $this->store_api_tester_result(
                                               array(
                                                       'status'  => 'error',
                                                       'message' => __( '[SO-ADM-005] A SqlData name is required.', 'softone-woocommerce-integration' ),
                                                       'service' => $service_name,
                                                       'request' => array(),
                                                       'response' => null,
                                                       'form'    => $form_data,
                                               )
                                       );
                                       wp_safe_redirect( $this->get_api_tester_page_url() );
                                       exit;
                               }

                               $request_payload['SqlName'] = $form_data['sql_name'];
                               break;
               }

               $request_details = array(
                       'payload'            => $request_payload,
                       'requires_client_id' => $form_data['requires_client_id'],
               );

               try {
                       $client   = new Softone_API_Client();
                       $response = $client->call_service( $service_name, $request_payload, $form_data['requires_client_id'] );

                       $this->store_api_tester_result(
                               array(
                                       'status'  => 'success',
                                       'message' => sprintf( __( 'Successfully executed %s.', 'softone-woocommerce-integration' ), $service_name ),
                                       'service' => $service_name,
                                       'request' => $request_details,
                                       'response' => $response,
                                       'form'    => $form_data,
                               )
                       );
               } catch ( Softone_API_Client_Exception $exception ) {
                       $this->store_api_tester_result(
                               array(
                                       'status'  => 'error',
                                       'message' => $exception->getMessage(),
                                       'service' => $service_name,
                                       'request' => $request_details,
                                       'response' => null,
                                       'form'    => $form_data,
                               )
                       );
               } catch ( Exception $exception ) {
                       $this->store_api_tester_result(
                               array(
                                       'status'  => 'error',
                                       'message' => $exception->getMessage(),
                                       'service' => $service_name,
                                       'request' => $request_details,
                                       'response' => null,
                                       'form'    => $form_data,
                               )
                       );
               }

               wp_safe_redirect( $this->get_api_tester_page_url() );
               exit;

       }

    /**
     * Handle manual item import requests.
     */
    public function handle_item_import() {

        if ( ! current_user_can( $this->capability ) ) {
                wp_die( esc_html__( 'You do not have permission to perform this action.', 'softone-woocommerce-integration' ) );
        }

        check_admin_referer( Softone_Item_Sync::ADMIN_ACTION );

        $force_full_import        = null;
        $force_taxonomy_refresh   = false;

        if ( isset( $_POST['softone_wc_integration_force_full'] ) ) {
                $force_full_import = (bool) absint( wp_unslash( $_POST['softone_wc_integration_force_full'] ) );
        }

        if ( isset( $_POST['softone_wc_integration_force_taxonomy_refresh'] ) ) {
                $force_taxonomy_refresh = (bool) absint( wp_unslash( $_POST['softone_wc_integration_force_taxonomy_refresh'] ) );
        }

        try {
                $result = $this->item_sync->sync( $force_full_import, $force_taxonomy_refresh );

                if ( isset( $result['started_at'] ) ) {
                        update_option( Softone_Item_Sync::OPTION_LAST_RUN, (int) $result['started_at'] );
                }

                $processed = isset( $result['processed'] ) ? (int) $result['processed'] : 0;
                $created   = isset( $result['created'] ) ? (int) $result['created'] : 0;
                $updated   = isset( $result['updated'] ) ? (int) $result['updated'] : 0;
                $skipped   = isset( $result['skipped'] ) ? (int) $result['skipped'] : 0;

                $message = sprintf(
                        /* translators: 1: total processed, 2: created count, 3: updated count, 4: skipped count */
                        __( 'Item import completed successfully. Processed %1$d items (%2$d created, %3$d updated, %4$d skipped).', 'softone-woocommerce-integration' ),
                        $processed,
                        $created,
                        $updated,
                        $skipped
                );

                if ( isset( $result['stale_processed'] ) && $result['stale_processed'] > 0 ) {
                        $message .= ' ' . sprintf(
                                /* translators: %d: number of stale products handled */
                                __( 'Marked %d stale products.', 'softone-woocommerce-integration' ),
                                (int) $result['stale_processed']
                        );
                }

                if ( $force_taxonomy_refresh ) {
                        $message .= ' ' . __( 'Category and menu assignments were refreshed.', 'softone-woocommerce-integration' );
                }

                $this->store_import_notice( 'success', $message );
        } catch ( Softone_API_Client_Exception $exception ) {
                $this->store_import_notice(
                        'error',
                        sprintf(
                                /* translators: %s: error message */
                                __( '[SO-ADM-006] Item import failed: %s', 'softone-woocommerce-integration' ),
                                $exception->getMessage()
                        )
                );
        } catch ( Exception $exception ) {
                $this->store_import_notice(
                        'error',
                        sprintf(
                                /* translators: %s: error message */
                                __( '[SO-ADM-007] Item import failed: %s', 'softone-woocommerce-integration' ),
                                $exception->getMessage()
                        )
                );
        }

        wp_safe_redirect( $this->get_settings_page_url() );
        exit;

    }

/**
 * Handle connection test requests.
 */
public function handle_test_connection() {

		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'softone-woocommerce-integration' ) );
		}

		check_admin_referer( 'softone_wc_integration_test_connection' );

		$message = '';
		$type    = 'success';
		$details = array();

		try {
			$client    = new Softone_API_Client();
			$client_id = $client->get_client_id( true );

			$message = sprintf(
				/* translators: %s: SoftOne client ID */
				__( 'SoftOne connection succeeded. Client ID: %s', 'softone-woocommerce-integration' ),
				$client_id
			);

			$details = array(
				'client_id' => $client_id,
			);

			$this->log_connection_event(
				'info',
				'SoftOne connection test succeeded.',
				array(
					'data' => $details,
				)
			);
		} catch ( Softone_API_Client_Exception $exception ) {
			$type    = 'error';
			$message = sprintf(
				/* translators: %s: error message */
                                __( '[SO-ADM-008] SoftOne connection failed: %s', 'softone-woocommerce-integration' ),
				$exception->getMessage()
			);

			$details = $exception->get_context();

			$this->log_connection_event(
				'error',
				'SoftOne connection test failed.',
				array(
					'data'          => $details,
					'error_message' => $exception->getMessage(),
				)
			);
		} catch ( Exception $exception ) {
			$type    = 'error';
			$message = sprintf(
				/* translators: %s: error message */
                                __( '[SO-ADM-009] SoftOne connection failed: %s', 'softone-woocommerce-integration' ),
				$exception->getMessage()
			);

			$details = array(
				'error'     => $exception->getMessage(),
				'exception' => get_class( $exception ),
			);

			$this->log_connection_event(
				'error',
				'SoftOne connection test triggered an unexpected exception.',
				array(
					'data'          => $details,
					'error_message' => $exception->getMessage(),
				)
			);
		}

		$this->store_test_notice( $type, $message, $details );

		wp_safe_redirect( $this->get_settings_page_url() );
		exit;

	}

	/**
	 * Render a stored connection test notice when available.
	 */
	private function maybe_render_connection_notice() {

		$notice = get_transient( $this->get_test_notice_key() );

		if ( empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( $this->get_test_notice_key() );

		$type      = isset( $notice['type'] ) && 'error' === $notice['type'] ? 'error' : 'success';
		$classes   = array( 'notice', 'notice-' . $type );
		$details   = isset( $notice['details'] ) && is_array( $notice['details'] ) ? $notice['details'] : array();
		$timestamp = isset( $notice['timestamp'] ) ? (int) $notice['timestamp'] : 0;

		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';
		echo '<p>' . esc_html( $notice['message'] ) . '</p>';

		if ( 'error' === $type ) {
			echo '<p>' . esc_html__( 'Check the WooCommerce logs for detailed diagnostics.', 'softone-woocommerce-integration' ) . '</p>';
		}

		if ( $timestamp > 0 ) {
			$formatted = function_exists( 'wp_date' ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
			echo '<p><em>' . esc_html( sprintf( __( 'Test executed at %s.', 'softone-woocommerce-integration' ), $formatted ) ) . '</em></p>';
		}

		if ( ! empty( $details ) ) {
			$encoded_details = wp_json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

			if ( false !== $encoded_details ) {
				echo '<details class="softone-connection-details"><summary>' . esc_html__( 'View diagnostic details', 'softone-woocommerce-integration' ) . '</summary>';
				echo '<pre>' . esc_html( $encoded_details ) . '</pre>';
				echo '</details>';
			}
		}

		echo '</div>';

	}

	/**
	 * Store a connection test notice for the current user.
	 *
	 * @param string $type    Notice type.
	 * @param string $message Notice message.
	 * @param array  $details Additional notice details.
	 */
	private function store_test_notice( $type, $message, array $details = array() ) {

		set_transient(
			$this->get_test_notice_key(),
			array(
				'type'      => $type,
				'message'   => $message,
				'details'   => $details,
				'timestamp' => time(),
			),
			MINUTE_IN_SECONDS
		);

	}

	/**
	 * Generate the transient key for the current user.
	 *
	 * @return string
	 */
	private function get_test_notice_key() {

		$user_id = get_current_user_id();

                return $this->test_notice_transient . ( $user_id ? $user_id : 'guest' );

        }

        /**
         * Render a stored item import notice when available.
         */
        private function maybe_render_import_notice() {

                $notice = get_transient( $this->get_import_notice_key() );

                if ( empty( $notice['message'] ) ) {
                        return;
                }

                delete_transient( $this->get_import_notice_key() );

                $type    = isset( $notice['type'] ) && 'error' === $notice['type'] ? 'error' : 'success';
                $classes = array( 'notice', 'notice-' . $type );

                printf(
                        '<div class="%1$s"><p>%2$s</p></div>',
                        esc_attr( implode( ' ', $classes ) ),
                        esc_html( $notice['message'] )
                );

        }

        /**
         * Store an import notice for the current user.
         *
         * @param string $type    Notice type.
         * @param string $message Notice message.
         */
        private function store_import_notice( $type, $message ) {

                set_transient(
                        $this->get_import_notice_key(),
                        array(
                                'type'    => $type,
                                'message' => $message,
                        ),
                        MINUTE_IN_SECONDS
                );

        }

        /**
         * Generate the transient key for import notices for the current user.
         *
         * @return string
         */
        private function get_import_notice_key() {

                $user_id = get_current_user_id();

                return $this->import_notice_transient . ( $user_id ? $user_id : 'guest' );

        }

        /**
         * Store an API tester result for the current user.
         *
         * @param array $result Result data.
         */
	private function store_api_tester_result( array $result ) {

                set_transient( $this->get_api_tester_transient_key(), $result, 5 * MINUTE_IN_SECONDS );

        }

        /**
         * Retrieve the stored API tester result for the current user.
         *
         * @return array
         */
	private function get_api_tester_result() {

                $key    = $this->get_api_tester_transient_key();
                $result = get_transient( $key );

                if ( false !== $result ) {
                        delete_transient( $key );
                }

                return is_array( $result ) ? $result : array();

        }

        /**
         * Generate the transient key for the API tester store for the current user.
         *
         * @return string
         */
	private function get_api_tester_transient_key() {

                $user_id = get_current_user_id();

                return $this->api_tester_transient . ( $user_id ? $user_id : 'guest' );

        }

        /**
         * Retrieve the API tester page URL.
         *
         * @return string
         */
	private function get_api_tester_page_url() {

                return add_query_arg( array( 'page' => $this->api_tester_slug ), admin_url( 'admin.php' ) );

        }

        /**
         * Retrieve the settings page URL.
         *
         * @return string
         */
        private function get_settings_page_url() {

		return add_query_arg( array( 'page' => $this->menu_slug ), admin_url( 'admin.php' ) );

	}

	/**
	 * Register a text field with the Settings API.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Field label.
	 * @param string $type  Input type.
	 */
        private function add_text_field( $key, $label, $type = 'text' ) {

                add_settings_field(
                        'softone_wc_integration_' . $key,
                        $label,
			array( $this, 'render_text_field' ),
			'softone_wc_integration',
			'softone_wc_integration_api',
			array(
				'key'  => $key,
				'type' => $type,
			)
                );

        }

        /**
         * Register a checkbox field with the Settings API.
         *
         * @param string $key         Setting key.
         * @param string $label       Field label.
         * @param string $description Optional field description.
         */
        private function add_checkbox_field( $key, $label, $description = '' ) {

                add_settings_field(
                        'softone_wc_integration_' . $key,
                        $label,
                        array( $this, 'render_checkbox_field' ),
                        'softone_wc_integration',
                        'softone_wc_integration_stock_behaviour',
                        array(
                                'key'         => $key,
                                'description' => $description,
                        )
                );

        }

        /**
         * Render the textarea used to manage country-to-SoftOne mapping values.
         *
         * Populates a newline-separated ISO and identifier list from the saved
         * options and prints helper copy explaining the expected format. Values
         * are retrieved through {@see softone_wc_integration_get_setting()} and
         * stored under {@see Softone_API_Client::OPTION_SETTINGS_KEY}.
         *
         * @param array $args Field arguments supplied by the Settings API.
         *
         * @return void
         */
        public function render_country_mapping_field( $args ) {

                $key = isset( $args['key'] ) ? $args['key'] : '';

                if ( '' === $key ) {
                        return;
                }

                $mappings = array();

                if ( function_exists( 'softone_wc_integration_get_setting' ) ) {
                        $mappings = softone_wc_integration_get_setting( $key, array() );
                }

                if ( ! is_array( $mappings ) ) {
                        $mappings = array();
                }

                ksort( $mappings );

                $lines = array();

                foreach ( $mappings as $iso => $identifier ) {
                        if ( ! is_scalar( $iso ) || ! is_scalar( $identifier ) ) {
                                continue;
                        }

                        $lines[] = strtoupper( (string) $iso ) . ':' . (string) $identifier;
                }

                $value = implode( "\n", $lines );

                printf(
                        '<textarea id="%1$s" name="%2$s" rows="6" class="large-text code">%3$s</textarea>',
                        esc_attr( 'softone_wc_integration_' . $key ),
                        esc_attr( Softone_API_Client::OPTION_SETTINGS_KEY . '[' . $key . ']' ),
                        esc_textarea( $value )
                );

                echo '<p class="description">' . esc_html__( 'Enter one ISO code and SoftOne ID pair per line, for example GR:123.', 'softone-woocommerce-integration' ) . '</p>';
                echo '<p class="description">' . esc_html__( 'Developers can also adjust the mapping via the softone_wc_integration_country_mappings filter.', 'softone-woocommerce-integration' ) . '</p>';

        }

        /**
         * Render a single-line text input for a Softone configuration value.
         *
         * Determines the appropriate getter helper for the requested key,
         * outputs the matching input element, and keeps values grouped under
         * {@see Softone_API_Client::OPTION_SETTINGS_KEY}. Password fields adjust
         * autocomplete hints to prevent browsers from autofilling.
         *
         * @param array $args Field arguments provided when registering the field.
         *
         * @return void
         */
        public function render_text_field( $args ) {

                $key  = isset( $args['key'] ) ? $args['key'] : '';
                $type = isset( $args['type'] ) ? $args['type'] : 'text';

		if ( '' === $key ) {
			return;
		}

                $lookup = array(
                        'endpoint'              => 'softone_wc_integration_get_endpoint',
                        'username'              => 'softone_wc_integration_get_username',
                        'password'              => 'softone_wc_integration_get_password',
                        'app_id'                => 'softone_wc_integration_get_app_id',
                        'company'               => 'softone_wc_integration_get_company',
                        'branch'                => 'softone_wc_integration_get_branch',
                        'module'                => 'softone_wc_integration_get_module',
                        'refid'                 => 'softone_wc_integration_get_refid',
                        'default_saldoc_series' => 'softone_wc_integration_get_default_saldoc_series',
                        'warehouse'             => 'softone_wc_integration_get_warehouse',
                        'areas'                 => 'softone_wc_integration_get_areas',
                        'socurrency'            => 'softone_wc_integration_get_socurrency',
                        'trdcategory'           => 'softone_wc_integration_get_trdcategory',
                );

                if ( isset( $lookup[ $key ] ) && is_callable( $lookup[ $key ] ) ) {
                        $value = call_user_func( $lookup[ $key ] );
                } else {
                        $value = '';
                }

		$attributes = array(
			'type'         => $type,
			'id'           => 'softone_wc_integration_' . $key,
			'name'         => Softone_API_Client::OPTION_SETTINGS_KEY . '[' . $key . ']',
			'value'        => $value,
			'class'        => 'regular-text',
			'autocomplete' => 'off',
		);

		if ( 'password' === $type ) {
			$attributes['autocomplete'] = 'current-password';
		}

		$attribute_string = '';
		foreach ( $attributes as $attr_key => $attr_value ) {
			$attribute_string .= sprintf( ' %1$s="%2$s"', esc_attr( $attr_key ), esc_attr( $attr_value ) );
		}

                echo '<input' . $attribute_string . ' />';

        }

        /**
         * Render a labelled checkbox used for boolean Softone settings.
         *
         * Reads the stored flag via {@see softone_wc_integration_get_setting()},
         * prints a standardised checkbox bound to
         * {@see Softone_API_Client::OPTION_SETTINGS_KEY}, and optionally
         * displays descriptive help text beneath the control.
         *
         * @param array $args Field arguments passed by the Settings API.
         *
         * @return void
         */
        public function render_checkbox_field( $args ) {

                $key         = isset( $args['key'] ) ? $args['key'] : '';
                $description = isset( $args['description'] ) ? $args['description'] : '';

                if ( '' === $key ) {
                        return;
                }

                $value   = softone_wc_integration_get_setting( $key, 'no' );
                $checked = in_array( $value, array( 'yes', '1', 1, true ), true );

                printf(
                        '<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>',
                        esc_attr( 'softone_wc_integration_' . $key ),
                        esc_attr( Softone_API_Client::OPTION_SETTINGS_KEY . '[' . $key . ']' ),
                        checked( $checked, true, false ),
                        esc_html__( 'Enable', 'softone-woocommerce-integration' )
                );

                if ( '' !== $description ) {
                        printf(
                                '<p class="description">%s</p>',
                                esc_html( $description )
                        );
                }

        }

        /**
         * Sanitize a checkbox style flag.
         *
         * @param array  $settings Raw settings array.
         * @param string $key      Setting key.
         *
         * @return string
         */
        private function sanitize_checkbox_flag( array $settings, $key ) {

                if ( ! isset( $settings[ $key ] ) ) {
                        return 'no';
                }

                $value = $settings[ $key ];

                if ( ! is_scalar( $value ) ) {
                        return 'no';
                }

                $value = strtolower( trim( (string) wp_unslash( $value ) ) );

                return in_array( $value, array( '1', 'true', 'yes', 'on' ), true ) ? 'yes' : 'no';

        }

        /**
         * Sanitize the submitted country mapping configuration.
         *
         * @param mixed $value Raw submitted value.
         *
         * @return array<string,string>
         */
        private function sanitize_country_mappings( $value ) {

                if ( is_array( $value ) && array_values( $value ) !== $value ) {
                        $raw_pairs = $value;
                } else {
                        if ( is_array( $value ) ) {
                                $value = implode( "\n", $value );
                        }

                        $value = wp_unslash( (string) $value );

                        $lines    = preg_split( '/\r\n|\r|\n/', $value );
                        $raw_pairs = array();

                        foreach ( $lines as $line ) {
                                $line = trim( $line );

                                if ( '' === $line ) {
                                        continue;
                                }

                                $parts = preg_split( '/\s*[:=]\s*/', $line, 2 );

                                if ( $parts && count( $parts ) >= 2 ) {
                                        $raw_pairs[ $parts[0] ] = $parts[1];
                                }
                        }
                }

                $mappings = array();

                foreach ( $raw_pairs as $code => $identifier ) {
                        if ( ! is_scalar( $code ) || ! is_scalar( $identifier ) ) {
                                continue;
                        }

                        $normalized_code = strtoupper( sanitize_text_field( (string) $code ) );

                        if ( '' === $normalized_code ) {
                                continue;
                        }

                        $normalized_identifier = sanitize_text_field( (string) $identifier );
                        $normalized_identifier = preg_replace( '/[^0-9]/', '', $normalized_identifier );

                        if ( '' === $normalized_identifier ) {
                                continue;
                        }

                        $mappings[ $normalized_code ] = $normalized_identifier;
                }

                ksort( $mappings );

                return $mappings;

        }

        /**
         * Sanitizes the Softone password setting without stripping special characters.
         *
         * @param mixed $value Raw input value.
         *
         * @return string
        */
	private function sanitize_password_value( $value ) {

		if ( is_array( $value ) ) {
			return '';
		}

                $value = wp_unslash( $value );

                if ( is_object( $value ) && ! method_exists( $value, '__toString' ) ) {
                        return '';
                }

                return (string) $value;

        }

        /**
         * Sanitize a generic text value.
         *
         * @param string $value Raw input value.
         *
         * @return string
	 */
	private function sanitize_text_value( $value ) {

		if ( is_array( $value ) ) {
			$value = '';
		} else {
			$value = wp_unslash( $value );
		}

		if ( is_string( $value ) ) {
			$value = trim( $value );
		} elseif ( is_numeric( $value ) ) {
			$value = trim( (string) $value );
		} else {
			$value = trim( (string) $value );
		}

		return trim( sanitize_text_field( $value ) );

	}

	/**
	 * Record the outcome of a SoftOne connection test in the WooCommerce logs when available.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	private function log_connection_event( $level, $message, array $context = array() ) {

		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();

		if ( ! $logger || ! method_exists( $logger, 'log' ) ) {
			return;
		}

		$log_context = array_merge(
			array( 'source' => 'softone-woocommerce-integration' ),
			$context
		);

		if ( isset( $log_context['data'] ) && ! is_string( $log_context['data'] ) ) {
			$encoded_data = wp_json_encode( $log_context['data'] );

			if ( false !== $encoded_data ) {
				$log_context['data'] = $encoded_data;
			}
		}

		$logger->log( $level, $message, $log_context );

	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/softone-woocommerce-integration-admin.css', array(), $this->version, 'all' );

	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/softone-woocommerce-integration-admin.js', array( 'jquery' ), $this->version, false );

                wp_localize_script(
                        $this->plugin_name,
                        'softoneApiTester',
                        array(
                                'presets'            => $this->get_api_tester_presets_for_script(),
                                'defaultDescription' => __( 'Choose a preset to automatically populate the form fields.', 'softone-woocommerce-integration' ),
                        )
                );

	}

}

