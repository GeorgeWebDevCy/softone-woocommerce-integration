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
         * Capability required to manage plugin settings.
         *
         * @var string
         */
        private $capability = 'manage_options';

        /**
         * Base transient key for connection test notices.
         *
         * @var string
         */
        private $test_notice_transient = 'softone_wc_integration_test_notice_';

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
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param string $plugin_name The name of this plugin.
	 * @param string $version     The version of this plugin.
	 */
        public function __construct( $plugin_name, $version, Softone_Item_Sync $item_sync ) {

                $this->plugin_name = $plugin_name;
                $this->version     = $version;
                $this->item_sync   = $item_sync;

        }

	/**
	 * Register the plugin submenu.
	 */
	public function register_menu() {

		if ( ! current_user_can( $this->capability ) ) {
			return;
		}

		$parent_slug = 'woocommerce';

		add_submenu_page(
			$parent_slug,
			__( 'Softone Integration', 'softone-woocommerce-integration' ),
			__( 'Softone Integration', 'softone-woocommerce-integration' ),
			$this->capability,
			$this->menu_slug,
			array( $this, 'render_settings_page' )
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

		$sanitized['endpoint']              = isset( $settings['endpoint'] ) ? esc_url_raw( trim( (string) $settings['endpoint'] ) ) : '';
		$sanitized['username']              = isset( $settings['username'] ) ? $this->sanitize_text_value( $settings['username'] ) : '';
		$sanitized['password']              = isset( $settings['password'] ) ? $this->sanitize_text_value( $settings['password'] ) : '';
		$sanitized['app_id']                = isset( $settings['app_id'] ) ? $this->sanitize_text_value( $settings['app_id'] ) : '';
		$sanitized['company']               = isset( $settings['company'] ) ? $this->sanitize_text_value( $settings['company'] ) : '';
		$sanitized['branch']                = isset( $settings['branch'] ) ? $this->sanitize_text_value( $settings['branch'] ) : '';
		$sanitized['module']                = isset( $settings['module'] ) ? $this->sanitize_text_value( $settings['module'] ) : '';
		$sanitized['refid']                 = isset( $settings['refid'] ) ? $this->sanitize_text_value( $settings['refid'] ) : '';
		$sanitized['default_saldoc_series'] = isset( $settings['default_saldoc_series'] ) ? $this->sanitize_text_value( $settings['default_saldoc_series'] ) : '';
		$sanitized['warehouse']             = isset( $settings['warehouse'] ) ? $this->sanitize_text_value( $settings['warehouse'] ) : '';

		return $sanitized;

	}

	/**
	 * Render section introduction text.
	 */
	public function render_settings_section_intro() {

		echo '<p>' . esc_html__( 'Configure the credentials used to communicate with Softone.', 'softone-woocommerce-integration' ) . '</p>';

	}

	/**
	 * Display the plugin settings page.
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
</div>
<?php

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

		try {
			$client = new Softone_API_Client();
			$client->get_client_id( true );
			$message = __( 'Successfully connected to Softone.', 'softone-woocommerce-integration' );
		} catch ( Softone_API_Client_Exception $exception ) {
			$type    = 'error';
			$message = $exception->getMessage();
		} catch ( Exception $exception ) {
			$type    = 'error';
			$message = $exception->getMessage();
		}

		$this->store_test_notice( $type, $message );

                wp_safe_redirect( $this->get_settings_page_url() );
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

                $type    = 'success';
                $message = '';

                if ( ! $this->item_sync instanceof Softone_Item_Sync ) {
                        $type    = 'error';
                        $message = __( 'Item import service is unavailable.', 'softone-woocommerce-integration' );
                } else {
                        try {
                                $result  = $this->item_sync->sync();
                                $message = sprintf(
                                        /* translators: 1: processed count, 2: created count, 3: updated count, 4: skipped count */
                                        __( 'Imported %1$d items (%2$d created, %3$d updated, %4$d skipped).', 'softone-woocommerce-integration' ),
                                        (int) $result['processed'],
                                        (int) $result['created'],
                                        (int) $result['updated'],
                                        (int) $result['skipped']
                                );
                                update_option( Softone_Item_Sync::OPTION_LAST_RUN, time() );
                        } catch ( Softone_API_Client_Exception $exception ) {
                                $type    = 'error';
                                $message = $exception->getMessage();
                        } catch ( Exception $exception ) {
                                $type    = 'error';
                                $message = $exception->getMessage();
                        }
                }

                $this->store_import_notice( $type, $message );

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

		$type    = isset( $notice['type'] ) && 'error' === $notice['type'] ? 'error' : 'success';
		$classes = array( 'notice', 'notice-' . $type );

		printf(
			'<div class="%1$s"><p>%2$s</p></div>',
			esc_attr( implode( ' ', $classes ) ),
			esc_html( $notice['message'] )
		);

        }

        /**
         * Store a connection test notice for the current user.
         *
	 * @param string $type    Notice type.
	 * @param string $message Notice message.
	 */
	private function store_test_notice( $type, $message ) {

		set_transient(
			$this->get_test_notice_key(),
			array(
				'type'    => $type,
				'message' => $message,
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
	 * Render a generic text field.
	 *
	 * @param array $args Field arguments.
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
	 * Sanitize a generic text value.
	 *
	 * @param string $value Raw input value.
	 *
	 * @return string
	 */
	private function sanitize_text_value( $value ) {

		return sanitize_text_field( wp_unslash( $value ) );

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

	}

}

