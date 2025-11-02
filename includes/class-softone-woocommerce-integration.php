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
                        $this->version = '1.8.25';
                }
		$this->plugin_name = 'softone-woocommerce-integration';

                $this->load_dependencies();
                $this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();

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

                $this->loader        = new Softone_Woocommerce_Integration_Loader();
                $this->item_sync     = new Softone_Item_Sync();
                $this->customer_sync = new Softone_Customer_Sync();
                $this->order_sync    = new Softone_Order_Sync( null, $this->customer_sync );

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
	
        $plugin_admin = new Softone_Woocommerce_Integration_Admin( $this->get_plugin_name(), $this->get_version(), $this->item_sync );

        $this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
        $this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );
        $this->loader->add_action( 'admin_menu', $plugin_admin, 'register_menu' );
        $this->loader->add_action( 'admin_init', $plugin_admin, 'register_settings' );
        $this->loader->add_action( 'admin_post_softone_wc_integration_api_tester', $plugin_admin, 'handle_api_tester_request' );
        $this->loader->add_action( 'admin_post_softone_wc_integration_test_connection', $plugin_admin, 'handle_test_connection' );
        $this->loader->add_action( 'admin_post_' . Softone_Item_Sync::ADMIN_ACTION, $plugin_admin, 'handle_item_import' );

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
                $menu_populator = new Softone_Menu_Populator();

                $this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
                $this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );
                $this->loader->add_filter( 'wp_nav_menu_objects', $menu_populator, 'filter_menu_items', 10, 2 );

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
