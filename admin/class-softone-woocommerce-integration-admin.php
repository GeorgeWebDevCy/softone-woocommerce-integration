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
         * API tester submenu slug.
         *
         * @var string
         */
	private $api_tester_slug = 'softone-woocommerce-integration-api-tester';

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

		$endpoint = isset( $settings['endpoint'] ) ? esc_url_raw( trim( (string) $settings['endpoint'] ) ) : '';

		if ( '' !== $endpoint && false === strpos( $endpoint, '?' ) ) {
			$endpoint = untrailingslashit( $endpoint );
		}

		$sanitized['endpoint']              = $endpoint;
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
         * Render the API tester page.
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


?>
<div class="wrap">
<h1><?php esc_html_e( 'Softone API Tester', 'softone-woocommerce-integration' ); ?></h1>

<?php if ( ! empty( $result ) && ! empty( $result['message'] ) ) : ?>
<?php
$status = ( isset( $result['status'] ) && 'error' === $result['status'] ) ? 'error' : 'success';
$classes = array( 'notice', 'notice-' . $status );
?>
<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
<p><?php echo esc_html( $result['message'] ); ?></p>
</div>

<?php if ( isset( $result['service'] ) || isset( $result['request'] ) || isset( $result['response'] ) ) : ?>
<div class="postbox" style="padding: 16px; margin-top: 16px;">
<?php if ( ! empty( $result['service'] ) ) : ?>
<h2><?php echo esc_html( sprintf( __( 'Service: %s', 'softone-woocommerce-integration' ), (string) $result['service'] ) ); ?></h2>
<?php endif; ?>
<?php if ( array_key_exists( 'request', $result ) ) : ?>
<h3><?php esc_html_e( 'Request Payload', 'softone-woocommerce-integration' ); ?></h3>
<pre><?php echo esc_html( $this->format_api_tester_output( $result['request'] ) ); ?></pre>
<?php endif; ?>
<?php if ( array_key_exists( 'response', $result ) ) : ?>
<h3><?php esc_html_e( 'Response', 'softone-woocommerce-integration' ); ?></h3>
<pre><?php echo esc_html( $this->format_api_tester_output( $result['response'] ) ); ?></pre>
<?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin-top: 1.5em;">
<?php wp_nonce_field( $this->api_tester_action ); ?>
<input type="hidden" name="action" value="<?php echo esc_attr( $this->api_tester_action ); ?>" />
<table class="form-table" role="presentation">
<tbody>
<tr>
<th scope="row"><label for="softone_api_preset"><?php esc_html_e( 'Preset', 'softone-woocommerce-integration' ); ?></label></th>
<td>
<select name="softone_preset" id="softone_api_preset">
<option value="" <?php selected( $form_data['preset'], '' ); ?>><?php esc_html_e( 'Manual selection', 'softone-woocommerce-integration' ); ?></option>
<?php foreach ( $presets as $preset_key => $preset_config ) : ?>
<option value="<?php echo esc_attr( $preset_key ); ?>" <?php selected( $form_data['preset'], $preset_key ); ?>><?php echo esc_html( $preset_config['label'] ); ?></option>
<?php endforeach; ?>
</select>
<p class="description" id="softone_api_preset_description" data-default-description="<?php echo esc_attr( $default_preset_description ); ?>"><?php echo esc_html( $preset_description ); ?></p>
</td>
</tr>
<tr>
<th scope="row"><label for="softone_service_type"><?php esc_html_e( 'Service', 'softone-woocommerce-integration' ); ?></label></th>
<td>
<select name="softone_service_type" id="softone_service_type">
<option value="sql_data" <?php selected( $form_data['service_type'], 'sql_data' ); ?>><?php esc_html_e( 'SqlData', 'softone-woocommerce-integration' ); ?></option>
<option value="set_data" <?php selected( $form_data['service_type'], 'set_data' ); ?>><?php esc_html_e( 'setData', 'softone-woocommerce-integration' ); ?></option>
<option value="custom" <?php selected( $form_data['service_type'], 'custom' ); ?>><?php esc_html_e( 'Custom', 'softone-woocommerce-integration' ); ?></option>
</select>
<p class="description"><?php esc_html_e( 'Choose a Softone service to call.', 'softone-woocommerce-integration' ); ?></p>
</td>
</tr>
<tr>
<th scope="row"><label for="softone_sql_name"><?php esc_html_e( 'SqlData name', 'softone-woocommerce-integration' ); ?></label></th>
<td>
<input type="text" class="regular-text" id="softone_sql_name" name="softone_sql_name" value="<?php echo esc_attr( $form_data['sql_name'] ); ?>" />
<p class="description"><?php esc_html_e( 'Required when calling SqlData.', 'softone-woocommerce-integration' ); ?></p>
</td>
</tr>
<tr>
<th scope="row"><label for="softone_object"><?php esc_html_e( 'setData object', 'softone-woocommerce-integration' ); ?></label></th>
<td>
<input type="text" class="regular-text" id="softone_object" name="softone_object" value="<?php echo esc_attr( $form_data['object'] ); ?>" />
<p class="description"><?php esc_html_e( 'Required when calling setData.', 'softone-woocommerce-integration' ); ?></p>
</td>
</tr>
<tr>
<th scope="row"><label for="softone_custom_service"><?php esc_html_e( 'Custom service name', 'softone-woocommerce-integration' ); ?></label></th>
<td>
<input type="text" class="regular-text" id="softone_custom_service" name="softone_custom_service" value="<?php echo esc_attr( $form_data['custom_service'] ); ?>" />
<p class="description"><?php esc_html_e( 'Specify the Softone service when using the Custom option.', 'softone-woocommerce-integration' ); ?></p>
</td>
</tr>
<tr>
<th scope="row"><?php esc_html_e( 'Requires client ID', 'softone-woocommerce-integration' ); ?></th>
<td>
<label for="softone_requires_client_id">
<input type="checkbox" id="softone_requires_client_id" name="softone_requires_client_id" value="1" <?php checked( $form_data['requires_client_id'] ); ?> />
<?php esc_html_e( 'Include the cached client ID for this request.', 'softone-woocommerce-integration' ); ?>
</label>
</td>
</tr>
<tr>
<th scope="row"><label for="softone_payload"><?php esc_html_e( 'JSON payload', 'softone-woocommerce-integration' ); ?></label></th>
<td>
<textarea class="large-text code" rows="10" id="softone_payload" name="softone_payload"><?php echo esc_textarea( $form_data['payload'] ); ?></textarea>
<p class="description"><?php esc_html_e( 'Provide additional parameters as JSON. The data will be merged with the base payload for the selected service.', 'softone-woocommerce-integration' ); ?></p>
</td>
</tr>
</tbody>
</table>
<?php submit_button( __( 'Send Request', 'softone-woocommerce-integration' ) ); ?>
</form>
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
                                               'message' => sprintf( __( 'Invalid JSON payload: %s', 'softone-woocommerce-integration' ), json_last_error_msg() ),
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
                                               'message' => __( 'JSON payload must decode to an array or object.', 'softone-woocommerce-integration' ),
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
                                                       'message' => __( 'A setData object name is required.', 'softone-woocommerce-integration' ),
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
                                                       'message' => __( 'A custom service name is required.', 'softone-woocommerce-integration' ),
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
                                                       'message' => __( 'A SqlData name is required.', 'softone-woocommerce-integration' ),
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
				__( 'SoftOne connection failed: %s', 'softone-woocommerce-integration' ),
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
				__( 'SoftOne connection failed: %s', 'softone-woocommerce-integration' ),
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

