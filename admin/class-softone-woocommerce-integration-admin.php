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
         * Variable product log submenu slug.
         *
         * @var string
         */
        private $variable_product_logs_slug = 'softone-woocommerce-integration-variable-product-logs';

        /**
         * Sync activity viewer submenu slug.
         *
         * @var string
         */
private $sync_activity_slug = 'softone-woocommerce-integration-sync-activity';

/**
 * Order export log submenu slug.
 *
 * @var string
 */
private $order_export_logs_slug = 'softone-woocommerce-integration-order-export-logs';

/**
 * Process trace viewer submenu slug.
 *
 * @var string
 */
private $process_trace_slug = 'softone-woocommerce-integration-process-trace';

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
 * AJAX action used to generate process trace output.
 *
 * @var string
 */
private $process_trace_action = 'softone_wc_integration_process_trace';

/**
 * Action name for deleting the Main Menu navigation menu.
 *
 * @var string
 */
private $delete_main_menu_action = 'softone_wc_integration_delete_main_menu';

/**
 * AJAX action name for batched Main Menu deletion.
 *
 * @var string
 */
private $delete_main_menu_ajax_action = 'softone_wc_integration_delete_main_menu_batch';

/**
 * Name of the primary navigation menu managed by the plugin.
 *
 * @var string
 */
private $main_menu_name = '';

/**
 * Base transient key for storing batched menu deletion state.
 *
 * @var string
 */
private $menu_delete_state_transient = 'softone_wc_integration_menu_delete_state_';

/**
 * Default number of menu items to delete per AJAX batch.
 *
 * @var int
 */
private $menu_delete_default_batch_size = 20;

/**
 * Lifetime (in seconds) for the menu deletion state transient.
 *
 * @var int
 */
private $menu_delete_state_lifetime = HOUR_IN_SECONDS;

/**
 * AJAX action used to batch item imports.
 *
 * @var string
 */
private $item_import_ajax_action = 'softone_wc_integration_item_import';

/**
 * Base transient key for storing item import state.
 *
 * @var string
 */
private $item_import_state_transient = 'softone_wc_integration_item_import_state_';

/**
 * Lifetime (in seconds) for item import state transients.
 *
 * @var int
 */
private $item_import_state_lifetime = HOUR_IN_SECONDS;

/**
 * Default batch size for item import processing.
 *
 * @var int
 */
private $item_import_default_batch_size = 25;

        /**
         * Base transient key for connection test notices.
         *
         * @var string
         */
        private $test_notice_transient = 'softone_wc_integration_test_notice_';

        /**
         * AJAX action used to stream sync activity updates.
         *
         * @var string
         */
        private $sync_activity_action = 'softone_wc_integration_sync_activity';

        /**
         * Default number of sync activity entries to display/fetch.
         *
         * @var int
         */
private $sync_activity_limit = 200;

/**
 * Maximum number of order export log entries to display.
 *
 * @var int
 */
private $order_export_log_limit = 200;

        /**
         * Polling interval (in milliseconds) for the sync activity monitor.
         *
         * @var int
         */
        private $sync_activity_poll_interval = 15000;

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
         * Base transient key for menu management notices.
         *
         * @var string
         */
        private $menu_notice_transient = 'softone_wc_integration_menu_notice_';

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
 * Logger used for order export diagnostics.
 *
 * @var Softone_Sync_Activity_Logger|null
 */
private $order_export_logger;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param string $plugin_name The name of this plugin.
	 * @param string $version     The version of this plugin.
	 */
public function __construct( $plugin_name, $version, Softone_Item_Sync $item_sync, ?Softone_Sync_Activity_Logger $activity_logger = null, ?Softone_Sync_Activity_Logger $order_export_logger = null ) {

$this->plugin_name = $plugin_name;
$this->version     = $version;
$this->item_sync   = $item_sync;
$this->activity_logger = $activity_logger ?: new Softone_Sync_Activity_Logger();
$this->order_export_logger = $order_export_logger ?: new Softone_Sync_Activity_Logger( 'softone-order-export.log' );

                if ( function_exists( 'softone_wc_integration_get_main_menu_name' ) ) {
                        $this->main_menu_name = softone_wc_integration_get_main_menu_name();
                } else {
                        $this->main_menu_name = 'Main Menu';
                }

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
                        __( 'Variable Product Logs', 'softone-woocommerce-integration' ),
                        __( 'Variable Product Logs', 'softone-woocommerce-integration' ),
                        $this->capability,
                        $this->variable_product_logs_slug,
                        array( $this, 'render_variable_product_logs_page' )
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
__( 'Order Export Logs', 'softone-woocommerce-integration' ),
__( 'Order Export Logs', 'softone-woocommerce-integration' ),
$this->capability,
$this->order_export_logs_slug,
array( $this, 'render_order_export_logs_page' )
);

                add_submenu_page(
                        $this->menu_slug,
                        __( 'Process Trace', 'softone-woocommerce-integration' ),
                        __( 'Process Trace', 'softone-woocommerce-integration' ),
                        $this->capability,
                        $this->process_trace_slug,
                        array( $this, 'render_process_trace_page' )
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
                        __( 'Variable Product Logs', 'softone-woocommerce-integration' ),
                        __( 'Variable Product Logs', 'softone-woocommerce-integration' ),
                        $this->capability,
                        $this->variable_product_logs_slug,
                        array( $this, 'render_variable_product_logs_page' )
                );

add_submenu_page(
'woocommerce',
__( 'Sync Activity', 'softone-woocommerce-integration' ),
__( 'Sync Activity', 'softone-woocommerce-integration' ),
$this->capability,
$this->sync_activity_slug,
array( $this, 'render_sync_activity_page' )
);

add_submenu_page(
'woocommerce',
__( 'Order Export Logs', 'softone-woocommerce-integration' ),
__( 'Order Export Logs', 'softone-woocommerce-integration' ),
$this->capability,
$this->order_export_logs_slug,
array( $this, 'render_order_export_logs_page' )
);

                add_submenu_page(
                        'woocommerce',
                        __( 'Process Trace', 'softone-woocommerce-integration' ),
                        __( 'Process Trace', 'softone-woocommerce-integration' ),
                        $this->capability,
                        $this->process_trace_slug,
                        array( $this, 'render_process_trace_page' )
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
                        'enable_variable_product_handling',
                        __( 'Enable variable product handling', 'softone-woocommerce-integration' ),
                        __( 'Allow the importer to create colour-based WooCommerce variations when related Softone items are detected.', 'softone-woocommerce-integration' )
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

                $zero_stock_fallback       = $this->sanitize_checkbox_flag( $settings, 'zero_stock_quantity_fallback' );
                $backorder_out_stock       = $this->sanitize_checkbox_flag( $settings, 'backorder_out_of_stock_products' );
                $variable_product_handling = $this->sanitize_checkbox_flag( $settings, 'enable_variable_product_handling' );

                if ( 'yes' === $zero_stock_fallback && 'yes' === $backorder_out_stock ) {
                        $backorder_out_stock = 'no';

                        add_settings_error(
                                'softone_wc_integration',
                                'softone_wc_integration_stock_mode_conflict',
                                __( 'Please select only one stock behaviour option at a time.', 'softone-woocommerce-integration' ),
                                'error'
                        );
                }

                $sanitized['zero_stock_quantity_fallback']     = $zero_stock_fallback;
                $sanitized['backorder_out_of_stock_products']  = $backorder_out_stock;
                $sanitized['enable_variable_product_handling'] = $variable_product_handling;

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
<?php $this->maybe_render_menu_notice(); ?>
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

<?php
$last_run         = get_option( Softone_Item_Sync::OPTION_LAST_RUN );
$last_run_message = $this->format_item_import_last_run_message( $last_run );
?>
<div class="softone-item-import" id="softone-item-import-panel" data-softone-item-import="1" style="margin-top: 1.5em;">
<?php if ( '' !== $last_run_message ) : ?>
        <p class="description" id="softone-item-import-last-run"><?php echo esc_html( $last_run_message ); ?></p>
<?php else : ?>
        <p class="description" id="softone-item-import-last-run" hidden></p>
<?php endif; ?>
        <p>
                <button type="button" class="button button-secondary softone-item-import__trigger" data-softone-import-trigger="run" data-softone-import-force-full="0" data-softone-import-refresh-taxonomy="0">
<?php esc_html_e( 'Run Item Import', 'softone-woocommerce-integration' ); ?>
                </button>
        </p>
        <p class="description"><?php esc_html_e( 'Run a standard import to fetch the latest Softone catalogue updates.', 'softone-woocommerce-integration' ); ?></p>
        <p>
                <button type="button" class="button button-secondary softone-item-import__trigger" data-softone-import-trigger="resync" data-softone-import-force-full="1" data-softone-import-refresh-taxonomy="1">
<?php esc_html_e( 'Re-sync Categories & Menus', 'softone-woocommerce-integration' ); ?>
                </button>
        </p>
        <p class="description"><?php esc_html_e( 'Force a full item import and refresh category and menu assignments for every product.', 'softone-woocommerce-integration' ); ?></p>
        <div class="softone-progress softone-delete-menu-progress" id="softone-item-import-progress" hidden>
                <div class="softone-progress__bar softone-delete-menu-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                        <div class="softone-progress__bar-fill softone-delete-menu-progress__bar-fill"></div>
                </div>
                <p class="softone-progress__text softone-delete-menu-progress__text" id="softone-item-import-progress-text"></p>
        </div>
        <div class="notice softone-item-import-status" id="softone-item-import-status" hidden></div>
</div>

<form
id="softone-delete-main-menu-form"
class="softone-delete-menu-form"
action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
method="post"
style="margin-top: 1.5em;"
data-softone-menu-delete="1"
>
<?php wp_nonce_field( $this->delete_main_menu_action ); ?>
<input type="hidden" name="action" value="<?php echo esc_attr( $this->delete_main_menu_action ); ?>" />
<p class="description"><?php printf( esc_html__( 'Permanently delete the WordPress navigation menu named "%s". This cannot be undone.', 'softone-woocommerce-integration' ), esc_html( $this->main_menu_name ) ); ?></p>
<?php submit_button( __( 'Delete Main Menu', 'softone-woocommerce-integration' ), 'delete', 'softone_wc_integration_delete_main_menu', false ); ?>
<div class="softone-delete-menu-progress" id="softone-delete-main-menu-progress" hidden>
<div class="softone-delete-menu-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
<div class="softone-delete-menu-progress__bar-fill"></div>
</div>
<p class="softone-delete-menu-progress__text" id="softone-delete-main-menu-progress-text"></p>
</div>
<div class="softone-delete-menu-status notice" id="softone-delete-main-menu-status" hidden></div>
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
         * Handle requests to delete the "Main Menu" navigation menu.
         */
        public function handle_delete_main_menu() {

                if ( ! current_user_can( $this->capability ) ) {
                        wp_die( esc_html__( 'You do not have permission to manage this plugin.', 'softone-woocommerce-integration' ) );
                }

                check_admin_referer( $this->delete_main_menu_action );

$menu_name = $this->main_menu_name;

$result = $this->delete_nav_menu_by_name( $menu_name );

                if ( is_wp_error( $result ) ) {
                        $this->store_menu_notice( 'error', $result->get_error_message() );
                } else {
                        $this->store_menu_notice(
                                'success',
                                sprintf(
                                        /* translators: %s: menu name */
                                        __( 'Successfully deleted the %s menu.', 'softone-woocommerce-integration' ),
                                        $menu_name
                                )
                        );
                }

                wp_safe_redirect( $this->get_settings_page_url() );
                exit;

        }

        /**
         * Retrieve the AJAX action name used for sync activity polling.
         *
         * @return string
         */
        public function get_sync_activity_action() {
                return $this->sync_activity_action;
        }

        /**
         * Retrieve the action name used to delete the Main Menu.
         *
         * @return string
         */
        public function get_delete_main_menu_action() {
                return $this->delete_main_menu_action;
        }

        /**
         * Retrieve the AJAX action name used to batch-delete the Main Menu.
         *
         * @return string
         */
        public function get_delete_main_menu_ajax_action() {
                return $this->delete_main_menu_ajax_action;
        }

/**
 * Retrieve the AJAX action name used to batch item imports.
 *
 * @return string
 */
public function get_item_import_ajax_action() {
return $this->item_import_ajax_action;
}

/**
 * Retrieve the AJAX action name used for process trace generation.
 *
 * @return string
 */
public function get_process_trace_action() {
return $this->process_trace_action;
}

        /**
         * Handle capability-protected AJAX requests for sync activity updates.
         *
         * Expects a valid nonce (under the `nonce` key), an optional `since`
         * Unix timestamp to support incremental updates, and an optional
         * `limit` indicating how many records to return. Responses follow the
         * standard WordPress JSON structure (success flag + data payload).
         *
         * @return void
         */
public function handle_sync_activity_ajax() {

if ( ! current_user_can( $this->capability ) ) {
wp_send_json_error(
array(
                                        'message' => __( 'You do not have permission to access sync activity.', 'softone-woocommerce-integration' ),
                                ),
                                403
                        );
                }

                check_ajax_referer( $this->sync_activity_action, 'nonce' );

                $since = 0;

                if ( isset( $_REQUEST['since'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
                        $since = (int) wp_unslash( $_REQUEST['since'] ); // phpcs:ignore WordPress.Security.NonceVerification
                }

                $limit = $this->sync_activity_limit;

                if ( isset( $_REQUEST['limit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
                        $requested_limit = (int) wp_unslash( $_REQUEST['limit'] ); // phpcs:ignore WordPress.Security.NonceVerification

                        if ( $requested_limit > 0 ) {
                                $limit = min( $requested_limit, 500 );
                        }
                }

                if ( ! $this->activity_logger ) {
                        wp_send_json_error(
                                array(
                                        'message' => __( 'The sync activity logger is not available.', 'softone-woocommerce-integration' ),
                                ),
                                500
                        );
                }

                $entries  = array();
                $metadata = array();

                if ( method_exists( $this->activity_logger, 'get_entries_since' ) ) {
                        $entries = $this->activity_logger->get_entries_since( $since, $limit );
                } elseif ( method_exists( $this->activity_logger, 'get_entries' ) ) {
                        $entries = $this->activity_logger->get_entries( $limit );
                }

                if ( method_exists( $this->activity_logger, 'get_metadata' ) ) {
                        $metadata = $this->activity_logger->get_metadata();
                }

                $prepared_entries = $this->prepare_activity_entries( $entries );
                $latest_timestamp = $this->get_latest_timestamp_from_entries( $prepared_entries );
                $metadata         = $this->enrich_activity_metadata( $metadata );

                wp_send_json_success(
                        array(
                                'entries'          => $prepared_entries,
                                'latestTimestamp'  => $latest_timestamp,
                                'metadata'         => $metadata,
)
);

}

        /**
         * Handle AJAX requests that generate detailed process traces.
         *
         * @return void
         */
        public function handle_process_trace_ajax() {

                if ( ! current_user_can( $this->capability ) ) {
                        wp_send_json_error(
                                array(
                                        'message' => __( 'You do not have permission to run a process trace.', 'softone-woocommerce-integration' ),
                                ),
                                403
                        );
                }

                check_ajax_referer( $this->process_trace_action, 'nonce' );

                if ( function_exists( 'set_time_limit' ) ) {
                        @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                }

                $force_full_import = false;
                if ( isset( $_POST['force_full_import'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
                        $force_full_import = $this->normalize_process_trace_flag( wp_unslash( $_POST['force_full_import'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
                }

                $force_taxonomy_refresh = false;
                if ( isset( $_POST['force_taxonomy_refresh'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
                        $force_taxonomy_refresh = $this->normalize_process_trace_flag( wp_unslash( $_POST['force_taxonomy_refresh'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
                }

                $trace           = new Softone_Process_Trace();
                $stream_logger   = new Softone_Process_Trace_Stream_Logger( $trace );
                $activity_logger = new Softone_Process_Trace_Activity_Logger( $trace );
                $api_client      = new Softone_Process_Trace_Api_Client( $trace, array(), $stream_logger );
                $item_sync       = new Softone_Item_Sync( $api_client, $stream_logger, null, $activity_logger );

                $trace->add_event(
                        'note',
                        'trace_started',
                        __( 'Starting Softone process trace.', 'softone-woocommerce-integration' ),
                        array(
                                'force_full_import'      => $force_full_import,
                                'force_taxonomy_refresh' => $force_taxonomy_refresh,
                        )
                );

                $started_at = time();

                try {
                        $result      = $item_sync->sync( $force_full_import, $force_taxonomy_refresh );
                        $finished_at = time();

                        $summary = $this->prepare_trace_summary( $result, $started_at, $finished_at, true );

                        $trace->add_event(
                                'note',
                                'trace_completed',
                                __( 'Softone process trace completed successfully.', 'softone-woocommerce-integration' ),
                                $summary
                        );

                        $prepared_entries = $this->prepare_trace_entries_for_response( $trace->get_entries() );
                        $latest_timestamp = $this->get_latest_timestamp_from_entries( $prepared_entries );

                        wp_send_json_success(
                                array(
                                        'entries'          => $prepared_entries,
                                        'summary'          => $summary,
                                        'latestTimestamp'  => $latest_timestamp,
                                )
                        );
                } catch ( Exception $exception ) {
                        $finished_at = time();

                        $trace->add_event(
                                'note',
                                'trace_failed',
                                __( 'Softone process trace failed.', 'softone-woocommerce-integration' ),
                                array( 'message' => $exception->getMessage() ),
                                'error'
                        );

                        $prepared_entries = $this->prepare_trace_entries_for_response( $trace->get_entries() );
                        $latest_timestamp = $this->get_latest_timestamp_from_entries( $prepared_entries );
                        $summary          = $this->prepare_trace_summary( array(), $started_at, $finished_at, false );

                        wp_send_json_error(
                                array(
                                        'message'         => $exception->getMessage(),
                                        'entries'         => $prepared_entries,
                                        'summary'         => $summary,
                                        'latestTimestamp' => $latest_timestamp,
                                ),
                                500
                        );
                }
        }

        /**
         * Handle AJAX requests for batched item imports.
         */
        public function handle_item_import_ajax() {

                if ( ! current_user_can( $this->capability ) ) {
                        wp_send_json_error(
                                array(
                                        'message' => __( 'You do not have permission to run item imports.', 'softone-woocommerce-integration' ),
                                ),
                                403
                        );
                }

                check_ajax_referer( $this->item_import_ajax_action, 'nonce' );

                $step = 'init';

                if ( isset( $_REQUEST['step'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
                        $step = sanitize_key( wp_unslash( $_REQUEST['step'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
                }

                switch ( $step ) {
                        case 'batch':
                                $this->process_item_import_batch_ajax();
                                break;
                        case 'init':
                                $this->process_item_import_init_ajax();
                                break;
                        default:
                                wp_send_json_error(
                                        array(
                                                'message' => __( 'Invalid item import request.', 'softone-woocommerce-integration' ),
                                        ),
                                        400
                                );
                }
        }

        /**
         * Initialise a batched item import request.
         */
        private function process_item_import_init_ajax() {

                $force_full_import = null;
                if ( isset( $_REQUEST['force_full_import'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
                        $force_full_import = (bool) absint( wp_unslash( $_REQUEST['force_full_import'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
                }

                $force_taxonomy_refresh = false;
                if ( isset( $_REQUEST['force_taxonomy_refresh'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
                        $force_taxonomy_refresh = (bool) absint( wp_unslash( $_REQUEST['force_taxonomy_refresh'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
                }

                try {
                        $state              = $this->item_sync->begin_async_import( $force_full_import, $force_taxonomy_refresh );
                        $state['user_id']   = get_current_user_id();
                        list( $process_id, $state ) = $this->create_item_import_state( $state );

                        wp_send_json_success(
                                array(
                                        'process_id'              => $process_id,
                                        'processed_items'         => (int) $state['stats']['processed'],
                                        'created_items'           => (int) $state['stats']['created'],
                                        'updated_items'           => (int) $state['stats']['updated'],
                                        'skipped_items'           => (int) $state['stats']['skipped'],
                                        'total_items'             => isset( $state['total_rows'] ) ? $state['total_rows'] : null,
                                        'force_taxonomy_refresh'  => (bool) $state['force_taxonomy_refresh'],
                                        'message'                 => __( 'Preparing item import…', 'softone-woocommerce-integration' ),
                                        'complete'                => false,
                                )
                        );
                } catch ( Softone_API_Client_Exception $exception ) {
                        $message = sprintf( __( '[SO-ADM-006] Item import failed: %s', 'softone-woocommerce-integration' ), $exception->getMessage() );
                        $this->store_import_notice( 'error', $message );
                        wp_send_json_error( array( 'message' => $message ), 500 );
                } catch ( Exception $exception ) {
                        $message = sprintf( __( '[SO-ADM-007] Item import failed: %s', 'softone-woocommerce-integration' ), $exception->getMessage() );
                        $this->store_import_notice( 'error', $message );
                        wp_send_json_error( array( 'message' => $message ), 500 );
                }
        }

        /**
         * Process the next batch for an active item import session.
         */
        private function process_item_import_batch_ajax() {

                if ( ! isset( $_REQUEST['process_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
                        wp_send_json_error(
                                array(
                                        'message' => __( 'Missing item import session identifier.', 'softone-woocommerce-integration' ),
                                ),
                                400
                        );
                }

                $process_id = sanitize_text_field( wp_unslash( $_REQUEST['process_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification

                if ( '' === $process_id ) {
                        wp_send_json_error(
                                array(
                                        'message' => __( 'Invalid item import session.', 'softone-woocommerce-integration' ),
                                ),
                                400
                        );
                }

                $state = $this->get_item_import_state( $process_id );
                if ( is_wp_error( $state ) ) {
                        wp_send_json_error(
                                array(
                                        'message' => $state->get_error_message(),
                                ),
                                400
                        );
                }

                $current_user_id = get_current_user_id();
                if ( isset( $state['user_id'] ) && (int) $state['user_id'] !== $current_user_id ) {
                        wp_send_json_error(
                                array(
                                        'message' => __( 'This item import session belongs to a different user.', 'softone-woocommerce-integration' ),
                                ),
                                403
                        );
                }

                $batch_size = $this->item_import_default_batch_size;
                if ( isset( $_REQUEST['batch_size'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
                        $batch_size = (int) wp_unslash( $_REQUEST['batch_size'] ); // phpcs:ignore WordPress.Security.NonceVerification
                }
                $batch_size = $this->normalize_item_import_batch_size( $batch_size );

                try {
                        $result       = $this->item_sync->run_async_import_batch( $state, $batch_size );
                        $updated_state = $result['state'];
                        $updated_state['user_id'] = $current_user_id;
                } catch ( Softone_API_Client_Exception $exception ) {
                        $this->delete_item_import_state( $process_id );
                        $message = sprintf( __( '[SO-ADM-006] Item import failed: %s', 'softone-woocommerce-integration' ), $exception->getMessage() );
                        $this->store_import_notice( 'error', $message );
                        wp_send_json_error( array( 'message' => $message ), 500 );
                } catch ( Exception $exception ) {
                        $this->delete_item_import_state( $process_id );
                        $message = sprintf( __( '[SO-ADM-007] Item import failed: %s', 'softone-woocommerce-integration' ), $exception->getMessage() );
                        $this->store_import_notice( 'error', $message );
                        wp_send_json_error( array( 'message' => $message ), 500 );
                }

                $stats    = isset( $result['stats'] ) && is_array( $result['stats'] ) ? $result['stats'] : array();
                $warnings = isset( $result['warnings'] ) && is_array( $result['warnings'] ) ? $result['warnings'] : array();
                $total    = isset( $result['total_rows'] ) ? $result['total_rows'] : ( isset( $updated_state['total_rows'] ) ? $updated_state['total_rows'] : null );

                if ( $result['complete'] ) {
                        $this->delete_item_import_state( $process_id );

                        if ( isset( $updated_state['started_at'] ) ) {
                                update_option( Softone_Item_Sync::OPTION_LAST_RUN, (int) $updated_state['started_at'] );
                        }

                        $stale_processed = isset( $result['stale_processed'] ) ? (int) $result['stale_processed'] : 0;
                        $summary_message = $this->build_item_import_summary_message( $stats, ! empty( $updated_state['force_taxonomy_refresh'] ), $stale_processed );

                        $this->store_import_notice( 'success', $summary_message );

                        $response = array(
                                'process_id'      => $process_id,
                                'complete'        => true,
                                'processed_items' => isset( $stats['processed'] ) ? (int) $stats['processed'] : 0,
                                'created_items'   => isset( $stats['created'] ) ? (int) $stats['created'] : 0,
                                'updated_items'   => isset( $stats['updated'] ) ? (int) $stats['updated'] : 0,
                                'skipped_items'   => isset( $stats['skipped'] ) ? (int) $stats['skipped'] : 0,
                                'total_items'     => $total,
                                'stale_processed' => $stale_processed,
                                'message'         => $summary_message,
                                'summary_message' => $summary_message,
                                'notice_type'     => 'success',
                                'warnings'        => $warnings,
                        );

                        if ( isset( $updated_state['started_at'] ) ) {
                                $response['last_run']           = (int) $updated_state['started_at'];
                                $response['last_run_formatted'] = $this->format_item_import_last_run_message( (int) $updated_state['started_at'] );
                        }

                        wp_send_json_success( $response );
                }

                $this->update_item_import_state( $process_id, $updated_state );

                $processed = isset( $stats['processed'] ) ? (int) $stats['processed'] : 0;
                $message   = $this->build_item_import_progress_message( $processed, $total );

                $response = array(
                        'process_id'      => $process_id,
                        'complete'        => false,
                        'processed_items' => $processed,
                        'created_items'   => isset( $stats['created'] ) ? (int) $stats['created'] : 0,
                        'updated_items'   => isset( $stats['updated'] ) ? (int) $stats['updated'] : 0,
                        'skipped_items'   => isset( $stats['skipped'] ) ? (int) $stats['skipped'] : 0,
                        'batch_processed' => isset( $result['batch']['processed'] ) ? (int) $result['batch']['processed'] : 0,
                        'batch_created'   => isset( $result['batch']['created'] ) ? (int) $result['batch']['created'] : 0,
                        'batch_updated'   => isset( $result['batch']['updated'] ) ? (int) $result['batch']['updated'] : 0,
                        'batch_skipped'   => isset( $result['batch']['skipped'] ) ? (int) $result['batch']['skipped'] : 0,
                        'total_items'     => $total,
                        'message'         => $message,
                        'notice_type'     => empty( $warnings ) ? 'info' : 'warning',
                        'warnings'        => $warnings,
                );

                wp_send_json_success( $response );
        }

        /**
         * Create a new transient-backed item import state payload.
         *
         * @param array<string,mixed> $state Initial state.
         * @return array{0:string,1:array<string,mixed>} Process identifier and persisted state.
         */
        private function create_item_import_state( array $state ) {

                $process_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( 'softone_import', true ) );
                $state['process_id'] = $process_id;
                $state['created_at'] = time();

                $this->update_item_import_state( $process_id, $state );

                return array( $process_id, $state );
        }

        /**
         * Retrieve the persisted state for a batched item import.
         *
         * @param string $process_id Process identifier.
         * @return array<string,mixed>|WP_Error
         */
        private function get_item_import_state( $process_id ) {

                $state = get_transient( $this->get_item_import_state_key( $process_id ) );

                if ( false === $state || ! is_array( $state ) ) {
                        return new WP_Error( 'softone_item_import_state_missing', __( 'The item import session has expired or could not be found.', 'softone-woocommerce-integration' ) );
                }

                return $state;
        }

        /**
         * Persist the updated item import state.
         *
         * @param string               $process_id Process identifier.
         * @param array<string,mixed>  $state      State payload.
         * @return void
         */
        private function update_item_import_state( $process_id, array $state ) {

                set_transient( $this->get_item_import_state_key( $process_id ), $state, $this->item_import_state_lifetime );
        }

        /**
         * Remove a persisted item import state payload.
         *
         * @param string $process_id Process identifier.
         * @return void
         */
        private function delete_item_import_state( $process_id ) {
                delete_transient( $this->get_item_import_state_key( $process_id ) );
        }

        /**
         * Build the transient key for storing item import state.
         *
         * @param string $process_id Process identifier.
         * @return string
         */
        private function get_item_import_state_key( $process_id ) {
                return $this->item_import_state_transient . sanitize_key( $process_id );
        }

        /**
         * Normalise the requested batch size for item imports.
         *
         * @param int $requested_size Requested batch size.
         * @return int
         */
        private function normalize_item_import_batch_size( $requested_size ) {

                $size = (int) $requested_size;
                if ( $size <= 0 ) {
                        $size = $this->item_import_default_batch_size;
                }

                $max_size = (int) apply_filters( 'softone_wc_integration_item_import_max_batch_size', 250 );
                if ( $max_size > 0 ) {
                        $size = min( $size, $max_size );
                }

                return max( 1, $size );
        }

        /**
         * Build a human-readable progress message for an in-flight item import.
         *
         * @param int      $processed Total processed items.
         * @param int|null $total     Total items expected, when known.
         * @return string
         */
        private function build_item_import_progress_message( $processed, $total ) {

                $processed = max( 0, (int) $processed );
                $total     = is_numeric( $total ) ? (int) $total : null;

                if ( null !== $total && $total > 0 ) {
                        return sprintf(
                                /* translators: 1: processed items, 2: total items */
                                __( 'Processed %1$d of %2$d items…', 'softone-woocommerce-integration' ),
                                $processed,
                                $total
                        );
                }

                return sprintf(
                        /* translators: %d: processed items */
                        __( 'Processed %d items…', 'softone-woocommerce-integration' ),
                        $processed
                );
        }

        /**
         * Build the completion summary message for an item import.
         *
         * @param array<string,int> $stats                   Aggregated statistics.
         * @param bool              $force_taxonomy_refresh Whether taxonomy refresh was forced.
         * @param int               $stale_processed        Number of stale products handled.
         * @return string
         */
        private function build_item_import_summary_message( array $stats, $force_taxonomy_refresh, $stale_processed ) {

                $processed = isset( $stats['processed'] ) ? (int) $stats['processed'] : 0;
                $created   = isset( $stats['created'] ) ? (int) $stats['created'] : 0;
                $updated   = isset( $stats['updated'] ) ? (int) $stats['updated'] : 0;
                $skipped   = isset( $stats['skipped'] ) ? (int) $stats['skipped'] : 0;

                $message = sprintf(
                        /* translators: 1: total processed, 2: created count, 3: updated count, 4: skipped count */
                        __( 'Item import completed successfully. Processed %1$d items (%2$d created, %3$d updated, %4$d skipped).', 'softone-woocommerce-integration' ),
                        $processed,
                        $created,
                        $updated,
                        $skipped
                );

                if ( $stale_processed > 0 ) {
                        $message .= ' ' . sprintf(
                                /* translators: %d: number of stale products handled */
                                __( 'Marked %d stale products.', 'softone-woocommerce-integration' ),
                                (int) $stale_processed
                        );
                }

                if ( $force_taxonomy_refresh ) {
                        $message .= ' ' . __( 'Category and menu assignments were refreshed.', 'softone-woocommerce-integration' );
                }

                return $message;
        }

        /**
         * Format the "last run" message for the item import interface.
         *
         * @param int $timestamp Unix timestamp.
         * @return string
         */
        private function format_item_import_last_run_message( $timestamp ) {

                $timestamp = (int) $timestamp;
                if ( $timestamp <= 0 ) {
                        return '';
                }

                return sprintf(
                        __( 'Last import completed on %s.', 'softone-woocommerce-integration' ),
                        wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp )
                );
        }

        /**
         * Handle AJAX requests that batch-delete the Main Menu.
         */
        public function handle_delete_main_menu_ajax() {

        if ( ! current_user_can( $this->capability ) ) {
        wp_send_json_error(
        array(
                                                'message' => __( 'You do not have permission to delete menus.', 'softone-woocommerce-integration' ),
                                        ),
                                        403
                                );
                        }

                        check_ajax_referer( $this->delete_main_menu_ajax_action, 'nonce' );

                        $step = 'init';

                        if ( isset( $_REQUEST['step'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
                                $step = sanitize_key( wp_unslash( $_REQUEST['step'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
                        }

                        switch ( $step ) {
                                case 'batch':
                                        $this->process_delete_main_menu_batch_ajax();
                                        break;
                                case 'init':
                                        $this->process_delete_main_menu_init_ajax();
                                        break;
                                default:
                                        wp_send_json_error(
                                                array(
                                                        'message' => __( 'Invalid menu deletion request.', 'softone-woocommerce-integration' ),
                                                ),
                                                400
                                        );
                }

}

        /**
         * Initialise a batched menu deletion request.
         */
        private function process_delete_main_menu_init_ajax() {

        $menu = wp_get_nav_menu_object( $this->main_menu_name );

        if ( ! $menu ) {
        wp_send_json_error(
        array(
        'message' => sprintf(
        /* translators: %s: menu name */
        __( 'Could not find the %s menu.', 'softone-woocommerce-integration' ),
        $this->main_menu_name
        ),
        ),
        404
        );
        }

        $items = wp_get_nav_menu_items(
        $menu->term_id,
        array(
        'post_status' => 'any',
        )
        );

        $total_items = is_array( $items ) ? count( $items ) : 0;

        if ( 0 === $total_items ) {
        $delete_result = wp_delete_nav_menu( $menu );

        if ( is_wp_error( $delete_result ) ) {
        wp_send_json_error(
        array(
        'message' => $delete_result->get_error_message(),
        ),
        500
        );
        }

        if ( false === $delete_result ) {
        wp_send_json_error(
        array(
        'message' => sprintf(
        /* translators: %s: menu name */
        __( '[SO-ADM-010] Failed to delete %s due to an unexpected error.', 'softone-woocommerce-integration' ),
        $this->main_menu_name
        ),
        ),
        500
        );
        }

        wp_send_json_success(
        array(
        'complete'      => true,
        'process_id'    => '',
        'total_items'   => 0,
        'deleted_items' => 0,
        'message'       => sprintf(
        /* translators: %s: menu name */
        __( 'Successfully deleted the %s menu.', 'softone-woocommerce-integration' ),
        $this->main_menu_name
        ),
        )
        );
        }

        list( $process_id, $state ) = $this->create_menu_delete_state( $menu, $total_items );

        wp_send_json_success(
        array(
        'process_id'    => $process_id,
        'total_items'   => (int) $state['total_items'],
        'deleted_items' => (int) $state['deleted_items'],
        'message'       => __( 'Starting menu deletion…', 'softone-woocommerce-integration' ),
        )
        );

}

        /**
         * Delete the next batch of menu items for the active deletion session.
         */
        private function process_delete_main_menu_batch_ajax() {

        if ( ! isset( $_REQUEST['process_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
        wp_send_json_error(
        array(
        'message' => __( 'Missing menu deletion session identifier.', 'softone-woocommerce-integration' ),
        ),
        400
        );
        }

        $process_id = sanitize_text_field( wp_unslash( $_REQUEST['process_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification

        if ( '' === $process_id ) {
        wp_send_json_error(
        array(
        'message' => __( 'Invalid menu deletion session.', 'softone-woocommerce-integration' ),
        ),
        400
        );
        }

        $state = $this->get_menu_delete_state( $process_id );

        if ( is_wp_error( $state ) ) {
        wp_send_json_error(
        array(
        'message' => $state->get_error_message(),
        ),
        400
        );
        }

        $batch_size = $this->menu_delete_default_batch_size;

        if ( isset( $_REQUEST['batch_size'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
        $batch_size = (int) wp_unslash( $_REQUEST['batch_size'] ); // phpcs:ignore WordPress.Security.NonceVerification
        }

        $batch_size = $this->normalize_menu_delete_batch_size( $batch_size );

        $query = $this->query_menu_items_for_batch( $state['menu_id'], $batch_size );

        $item_ids    = $query->posts;
        $found_posts = (int) $query->found_posts;

        if ( empty( $item_ids ) ) {
        $completed_state = $this->complete_menu_delete_process( $process_id, $state );

        if ( is_wp_error( $completed_state ) ) {
        wp_send_json_error(
        array(
        'message' => $completed_state->get_error_message(),
        ),
        500
        );
        }

        wp_send_json_success(
        array(
        'process_id'      => $process_id,
        'total_items'     => (int) $completed_state['total_items'],
        'deleted_items'   => (int) $completed_state['deleted_items'],
        'remaining_items' => 0,
        'complete'        => true,
        'message'         => sprintf(
        /* translators: %s: menu name */
        __( 'Successfully deleted the %s menu.', 'softone-woocommerce-integration' ),
        $this->main_menu_name
        ),
        )
        );
        }

        $deleted_this_round = 0;

        foreach ( $item_ids as $item_id ) {
        $deleted = wp_delete_post( (int) $item_id, true );

        if ( is_wp_error( $deleted ) ) {
        wp_send_json_error(
        array(
        'message' => $deleted->get_error_message(),
        ),
        500
        );
        }

        if ( false === $deleted ) {
        wp_send_json_error(
        array(
        'message' => sprintf(
        /* translators: %d: menu item ID */
        __( '[SO-ADM-012] Failed to delete menu item %d.', 'softone-woocommerce-integration' ),
        (int) $item_id
        ),
        ),
        500
        );
        }

        $deleted_this_round++; // phpcs:ignore Squiz.PHP.IncrementDecrementUsage
        }

        $state['deleted_items'] += $deleted_this_round;
        $state['last_activity']  = time();

        $remaining_after      = max( 0, $found_posts - $deleted_this_round );
        $state['total_items'] = max( (int) $state['total_items'], (int) ( $state['deleted_items'] + $remaining_after ) );

        if ( $remaining_after <= 0 ) {
        $completed_state = $this->complete_menu_delete_process( $process_id, $state );

        if ( is_wp_error( $completed_state ) ) {
        wp_send_json_error(
        array(
        'message' => $completed_state->get_error_message(),
        ),
        500
        );
        }

        wp_send_json_success(
        array(
        'process_id'      => $process_id,
        'total_items'     => (int) $completed_state['total_items'],
        'deleted_items'   => (int) $completed_state['deleted_items'],
        'remaining_items' => 0,
        'complete'        => true,
        'message'         => sprintf(
        /* translators: %s: menu name */
        __( 'Successfully deleted the %s menu.', 'softone-woocommerce-integration' ),
        $this->main_menu_name
        ),
        )
        );
        }

        $this->persist_menu_delete_state( $process_id, $state );

        wp_send_json_success(
        array(
        'process_id'      => $process_id,
        'total_items'     => (int) $state['total_items'],
        'deleted_items'   => (int) $state['deleted_items'],
        'remaining_items' => $remaining_after,
        'complete'        => false,
        'message'         => __( 'Deleted a batch of menu items.', 'softone-woocommerce-integration' ),
        )
        );

}

/**
 * Prepare entries for front-end consumption by adding formatted fields.
 *
 * @param array<int, array<string, mixed>> $entries Raw entries from the logger.
 *
 * @return array<int, array<string, mixed>>
 */
	private function prepare_activity_entries( array $entries ) {
		$prepared = array();

		foreach ( $entries as $entry ) {
			$timestamp = isset( $entry['timestamp'] ) ? (int) $entry['timestamp'] : 0;
			$channel   = isset( $entry['channel'] ) ? (string) $entry['channel'] : '';
			$action    = isset( $entry['action'] ) ? (string) $entry['action'] : '';
			$message   = isset( $entry['message'] ) ? (string) $entry['message'] : '';
			$context   = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();

			$prepared[] = array(
				'timestamp'       => $timestamp,
				'time'            => $this->format_activity_time( $timestamp ),
				'channel'         => $channel,
				'action'          => $action,
				'message'         => $message,
				'context'         => $context,
				'context_display' => $this->format_activity_context( $context ),
			);
		}

		return $prepared;
	}

/**
 * Translate a variable product failure reason into a human-readable message.
 *
 * @param array<string, mixed> $context Activity context payload.
 *
 * @return string
 */
	private function describe_variable_product_reason( array $context ) {
		$reason = isset( $context['reason'] ) ? (string) $context['reason'] : '';

		if ( '' === $reason ) {
			return '';
		}

		$messages = array(
			'invalid_variation_arguments'        => __( 'Invalid variation parameters were supplied.', 'softone-woocommerce-integration' ),
			'variable_product_handling_disabled' => __( 'Variable product handling is disabled in the plugin settings.', 'softone-woocommerce-integration' ),
			'missing_wc_variation_support'       => __( 'WooCommerce variation support is unavailable on this site.', 'softone-woocommerce-integration' ),
			'product_not_found'                  => __( 'The parent product could not be loaded.', 'softone-woocommerce-integration' ),
			'product_not_variable'               => __( 'The parent product is not marked as variable.', 'softone-woocommerce-integration' ),
			'term_not_found'                     => __( 'The referenced attribute term could not be found.', 'softone-woocommerce-integration' ),
			'term_slug_empty'                    => __( 'The attribute term does not have a usable slug.', 'softone-woocommerce-integration' ),
			'invalid_variation_object'           => __( 'The WooCommerce variation object could not be initialised.', 'softone-woocommerce-integration' ),
			'failed_to_save_variation'           => __( 'WooCommerce reported an error while saving the variation.', 'softone-woocommerce-integration' ),
		);

		if ( 'term_not_found' === $reason && ! empty( $context['term_error'] ) ) {
			return sprintf(
			__( 'The referenced attribute term could not be found: %s', 'softone-woocommerce-integration' ),
			(string) $context['term_error']
			);
		}

		if ( 'product_not_variable' === $reason && ! empty( $context['product_type'] ) ) {
			return sprintf(
			__( 'The parent product is of type “%s” and not variable.', 'softone-woocommerce-integration' ),
			(string) $context['product_type']
			);
		}

		if ( isset( $messages[ $reason ] ) ) {
			return $messages[ $reason ];
		}

		return $reason;
	}

        /**
         * Format a sync activity timestamp according to site preferences.
         *
         * @param int $timestamp Unix timestamp.
         *
         * @return string
         */
        private function format_activity_time( $timestamp ) {
                $timestamp = (int) $timestamp;

                if ( $timestamp <= 0 ) {
                        return __( 'Unknown time', 'softone-woocommerce-integration' );
                }

                $format = get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' );

                if ( function_exists( 'wp_date' ) ) {
                        return wp_date( $format, $timestamp );
                }

                return date_i18n( $format, $timestamp );
        }

        /**
         * Render a JSON context payload as a formatted string for display.
         *
         * @param array<string, mixed> $context Structured context payload.
         *
         * @return string
         */
        private function format_activity_context( array $context ) {
                if ( empty( $context ) ) {
                        return '';
                }

                $encoded = wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

                if ( false === $encoded ) {
                        $encoded = json_encode( $context, JSON_PRETTY_PRINT );
                }

                if ( false === $encoded || '' === $encoded ) {
                        return '';
                }

                return (string) $encoded;
        }

        /**
         * Normalise metadata output to include readable file size information.
         *
         * @param array<string, mixed> $metadata Raw metadata from the logger.
         *
         * @return array<string, mixed>
         */
        private function enrich_activity_metadata( array $metadata ) {
                $file_path = isset( $metadata['file_path'] ) ? (string) $metadata['file_path'] : '';
                $exists    = ! empty( $metadata['exists'] );
                $size      = isset( $metadata['size'] ) ? (int) $metadata['size'] : 0;

                if ( function_exists( 'size_format' ) ) {
                        $size_display = size_format( $size );
                } else {
                        $size_display = sprintf( __( '%d bytes', 'softone-woocommerce-integration' ), $size );
                }

                return array(
                        'file_path'    => $file_path,
                        'exists'       => $exists,
                        'size'         => $size,
                        'size_display' => $size_display,
                );
        }

        /**
         * Determine the latest timestamp from a prepared entry set.
         *
         * @param array<int, array<string, mixed>> $entries Prepared entry payload.
         *
         * @return int
         */
        private function get_latest_timestamp_from_entries( array $entries ) {
                $latest = 0;

                foreach ( $entries as $entry ) {
                        if ( isset( $entry['timestamp'] ) ) {
                                $latest = max( $latest, (int) $entry['timestamp'] );
                        }
                }

                return $latest;
        }

        /**
         * Prepare trace entries for JSON responses.
         *
         * @param array<int,array<string,mixed>> $entries Raw trace entries.
         *
         * @return array<int,array<string,mixed>>
         */
	private function prepare_trace_entries_for_response( array $entries ) {
		$prepared      = array();
		$block_indexes = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$prepared_entry = $this->prepare_single_trace_entry( $entry );
			$block_key      = $this->extract_product_block_key( $entry );

			if ( '' === $block_key ) {
				$prepared[] = $prepared_entry;
				continue;
			}

			if ( isset( $block_indexes[ $block_key ] ) && isset( $prepared[ $block_indexes[ $block_key ] ]['entries'] ) ) {
				$prepared[ $block_indexes[ $block_key ] ]['entries'][] = $prepared_entry;
				continue;
			}

			$block_indexes[ $block_key ] = count( $prepared );
			$prepared[]                  = array(
				'type'      => 'product_block',
				'timestamp' => $prepared_entry['timestamp'],
				'time'      => $prepared_entry['time'],
				'level'     => 'info',
				'product'   => $this->build_product_block_metadata( $entry ),
				'entries'   => array( $prepared_entry ),
			);
		}

		return $prepared;
	}

	/**
	 * Prepare a single trace entry for output.
	 *
	 * @param array<string,mixed> $entry Raw trace entry.
	 *
	 * @return array<string,mixed>
	 */
	private function prepare_single_trace_entry( array $entry ) {
		$timestamp = isset( $entry['timestamp'] ) ? (int) $entry['timestamp'] : time();

		return array(
			'timestamp' => $timestamp,
			'time'      => $this->format_trace_timestamp( $timestamp ),
			'type'      => isset( $entry['type'] ) ? (string) $entry['type'] : '',
			'action'    => isset( $entry['action'] ) ? (string) $entry['action'] : '',
			'level'     => isset( $entry['level'] ) ? (string) $entry['level'] : 'info',
			'message'   => isset( $entry['message'] ) ? (string) $entry['message'] : '',
			'context'   => isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array(),
		);
	}

	/**
	 * Determine whether a trace entry should be grouped within a product block.
	 *
	 * @param array<string,mixed> $entry Raw trace entry.
	 *
	 * @return string Unique block key or empty string if the entry should remain ungrouped.
	 */
	private function extract_product_block_key( array $entry ) {
		if ( empty( $entry['context'] ) || ! is_array( $entry['context'] ) ) {
			return '';
		}

		$metadata = $this->build_product_block_metadata( $entry );

		if ( ! empty( $metadata['product_id'] ) ) {
			return 'product_id:' . (int) $metadata['product_id'];
		}

		$identifier_fields = array( 'sku', 'mtrl', 'code' );

		foreach ( $identifier_fields as $field ) {
			if ( ! empty( $metadata[ $field ] ) ) {
				return $field . ':' . strtolower( (string) $metadata[ $field ] );
			}
		}

		return '';
	}

	/**
	 * Build metadata describing the product associated with a trace entry.
	 *
	 * @param array<string,mixed> $entry Raw trace entry.
	 *
	 * @return array<string,mixed>
	 */
	private function build_product_block_metadata( array $entry ) {
		$context = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();

		$product_id = 0;
		if ( isset( $context['product_id'] ) && is_numeric( $context['product_id'] ) ) {
			$product_id = (int) $context['product_id'];
		}

		$item_context = array();
		if ( isset( $context['item'] ) && is_array( $context['item'] ) ) {
			$item_context = $context['item'];
		}

		$sku = $this->first_non_empty_scalar( $context, array( 'sku', 'product_sku' ) );
		if ( '' === $sku ) {
			$sku = $this->first_non_empty_scalar( $item_context, array( 'sku', 'code' ) );
		}

		$mtrl = $this->first_non_empty_scalar( $context, array( 'mtrl' ) );
		if ( '' === $mtrl ) {
			$mtrl = $this->first_non_empty_scalar( $item_context, array( 'mtrl' ) );
		}

		$code = $this->first_non_empty_scalar( $context, array( 'code' ) );
		if ( '' === $code ) {
			$code = $this->first_non_empty_scalar( $item_context, array( 'code' ) );
		}

		$name = $this->first_non_empty_scalar( $context, array( 'name', 'product_name' ) );
		if ( '' === $name ) {
			$name = $this->first_non_empty_scalar( $item_context, array( 'name', 'description' ) );
		}

		$metadata = array(
			'product_id' => $product_id,
			'sku'        => $sku,
			'mtrl'       => $mtrl,
			'code'       => $code,
			'name'       => $name,
		);

		$metadata['label'] = $this->format_product_block_label( $metadata );

		return $metadata;
	}

	/**
	 * Extract the first non-empty scalar value from the provided keys.
	 *
	 * @param array<string,mixed> $context Context array to inspect.
	 * @param array<int,string>   $keys    Keys to evaluate in order.
	 *
	 * @return string
	 */
	private function first_non_empty_scalar( array $context, array $keys ) {
		foreach ( $keys as $key ) {
			if ( ! isset( $context[ $key ] ) ) {
				continue;
			}

			$value = $context[ $key ];

			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return trim( (string) $value );
			}
		}

		return '';
	}

	/**
	 * Create a translated label describing a product block.
	 *
	 * @param array<string,mixed> $metadata Product metadata.
	 *
	 * @return string
	 */
	private function format_product_block_label( array $metadata ) {
		$parts = array();

		if ( ! empty( $metadata['product_id'] ) ) {
			$parts[] = sprintf( __( 'ID %d', 'softone-woocommerce-integration' ), (int) $metadata['product_id'] );
		}

		if ( ! empty( $metadata['sku'] ) ) {
			$parts[] = sprintf( __( 'SKU %s', 'softone-woocommerce-integration' ), $metadata['sku'] );
		}

		if ( ! empty( $metadata['mtrl'] ) ) {
			$parts[] = sprintf( __( 'MTRL %s', 'softone-woocommerce-integration' ), $metadata['mtrl'] );
		}

		if ( ! empty( $metadata['code'] ) && ( empty( $metadata['sku'] ) || $metadata['code'] !== $metadata['sku'] ) ) {
			$parts[] = sprintf( __( 'Code %s', 'softone-woocommerce-integration' ), $metadata['code'] );
		}

		if ( ! empty( $metadata['name'] ) ) {
			$parts[] = $metadata['name'];
		}

		$base_label = __( 'Product', 'softone-woocommerce-integration' );

		if ( empty( $parts ) ) {
			return $base_label;
		}

		return sprintf( __( 'Product (%s)', 'softone-woocommerce-integration' ), implode( ' • ', $parts ) );
	}

private function prepare_trace_summary( array $result, $started_at, $finished_at, $success ) {
                $started_at  = max( 0, (int) $started_at );
                $finished_at = max( $started_at, (int) $finished_at );
                $duration    = max( 0, $finished_at - $started_at );

                $summary = array(
                        'success'             => (bool) $success,
                        'processed'           => isset( $result['processed'] ) ? (int) $result['processed'] : 0,
                        'created'             => isset( $result['created'] ) ? (int) $result['created'] : 0,
                        'updated'             => isset( $result['updated'] ) ? (int) $result['updated'] : 0,
                        'skipped'             => isset( $result['skipped'] ) ? (int) $result['skipped'] : 0,
                        'stale_processed'     => isset( $result['stale_processed'] ) ? (int) $result['stale_processed'] : 0,
                        'started_at'          => $started_at,
                        'finished_at'         => $finished_at,
                        'duration_seconds'    => $duration,
                        'started_at_formatted'  => $this->format_trace_timestamp( $started_at ),
                        'finished_at_formatted' => $this->format_trace_timestamp( $finished_at ),
                        'duration_human'      => $duration > 0 ? human_time_diff( $started_at, $finished_at ) : __( 'less than a minute', 'softone-woocommerce-integration' ),
                );

                return $summary;
        }

        /**
         * Format a trace timestamp for display.
         *
         * @param int $timestamp Unix timestamp.
         *
         * @return string
         */
        private function format_trace_timestamp( $timestamp ) {
                $timestamp = (int) $timestamp;

                if ( $timestamp <= 0 ) {
                        return '';
                }

                $date_format = (string) get_option( 'date_format', 'Y-m-d' );
                $time_format = (string) get_option( 'time_format', 'H:i' );
                $format      = trim( $date_format . ' ' . $time_format );

                if ( function_exists( 'wp_date' ) ) {
                        return wp_date( $format, $timestamp );
                }

                return date_i18n( $format, $timestamp );
        }

        /**
         * Normalise user-submitted boolean flags for the process trace request.
         *
         * @param mixed $value Raw submitted value.
         *
         * @return bool
         */
        private function normalize_process_trace_flag( $value ) {
                if ( is_bool( $value ) ) {
                        return $value;
                }

                if ( is_numeric( $value ) ) {
                        return (bool) absint( $value );
                }

                $value = strtolower( trim( (string) $value ) );

                return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
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
         * Render the variable product activity viewer.
         *
         * @return void
         */
        public function render_variable_product_logs_page() {

               if ( ! current_user_can( $this->capability ) ) {
                       return;
               }

               $error_state = '';
               $entries     = array();

               if ( $this->activity_logger && method_exists( $this->activity_logger, 'get_entries' ) ) {
                       $raw_entries = $this->activity_logger->get_entries( $this->sync_activity_limit );

                       foreach ( $raw_entries as $entry ) {
                               $channel = isset( $entry['channel'] ) ? (string) $entry['channel'] : '';

                               if ( 'variable_products' !== $channel ) {
                                       continue;
                               }

                               $entries[] = $entry;
                       }
               } else {
                       $error_state = __( 'The sync activity logger is not available.', 'softone-woocommerce-integration' );
               }

               $prepared_entries = $this->prepare_activity_entries( $entries );

               $display_entries = array();

               foreach ( $prepared_entries as $entry ) {
                       $context = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();

                       $display_entries[] = array(
                               'timestamp'       => isset( $entry['timestamp'] ) ? (int) $entry['timestamp'] : 0,
                               'time'            => isset( $entry['time'] ) ? (string) $entry['time'] : '',
                               'action'          => isset( $entry['action'] ) ? (string) $entry['action'] : '',
                               'message'         => isset( $entry['message'] ) ? (string) $entry['message'] : '',
                               'context_display' => isset( $entry['context_display'] ) ? (string) $entry['context_display'] : '',
                               'context'         => $context,
                               'reason'          => $this->describe_variable_product_reason( $context ),
                       );
               }

               $page_size = (int) apply_filters( 'softone_wc_integration_variable_logs_page_size', 20 );

               if ( $page_size <= 0 ) {
                       $page_size = 20;
               }

               wp_enqueue_script(
                       'softone-variable-product-logs',
                       plugin_dir_url( __FILE__ ) . 'js/softone-variable-product-logs.js',
                       array(),
                       $this->version,
                       true
               );

               $localised_entries = array();

               foreach ( $display_entries as $entry ) {
                       $localised_entries[] = array(
                               'timestamp' => (int) $entry['timestamp'],
                               'time'      => (string) $entry['time'],
                               'action'    => (string) $entry['action'],
                               'message'   => (string) $entry['message'],
                               'reason'    => (string) $entry['reason'],
                               'context'   => (string) $entry['context_display'],
                       );
               }

		wp_localize_script(
			'softone-variable-product-logs',
			'softoneVariableProductLogs',
			array(
				'entries'  => $localised_entries,
				'pageSize' => $page_size,
				'strings'  => array(
					'noContext'     => __( 'No additional context provided.', 'softone-woocommerce-integration' ),
					'pageIndicator' => __( 'Page %1$d of %2$d', 'softone-woocommerce-integration' ),
					'noEntries'     => __( 'No variable product activity has been recorded yet.', 'softone-woocommerce-integration' ),
				),
			)
		);

               $entries_for_display = $display_entries;
               $entries_limit       = $this->sync_activity_limit;
               $page_size_display   = $page_size;

               include plugin_dir_path( __FILE__ ) . 'partials/softone-woocommerce-integration-variable-product-logs.php';
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
                $limit       = $this->sync_activity_limit;

                if ( $this->activity_logger && method_exists( $this->activity_logger, 'get_entries' ) ) {
                        $entries = $this->activity_logger->get_entries( $limit );
                } else {
                        $error_state = __( 'The sync activity logger is not available.', 'softone-woocommerce-integration' );
                }

                if ( $this->activity_logger && method_exists( $this->activity_logger, 'get_metadata' ) ) {
                        $metadata = $this->activity_logger->get_metadata();
                }

                $prepared_entries = $this->prepare_activity_entries( $entries );
                $latest_timestamp = $this->get_latest_timestamp_from_entries( $prepared_entries );
                $metadata         = $this->enrich_activity_metadata( $metadata );

                $poll_interval = (int) apply_filters( 'softone_wc_integration_sync_poll_interval', $this->sync_activity_poll_interval );

                if ( $poll_interval < 1000 ) {
                        $poll_interval = 1000;
                }

                $manual_sync_enabled = ! empty( $this->item_sync );

                $localised_data = array(
                        'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
                        'action'          => $this->sync_activity_action,
                        'nonce'           => wp_create_nonce( $this->sync_activity_action ),
                        'pollInterval'    => $poll_interval,
                        'limit'           => $limit,
                        'latestTimestamp' => $latest_timestamp,
                        'initialEntries'  => $prepared_entries,
                        'metadata'        => $metadata,
                        'error'           => $error_state,
                        'strings'         => array(
                                'entriesDisplayed'   => sprintf( __( 'Displaying up to %d recent events.', 'softone-woocommerce-integration' ), $limit ),
                                'logFileLocation'    => __( 'Log file location: %s', 'softone-woocommerce-integration' ),
                                'logFileSize'        => __( 'Log file size: %s', 'softone-woocommerce-integration' ),
                                'logFileMissing'     => __( 'A log file will be created automatically when new sync events occur.', 'softone-woocommerce-integration' ),
                                'loading'            => __( 'Loading…', 'softone-woocommerce-integration' ),
                                'refreshing'         => __( 'Refreshing activity…', 'softone-woocommerce-integration' ),
                                'error'              => __( 'Unable to load sync activity. Please try again.', 'softone-woocommerce-integration' ),
                                'retry'              => __( 'Retry', 'softone-woocommerce-integration' ),
                                'noEntries'          => __( 'No sync activity has been recorded yet.', 'softone-woocommerce-integration' ),
                                'manualSyncStarting' => __( 'Starting manual sync…', 'softone-woocommerce-integration' ),
                                'manualSyncQueued'   => __( 'Manual sync request sent.', 'softone-woocommerce-integration' ),
                                'manualSyncError'    => __( 'Manual sync request failed. Please check the logs.', 'softone-woocommerce-integration' ),
                                'pollingPaused'      => __( 'Polling has been paused after repeated errors.', 'softone-woocommerce-integration' ),
                        ),
                        'manualSync'      => array(
                                'enabled'  => $manual_sync_enabled,
                                'endpoint' => admin_url( 'admin-post.php' ),
                                'action'   => Softone_Item_Sync::ADMIN_ACTION,
                                'nonce'    => wp_create_nonce( Softone_Item_Sync::ADMIN_ACTION ),
                        ),
                );

                wp_enqueue_script(
                        'softone-sync-monitor',
                        plugin_dir_url( __FILE__ ) . 'js/softone-sync-monitor.js',
                        array(),
                        $this->version,
                        true
                );

                wp_localize_script( 'softone-sync-monitor', 'softoneSyncMonitor', $localised_data );

                $entries_text = $localised_data['strings']['entriesDisplayed'];
                $file_text    = '';
                $size_text    = $localised_data['strings']['logFileMissing'];

                if ( '' !== $metadata['file_path'] ) {
                        $file_text = sprintf( $localised_data['strings']['logFileLocation'], $metadata['file_path'] );
                }

                if ( $metadata['exists'] ) {
                        $size_text = sprintf( $localised_data['strings']['logFileSize'], $metadata['size_display'] );
                }

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

        <div class="softone-sync-monitor" data-softone-sync-monitor>
                <div class="softone-sync-monitor__status" data-sync-status role="status" aria-live="polite"></div>
                <div class="softone-sync-monitor__status softone-sync-monitor__status--error" data-sync-error role="alert" hidden="hidden"></div>

                <div class="softone-sync-monitor__controls">
                        <button type="button" class="button" data-sync-refresh><?php esc_html_e( 'Refresh now', 'softone-woocommerce-integration' ); ?></button>
                        <?php if ( $manual_sync_enabled ) : ?>
                        <button type="button" class="button button-primary" data-sync-manual><?php esc_html_e( 'Run Manual Sync', 'softone-woocommerce-integration' ); ?></button>
                        <?php endif; ?>
                </div>

                <div class="softone-sync-monitor__meta" aria-label="<?php esc_attr_e( 'Log file details', 'softone-woocommerce-integration' ); ?>">
                        <p data-sync-meta="entries"><?php echo esc_html( $entries_text ); ?></p>
                        <p data-sync-meta="file"><?php echo esc_html( $file_text ); ?></p>
                        <p data-sync-meta="size"><?php echo esc_html( $size_text ); ?></p>
                </div>

                <table class="widefat fixed striped softone-sync-monitor__table">
                        <thead>
                        <tr>
                                <th scope="col"><?php esc_html_e( 'Time', 'softone-woocommerce-integration' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Channel', 'softone-woocommerce-integration' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Action', 'softone-woocommerce-integration' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Message', 'softone-woocommerce-integration' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Context', 'softone-woocommerce-integration' ); ?></th>
                        </tr>
                        </thead>
                        <tbody data-sync-body>
                        <tr class="softone-sync-monitor__empty">
                                <td colspan="5"><?php esc_html_e( 'No sync activity has been recorded yet.', 'softone-woocommerce-integration' ); ?></td>
                        </tr>
                        </tbody>
                </table>
        </div>

        <noscript>
                <p><?php esc_html_e( 'JavaScript is required to view live sync activity updates.', 'softone-woocommerce-integration' ); ?></p>
        </noscript>

        <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin-top:1.5em;">
                <?php wp_nonce_field( $this->clear_activity_action ); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr( $this->clear_activity_action ); ?>" />
                <?php submit_button( __( 'Delete Sync Activity Log', 'softone-woocommerce-integration' ), 'delete', 'softone_wc_integration_delete_sync_activity', false ); ?>
        </form>
</div>
<?php

        }

        /**
         * Render the process trace diagnostic page.
         *
         * @return void
         */
        public function render_process_trace_page() {

                if ( ! current_user_can( $this->capability ) ) {
                        return;
                }

include plugin_dir_path( __FILE__ ) . 'partials/softone-woocommerce-integration-process-trace.php';
}

/**
 * Render the order export diagnostic log page.
 */
public function render_order_export_logs_page() {

if ( ! current_user_can( $this->capability ) ) {
return;
}

$entries     = array();
$metadata    = array();
$error_state = '';
$limit       = $this->order_export_log_limit;

if ( $this->order_export_logger && method_exists( $this->order_export_logger, 'get_entries' ) ) {
$entries = $this->order_export_logger->get_entries( $limit );
} else {
$error_state = __( 'The order export logger is not available.', 'softone-woocommerce-integration' );
}

if ( $this->order_export_logger && method_exists( $this->order_export_logger, 'get_metadata' ) ) {
$metadata = $this->order_export_logger->get_metadata();
}

$entries_for_display = $this->prepare_activity_entries( $entries );
$metadata            = $this->enrich_activity_metadata( $metadata );

$order_export_entries = $entries_for_display;
$order_export_metadata = $metadata;
$order_export_error    = $error_state;
$order_export_limit    = $limit;

include plugin_dir_path( __FILE__ ) . 'partials/softone-woocommerce-integration-order-export-logs.php';
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
                $result['error'] = __( 'WooCommerce logging directory is not available on this site.', 'softone-woocommerce-integration' );
                return $result;
        }

        $log_directory        = (string) WC_LOG_DIR;
        $result['directory']  = $log_directory;

        $files = $this->locate_category_log_files( $log_directory );
        if ( empty( $files ) ) {
                $result['files'] = array();
                return $result;
        }

        $result['files'] = $files;

        $entries       = array();
        $order         = 0;
        $limit         = (int) $this->category_log_limit;
        $limit         = $limit > 0 ? $limit : 400;

        // Helper: iterate lines for plain or gz logs.
        $read_lines = function( $path ) {
                $lines = array();
                if ( substr( $path, -3 ) === '.gz' ) {
                        $h = @gzopen( $path, 'rb' );
                        if ( ! $h ) {
                                return $lines;
                        }
                        while ( false === gzeof( $h ) ) {
                                $line = gzgets( $h );
                                if ( false === $line ) {
                                        break;
                                }
                                $lines[] = $line;
                        }
                        gzclose( $h );
                        return $lines;
                }

                $h = @fopen( $path, 'rb' );
                if ( ! $h ) {
                        return $lines;
                }
                while ( ! feof( $h ) ) {
                        $line = fgets( $h );
                        if ( false === $line ) {
                                break;
                        }
                        $lines[] = $line;
                }
                fclose( $h );
                return $lines;
        };

        foreach ( $files as $file_path ) {
                if ( count( $entries ) >= $limit ) {
                        break;
                }

                $lines = $read_lines( $file_path );
                if ( empty( $lines ) ) {
                        continue;
                }

                // Read newest-looking lines first (logs usually append).
                // Reversing makes us hit the limit faster with recent entries.
                $lines = array_reverse( $lines );

                foreach ( $lines as $line ) {
                        if ( count( $entries ) >= $limit ) {
                                break;
                        }
                        $line = (string) $line;
                        if ( '' === trim( $line ) ) {
                                continue;
                        }

                        $order++;
                        $entry = $this->parse_category_log_line( $line, (string) $file_path, $order );

                        // Optional: filter by our source to keep this page clean.
                        if ( isset( $entry['source'] ) && $entry['source'] !== '' ) {
                                // Only show SoftOne category sync log entries.
                                // Woo usually writes with the "source" we pass in logger context.
                                if ( 0 !== strcasecmp( $entry['source'], 'softone-category-sync' ) ) {
                                        continue;
                                }
                        }

                        $entries[] = $entry;
                }
        }

        if ( empty( $entries ) ) {
                return $result;
        }

        // Already reversed per-file; keep the global most-recent-first ordering by timestamp.
        usort(
                $entries,
                function( $a, $b ) {
                        $ta = isset( $a['timestamp'] ) ? (int) $a['timestamp'] : 0;
                        $tb = isset( $b['timestamp'] ) ? (int) $b['timestamp'] : 0;
                        if ( $ta === $tb ) {
                                // fallback to order for stability (newer first).
                                return ( ( $b['order'] ?? 0 ) <=> ( $a['order'] ?? 0 ) );
                        }
                        return ( $tb <=> $ta );
                }
        );

        $result['entries'] = $entries;
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

        // Helper to join a path safely without WP helper reliance.
        $join = function( $base, $path ) {
                $base = (string) $base;
                $path = (string) $path;
                if ( '' === $base ) {
                        return $path;
                }
                $sep = defined( 'DIRECTORY_SEPARATOR' ) ? DIRECTORY_SEPARATOR : '/';
                return rtrim( $base, '/\\' ) . $sep . ltrim( $path, '/\\' );
        };

        // First try WooCommerce API if available (these return basenames).
        if ( class_exists( 'WC_Log_Handler_File' ) && method_exists( 'WC_Log_Handler_File', 'get_log_files' ) ) {
                $log_files = WC_Log_Handler_File::get_log_files();
                if ( is_array( $log_files ) ) {
                        foreach ( $log_files as $log_file ) {
                                $full_path = $join( $log_directory, (string) $log_file );
                                if ( '' === $full_path || isset( $seen[ $full_path ] ) ) {
                                        continue;
                                }
                                $files[]            = $full_path;
                                $seen[ $full_path ] = true;
                        }
                }
        }

        // Also look for raw files on disk (compressed + uncompressed).
        $globs = array(
                '*.log',            // standard Woo logs
                '*.log.gz',         // rotated/compressed logs
        );

        foreach ( $globs as $pattern ) {
                $matched = glob( $join( $log_directory, $pattern ) );
                if ( is_array( $matched ) ) {
                        foreach ( $matched as $match ) {
                                $match = (string) $match;
                                if ( '' === $match || isset( $seen[ $match ] ) ) {
                                        continue;
                                }
                                $files[]            = $match;
                                $seen[ $match ]     = true;
                        }
                }
        }

        if ( empty( $files ) ) {
                return array();
        }

        // Prefer category-sync logs first, but keep others as fallback.
        $preferred = array();
        $others    = array();

        foreach ( $files as $f ) {
                $name = basename( (string) $f );
                if ( false !== stripos( $name, 'softone-category-sync' ) || false !== stripos( $name, 'softone-category' ) ) {
                        $preferred[] = $f;
                } else {
                        $others[] = $f;
                }
        }

        $ordered = array_merge( $preferred, $others );

        // Sort newest first by filemtime.
        usort(
                $ordered,
                function( $a, $b ) {
                        $ta = @filemtime( $a ) ?: 0;
                        $tb = @filemtime( $b ) ?: 0;
                        if ( $ta === $tb ) {
                                return strcmp( $b, $a );
                        }
                        return ( $tb <=> $ta );
                }
        );

        return $ordered;
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
         * Render a stored menu management notice when available.
         */
        private function maybe_render_menu_notice() {

                $notice = get_transient( $this->get_menu_notice_key() );

                if ( empty( $notice['message'] ) ) {
                        return;
                }

                delete_transient( $this->get_menu_notice_key() );

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
         * Store a menu management notice for the current user.
         *
         * @param string $type    Notice type.
         * @param string $message Notice message.
         */
        private function store_menu_notice( $type, $message ) {

                set_transient(
                        $this->get_menu_notice_key(),
                        array(
                                'type'    => $type,
                                'message' => $message,
                        ),
                        MINUTE_IN_SECONDS
                );

        }

        /**
         * Delete a WordPress navigation menu by name.
         *
         * Mirrors the expected behaviour of deleting the "Main Menu" manually
         * through the WordPress admin interface: locate the menu object and
         * remove it (including all related items) when present.
         *
         * @param string $menu_name Menu name, slug or ID.
         *
         * @return true|WP_Error True on success, WP_Error on failure.
         */
private function delete_nav_menu_by_name( $menu_name ) {

if ( ! function_exists( 'wp_get_nav_menu_object' ) || ! function_exists( 'wp_delete_nav_menu' ) ) {
return new WP_Error(
                                'softone_missing_menu_functions',
                                __( '[SO-ADM-011] Required WordPress menu functions are unavailable.', 'softone-woocommerce-integration' )
                        );
                }

                $menu = wp_get_nav_menu_object( $menu_name );

                if ( ! $menu ) {
                        return new WP_Error(
                                'softone_menu_not_found',
                                sprintf(
                                        /* translators: %s: menu name */
                                        __( '[SO-ADM-008] Could not find a menu named %s.', 'softone-woocommerce-integration' ),
                                        $menu_name
                                )
                        );
                }

                $result = wp_delete_nav_menu( $menu );

                if ( is_wp_error( $result ) ) {
                        return new WP_Error(
                                $result->get_error_code(),
                                sprintf(
                                        /* translators: 1: menu name, 2: error message */
                                        __( '[SO-ADM-009] Failed to delete %1$s: %2$s', 'softone-woocommerce-integration' ),
                                        $menu_name,
                                        $result->get_error_message()
                                )
                        );
                }

                if ( false === $result ) {
                        return new WP_Error(
                                'softone_menu_delete_failed',
                                sprintf(
                                        /* translators: %s: menu name */
                                        __( '[SO-ADM-010] Failed to delete %s due to an unexpected error.', 'softone-woocommerce-integration' ),
                                        $menu_name
                                )
                        );
                }

return true;

}

/**
 * Create and store the state used to track an in-progress menu deletion.
 *
 * @param WP_Term $menu        Menu term object.
 * @param int     $total_items Total number of menu items detected.
 *
 * @return array{0:string,1:array} Tuple containing the process ID and state array.
 */
private function create_menu_delete_state( $menu, $total_items ) {

$process_id = wp_generate_uuid4();
$user_id    = get_current_user_id();

$state = array(
'menu_id'       => (int) $menu->term_id,
'menu_name'     => (string) $menu->name,
'total_items'   => (int) $total_items,
'deleted_items' => 0,
'user_id'       => $user_id,
'started_at'    => time(),
'last_activity' => time(),
);

$this->persist_menu_delete_state( $process_id, $state );

return array( $process_id, $state );

}

/**
 * Persist the menu deletion state to a transient for future batches.
 *
 * @param string $process_id Unique process identifier.
 * @param array  $state      State to store.
 */
private function persist_menu_delete_state( $process_id, array $state ) {

$user_id = isset( $state['user_id'] ) ? (int) $state['user_id'] : get_current_user_id();
$key     = $this->get_menu_delete_state_key( $process_id, $user_id );

set_transient( $key, $state, $this->menu_delete_state_lifetime );

}

/**
 * Retrieve the state for an active menu deletion process.
 *
 * @param string $process_id Unique process identifier.
 *
 * @return array|WP_Error
 */
private function get_menu_delete_state( $process_id ) {

$user_id = get_current_user_id();
$key     = $this->get_menu_delete_state_key( $process_id, $user_id );
$state   = get_transient( $key );

if ( ! is_array( $state ) ) {
return new WP_Error(
'softone_menu_delete_state_missing',
__( 'The menu deletion session has expired. Please start again.', 'softone-woocommerce-integration' )
);
}

if ( ! isset( $state['user_id'] ) || (int) $state['user_id'] !== $user_id ) {
return new WP_Error(
'softone_menu_delete_state_mismatch',
__( 'You are not allowed to continue this deletion session.', 'softone-woocommerce-integration' )
);
}

return $state;

}

/**
 * Remove any stored state for a menu deletion session.
 *
 * @param string $process_id Unique process identifier.
 * @param int    $user_id    User identifier.
 */
private function delete_menu_delete_state( $process_id, $user_id ) {

delete_transient( $this->get_menu_delete_state_key( $process_id, $user_id ) );

}

/**
 * Generate the transient key used to store menu deletion state data.
 *
 * @param string $process_id Unique process identifier.
 * @param int    $user_id    User identifier.
 *
 * @return string
 */
private function get_menu_delete_state_key( $process_id, $user_id ) {

return $this->menu_delete_state_transient . (int) $user_id . '_' . $process_id;

}

/**
 * Complete a menu deletion by removing the menu term and clearing stored state.
 *
 * @param string $process_id Process identifier.
 * @param array  $state      State data.
 *
 * @return array|WP_Error Updated state on success, error otherwise.
 */
private function complete_menu_delete_process( $process_id, array $state ) {

$menu = wp_get_nav_menu_object( (int) $state['menu_id'] );

if ( $menu ) {
$result = wp_delete_nav_menu( $menu );

if ( is_wp_error( $result ) ) {
return $result;
}

if ( false === $result ) {
return new WP_Error(
'softone_menu_delete_failed',
sprintf(
/* translators: %s: menu name */
__( '[SO-ADM-010] Failed to delete %s due to an unexpected error.', 'softone-woocommerce-integration' ),
$state['menu_name']
)
);
}
}

$state['deleted_items'] = max( (int) $state['deleted_items'], (int) $state['total_items'] );
$this->delete_menu_delete_state( $process_id, (int) $state['user_id'] );

return $state;

}

/**
 * Normalise the requested batch size for menu item deletions.
 *
 * @param int $requested_size Requested batch size.
 *
 * @return int
 */
private function normalize_menu_delete_batch_size( $requested_size ) {

$size = (int) $requested_size;

if ( $size < 1 ) {
$size = $this->menu_delete_default_batch_size;
}

return min( $size, 100 );

}

/**
 * Retrieve a batch of menu item IDs for deletion.
 *
 * @param int $menu_id    Menu term ID.
 * @param int $batch_size Number of menu items to fetch.
 *
 * @return WP_Query
 */
private function query_menu_items_for_batch( $menu_id, $batch_size ) {

$query_args = array(
'post_type'      => 'nav_menu_item',
'posts_per_page' => (int) $batch_size,
'fields'         => 'ids',
'no_found_rows'  => false,
'orderby'        => 'ID',
'order'          => 'ASC',
'post_status'    => 'any',
'tax_query'      => array(
array(
'taxonomy'         => 'nav_menu',
'field'            => 'term_id',
'terms'            => (int) $menu_id,
'include_children' => false,
),
),
);

return new WP_Query( $query_args );

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
         * Generate the transient key for menu management notices for the current user.
         *
         * @return string
         */
        private function get_menu_notice_key() {

                $user_id = get_current_user_id();

                return $this->menu_notice_transient . ( $user_id ? $user_id : 'guest' );

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
public function enqueue_scripts( $hook_suffix = '' ) {

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/softone-woocommerce-integration-admin.js', array( 'jquery' ), $this->version, false );

wp_localize_script(
$this->plugin_name,
'softoneApiTester',
array(
'presets'            => $this->get_api_tester_presets_for_script(),
'defaultDescription' => __( 'Choose a preset to automatically populate the form fields.', 'softone-woocommerce-integration' ),
)
);

wp_localize_script(
$this->plugin_name,
'softoneMenuDeletion',
array(
'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
'action'      => $this->get_delete_main_menu_ajax_action(),
'nonce'       => wp_create_nonce( $this->get_delete_main_menu_ajax_action() ),
'formSelector'=> '#softone-delete-main-menu-form',
'batchSize'   => $this->menu_delete_default_batch_size,
'menuName'    => $this->main_menu_name,
'i18n'        => array(
'preparingText'    => __( 'Preparing to delete the menu…', 'softone-woocommerce-integration' ),
'progressTemplate' => __( '%1$s of %2$s items deleted (%3$s%%).', 'softone-woocommerce-integration' ),
'completeText'     => __( 'Menu deleted successfully.', 'softone-woocommerce-integration' ),
'genericError'     => __( 'An unexpected error occurred while deleting the menu.', 'softone-woocommerce-integration' ),
'menuDeletedMessage'=> sprintf(
/* translators: %s: menu name */
__( 'Successfully deleted the %s menu.', 'softone-woocommerce-integration' ),
$this->main_menu_name
),
),
)
);

wp_localize_script(
$this->plugin_name,
'softoneItemImport',
array(
'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
'action'             => $this->get_item_import_ajax_action(),
'nonce'              => wp_create_nonce( $this->get_item_import_ajax_action() ),
'containerSelector'  => '#softone-item-import-panel',
'triggerSelector'    => '.softone-item-import__trigger',
'progressSelector'   => '#softone-item-import-progress',
'progressTextSelector' => '#softone-item-import-progress-text',
'statusSelector'     => '#softone-item-import-status',
'lastRunSelector'    => '#softone-item-import-last-run',
'batchSize'          => $this->item_import_default_batch_size,
'i18n'               => array(
'preparingText'        => __( 'Preparing item import…', 'softone-woocommerce-integration' ),
'progressTemplate'     => __( '%1$s of %2$s items processed (%3$s%%).', 'softone-woocommerce-integration' ),
'indeterminateTemplate'=> __( '%1$s items processed so far…', 'softone-woocommerce-integration' ),
'completeText'         => __( 'Item import completed successfully.', 'softone-woocommerce-integration' ),
'genericError'         => __( 'An unexpected error occurred while importing items.', 'softone-woocommerce-integration' ),
'warningPrefix'        => __( 'Warning:', 'softone-woocommerce-integration' ),
),
)
);

		if ( 'nav-menus.php' === $hook_suffix ) {
			$this->enqueue_nav_menu_guard_scripts();
		}

$current_page = '';
if ( isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
        $current_page = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
}

if ( $current_page === $this->process_trace_slug ) {
        wp_enqueue_script(
                'softone-process-trace',
                plugin_dir_url( __FILE__ ) . 'js/softone-process-trace.js',
                array(),
                $this->version,
                true
        );

        $date_format = (string) get_option( 'date_format', 'Y-m-d' );
        $time_format = (string) get_option( 'time_format', 'H:i' );

		$trace_strings = array(
			'runTrace'         => __( 'Run process trace', 'softone-woocommerce-integration' ),
			'running'          => __( 'Running process trace…', 'softone-woocommerce-integration' ),
			'completed'        => __( 'Process trace completed.', 'softone-woocommerce-integration' ),
			'failed'           => __( 'Process trace failed.', 'softone-woocommerce-integration' ),
			'empty'            => __( 'No events were recorded during this trace.', 'softone-woocommerce-integration' ),
			'summaryHeading'   => __( 'Summary', 'softone-woocommerce-integration' ),
			'startedAt'        => __( 'Started at', 'softone-woocommerce-integration' ),
			'finishedAt'       => __( 'Finished at', 'softone-woocommerce-integration' ),
			'duration'         => __( 'Duration', 'softone-woocommerce-integration' ),
			'processed'        => __( 'Processed', 'softone-woocommerce-integration' ),
			'created'          => __( 'Created', 'softone-woocommerce-integration' ),
			'updated'          => __( 'Updated', 'softone-woocommerce-integration' ),
			'skipped'          => __( 'Skipped', 'softone-woocommerce-integration' ),
			'staleProcessed'   => __( 'Stale products updated', 'softone-woocommerce-integration' ),
			'details'          => __( 'Details', 'softone-woocommerce-integration' ),
			'productBlockHeading' => __( 'Product', 'softone-woocommerce-integration' ),
			'productIdLabel'      => __( 'ID', 'softone-woocommerce-integration' ),
			'productSkuLabel'     => __( 'SKU', 'softone-woocommerce-integration' ),
			'productMtrlLabel'    => __( 'MTRL', 'softone-woocommerce-integration' ),
			'productCodeLabel'    => __( 'Code', 'softone-woocommerce-integration' ),
			'productNameLabel'    => __( 'Name', 'softone-woocommerce-integration' ),
			'copyContext'      => __( 'Copy context', 'softone-woocommerce-integration' ),
			'copied'           => __( 'Copied!', 'softone-woocommerce-integration' ),
			'copyFailed'       => __( 'Copy failed', 'softone-woocommerce-integration' ),
			'errorPrefix'      => __( 'Error:', 'softone-woocommerce-integration' ),
			'successStatus'    => __( 'Success', 'softone-woocommerce-integration' ),
                'failureStatus'    => __( 'Failed', 'softone-woocommerce-integration' ),
                'durationFallback' => __( 'Less than a minute', 'softone-woocommerce-integration' ),
        );

        $trace_data = array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'action'  => $this->get_process_trace_action(),
                'nonce'   => wp_create_nonce( $this->get_process_trace_action() ),
                'strings' => $trace_strings,
                'options' => array(
                        'forceFullImport'      => array(
                                'label'       => __( 'Force full import', 'softone-woocommerce-integration' ),
                                'description' => __( 'Ignore cached timestamps and fetch the entire Softone catalogue.', 'softone-woocommerce-integration' ),
                        ),
                        'forceTaxonomyRefresh' => array(
                                'label'       => __( 'Refresh taxonomy assignments', 'softone-woocommerce-integration' ),
                                'description' => __( 'Rebuild attribute and category relationships during the trace.', 'softone-woocommerce-integration' ),
                        ),
                ),
                'format'  => array(
                        'date' => $date_format,
                        'time' => $time_format,
                ),
        );

        wp_localize_script( 'softone-process-trace', 'softoneProcessTrace', $trace_data );
}

}

	/**
	 * Enqueues the navigation menu guard script wherever menu items may be edited.
	 *
	 * @return void
	 */
	public function enqueue_nav_menu_guard_scripts() {
		wp_enqueue_script(
			'softone-nav-menu-guard',
			plugin_dir_url( __FILE__ ) . 'js/softone-nav-menu-guard.js',
			array( 'jquery' ),
			$this->version,
			true
		);
	}


}

