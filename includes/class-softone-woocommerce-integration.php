<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://www.georgenicolaou.me/
 * @since      1.0.0
 *
 * @package    Softone_Woocommerce_Integration
 * @subpackage Softone_Woocommerce_Integration/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Softone_Woocommerce_Integration
 * @subpackage Softone_Woocommerce_Integration/includes
 * @author     George Nicolaou <orionas.elite@gmail.com>
 */
class Softone_Woocommerce_Integration {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Softone_Woocommerce_Integration_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

        /**
         * Item synchronisation service instance.
         *
         * @var Softone_Item_Sync
         */
        protected $item_sync;

        /**
         * File-based activity logger instance.
         *
         * @var Softone_Sync_Activity_Logger
         */
        protected $activity_logger;

        /**
         * Customer synchronisation service instance.
         *
         * @var Softone_Customer_Sync
         */
        protected $customer_sync;

        /**
         * Order synchronisation service instance.
         *
         * @var Softone_Order_Sync
         */
        protected $order_sync;

        /**
         * Define the core functionality of the plugin.
         *
         * Set the plugin name and the plugin version that can be used throughout the plugin.
         * Load the dependencies, define the locale, and set the hooks for the admin area and
         * the public-facing side of the site.
         *
         * @since    1.0.0
         */
	public function __construct() {
                if ( defined( 'SOFTONE_WOOCOMMERCE_INTEGRATION_VERSION' ) ) {
                        $this->version = SOFTONE_WOOCOMMERCE_INTEGRATION_VERSION;
                } else {
                        $this->version = '1.8.98';
                }
		$this->plugin_name = 'softone-woocommerce-integration';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
		$this->define_shared_hooks();
	}

	/**
	 * Register hooks that run on both the public and admin sides.
	 *
	 * @return void
	 */
        private function define_shared_hooks() {
                $this->loader->add_action( 'init', $this, 'maybe_register_brand_taxonomy' );
                $this->loader->add_filter(
                        'softone_wc_integration_enable_variable_product_handling',
                        $this,
                        'enable_variable_product_handling_from_settings'
                );
        }

        /**
         * Enable variable product handling when toggled in the plugin settings.
         *
         * @param bool $enabled Whether variable product handling is currently enabled.
         *
         * @return bool
         */
        public function enable_variable_product_handling_from_settings( $enabled ) {
                $setting = softone_wc_integration_get_setting( 'enable_variable_product_handling', 'no' );

                if ( 'yes' === $setting ) {
                        return true;
                }

                return (bool) $enabled;
        }

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Softone_Woocommerce_Integration_Loader. Orchestrates the hooks of the plugin.
	 * - Softone_Woocommerce_Integration_i18n. Defines internationalization functionality.
	 * - Softone_Woocommerce_Integration_Admin. Defines all hooks for the admin area.
	 * - Softone_Woocommerce_Integration_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {

                /**
                 * The class responsible for orchestrating the actions and filters of the
                 * core plugin.
                 */
                require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-softone-woocommerce-integration-loader.php';

                /**
                 * Service class for performing SoftOne API requests.
                 */
                require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-softone-api-client.php';

                /**
                 * Helper for writing category synchronisation log entries.
                 */
                require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-softone-category-sync-logger.php';

                /**
                 * File-based synchronisation activity logger.
                 */
                require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-softone-sync-activity-logger.php';

                /**
                 * Helpers for generating detailed process trace output.
                 */
                require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-softone-process-trace.php';

                /**
                 * Service class for synchronising items from SoftOne into WooCommerce.
                 */
                require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-softone-item-sync.php';

                /**
                 * Service class for synchronising WooCommerce customers with SoftOne.
                 */
                require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-softone-customer-sync.php';

                /**
                 * Service class for exporting WooCommerce orders to SoftOne.
                 */
                require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-softone-order-sync.php';

                /**
                 * Helper for dynamically populating navigation menus.
                 */
                require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-softone-menu-populator.php';

                /**
                 * Helper functions for accessing plugin settings.
                 */
                require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/softone-woocommerce-integration-settings.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-softone-woocommerce-integration-i18n.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-softone-woocommerce-integration-admin.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-softone-woocommerce-integration-public.php';

		require_once __DIR__ . '/class-softone-sku-image-attacher.php';


                $this->loader          = new Softone_Woocommerce_Integration_Loader();
                $this->activity_logger = new Softone_Sync_Activity_Logger();
                $this->item_sync       = new Softone_Item_Sync( null, null, null, $this->activity_logger );
                $this->customer_sync   = new Softone_Customer_Sync();
                $this->order_sync      = new Softone_Order_Sync( null, $this->customer_sync );

                $this->item_sync->register_hooks( $this->loader );
                $this->customer_sync->register_hooks( $this->loader );
                $this->order_sync->register_hooks( $this->loader );

        }

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Softone_Woocommerce_Integration_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new Softone_Woocommerce_Integration_i18n();

		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );

	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {

                $plugin_admin = new Softone_Woocommerce_Integration_Admin( $this->get_plugin_name(), $this->get_version(), $this->item_sync, $this->activity_logger );

                $this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
                $this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );
                $this->loader->add_action( 'admin_menu', $plugin_admin, 'register_menu' );
                $this->loader->add_action( 'admin_init', $plugin_admin, 'register_settings' );
                $this->loader->add_action( 'admin_post_softone_wc_integration_api_tester', $plugin_admin, 'handle_api_tester_request' );
                $this->loader->add_action( 'admin_post_softone_wc_integration_test_connection', $plugin_admin, 'handle_test_connection' );
                $this->loader->add_action( 'admin_post_' . Softone_Item_Sync::ADMIN_ACTION, $plugin_admin, 'handle_item_import' );
                $this->loader->add_action( 'admin_post_softone_wc_integration_clear_sync_activity', $plugin_admin, 'handle_clear_sync_activity' );
                $this->loader->add_action( 'admin_post_' . $plugin_admin->get_delete_main_menu_action(), $plugin_admin, 'handle_delete_main_menu' );
                $this->loader->add_action( 'wp_ajax_' . $plugin_admin->get_sync_activity_action(), $plugin_admin, 'handle_sync_activity_ajax' );
                $this->loader->add_action( 'wp_ajax_' . $plugin_admin->get_process_trace_action(), $plugin_admin, 'handle_process_trace_ajax' );
                $this->loader->add_action( 'wp_ajax_' . $plugin_admin->get_item_import_ajax_action(), $plugin_admin, 'handle_item_import_ajax' );
                $this->loader->add_action( 'wp_ajax_' . $plugin_admin->get_delete_main_menu_ajax_action(), $plugin_admin, 'handle_delete_main_menu_ajax' );

        }

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {

		$plugin_public = new Softone_Woocommerce_Integration_Public( $this->get_plugin_name(), $this->get_version() );
		$menu_populator = new Softone_Menu_Populator( $this->activity_logger );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );
		$this->loader->add_filter( 'wp_nav_menu_objects', $menu_populator, 'filter_menu_items', 10, 2 );

	}

	/**
	 * Ensure the product brand taxonomy is registered.
	 *
	 * @return void
	 */
	public function maybe_register_brand_taxonomy() {
		if ( function_exists( 'taxonomy_exists' ) && taxonomy_exists( 'product_brand' ) ) {
			return;
		}

		if ( ! function_exists( 'register_taxonomy' ) ) {
			return;
		}

		$labels = array(
			'name'                       => _x( 'Brands', 'taxonomy general name', 'softone-woocommerce-integration' ),
			'singular_name'              => _x( 'Brand', 'taxonomy singular name', 'softone-woocommerce-integration' ),
			'search_items'               => __( 'Search Brands', 'softone-woocommerce-integration' ),
			'all_items'                  => __( 'All Brands', 'softone-woocommerce-integration' ),
			'parent_item'                => __( 'Parent Brand', 'softone-woocommerce-integration' ),
			'parent_item_colon'          => __( 'Parent Brand:', 'softone-woocommerce-integration' ),
			'edit_item'                  => __( 'Edit Brand', 'softone-woocommerce-integration' ),
			'update_item'                => __( 'Update Brand', 'softone-woocommerce-integration' ),
			'add_new_item'               => __( 'Add New Brand', 'softone-woocommerce-integration' ),
			'new_item_name'              => __( 'New Brand Name', 'softone-woocommerce-integration' ),
			'menu_name'                  => __( 'Brands', 'softone-woocommerce-integration' ),
		);

               $default_capabilities = array(
                       'manage_terms' => 'manage_product_terms',
                       'edit_terms'   => 'edit_product_terms',
                       'delete_terms' => 'delete_product_terms',
                       'assign_terms' => 'assign_product_terms',
               );

               $args = array(
                       'hierarchical'          => false,
                       'labels'                => $labels,
                       'show_ui'               => true,
                       'show_admin_column'     => true,
                       'query_var'             => true,
                       'rewrite'               => array( 'slug' => 'brand' ),
                       'show_in_rest'          => true,
                       'show_in_nav_menus'     => true,
                       'public'                => true,
                       'show_tagcloud'         => false,
                       'capabilities'          => $default_capabilities,
                );

		$objects = apply_filters( 'softone_product_brand_taxonomy_objects', array( 'product' ) );
		if ( empty( $objects ) ) {
			$objects = array( 'product' );
		}

               $args = apply_filters( 'softone_product_brand_taxonomy_args', $args );

               $args['capabilities'] = wp_parse_args(
                       isset( $args['capabilities'] ) ? $args['capabilities'] : array(),
                       $default_capabilities
               );

               register_taxonomy( 'product_brand', $objects, $args );
       }

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Softone_Woocommerce_Integration_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

}
