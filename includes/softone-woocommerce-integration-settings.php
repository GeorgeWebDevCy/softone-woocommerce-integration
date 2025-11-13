<?php
/**
 * Helper functions for Softone WooCommerce Integration settings.
 *
 * @package    Softone_Woocommerce_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'softone_wc_integration_get_settings' ) ) {
    /**
     * Retrieve the plugin settings from the options table.
     *
     * @param bool $force_refresh Optional. Whether to bypass the cached result.
     *
     * @return array
     */
    function softone_wc_integration_get_settings( $force_refresh = false ) {
        static $cached_settings = null;

        if ( $force_refresh ) {
            $cached_settings = null;
        }

        if ( null !== $cached_settings ) {
            return $cached_settings;
        }

        $stored = get_option( Softone_API_Client::OPTION_SETTINGS_KEY, array() );
        $stored = is_array( $stored ) ? $stored : array();

        $defaults = array(
            'endpoint'              => Softone_API_Client::LEGACY_DEFAULT_ENDPOINT,
            'username'              => '',
            'password'              => '',
            'app_id'                => Softone_API_Client::LEGACY_DEFAULT_APP_ID,
            'company'               => Softone_API_Client::LEGACY_DEFAULT_COMPANY,
            'branch'                => Softone_API_Client::LEGACY_DEFAULT_BRANCH,
            'module'                => Softone_API_Client::LEGACY_DEFAULT_MODULE,
            'refid'                 => Softone_API_Client::LEGACY_DEFAULT_REFID,
            'default_saldoc_series' => '',
            'warehouse'             => '',
            'areas'                 => '',
            'socurrency'            => '',
            'trdcategory'           => '',
            'country_mappings'      => array(),
            'timeout'               => Softone_API_Client::DEFAULT_TIMEOUT,
            'client_id_ttl'         => Softone_API_Client::DEFAULT_CLIENT_ID_TTL,
            'zero_stock_quantity_fallback'    => 'no',
            'backorder_out_of_stock_products' => 'no',
            'enable_variable_product_handling' => 'no',
        );

        $settings = wp_parse_args( $stored, $defaults );

        foreach ( softone_wc_integration_get_legacy_defaults() as $key => $value ) {
            if ( '' === softone_wc_integration_normalize_setting_value( $settings, $key ) ) {
                $settings[ $key ] = $value;
            }
        }

        /**
         * Filter the plugin settings before they are returned.
         *
         * @param array $settings Plugin settings.
         */
        $cached_settings = apply_filters( 'softone_wc_integration_settings_raw', $settings );

        return $cached_settings;
    }
}

if ( ! function_exists( 'softone_wc_integration_flush_settings_cache' ) ) {
    /**
     * Clear the cached plugin settings.
     *
     * @return void
     */
    function softone_wc_integration_flush_settings_cache() {
        softone_wc_integration_get_settings( true );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_legacy_defaults' ) ) {
    /**
     * Retrieve the legacy PT Kids connection defaults.
     *
     * @return array<string,string>
     */
    function softone_wc_integration_get_legacy_defaults() {
        return array(
            'endpoint' => Softone_API_Client::LEGACY_DEFAULT_ENDPOINT,
            'app_id'   => Softone_API_Client::LEGACY_DEFAULT_APP_ID,
            'company'  => Softone_API_Client::LEGACY_DEFAULT_COMPANY,
            'branch'   => Softone_API_Client::LEGACY_DEFAULT_BRANCH,
            'module'   => Softone_API_Client::LEGACY_DEFAULT_MODULE,
            'refid'    => Softone_API_Client::LEGACY_DEFAULT_REFID,
        );
    }
}

if ( ! function_exists( 'softone_wc_integration_normalize_setting_value' ) ) {
    /**
     * Normalise a stored setting value when checking for fallbacks.
     *
     * @param array  $settings Settings array.
     * @param string $key      Setting key.
     *
     * @return string
     */
    function softone_wc_integration_normalize_setting_value( array $settings, $key ) {
        if ( ! array_key_exists( $key, $settings ) ) {
            return '';
        }

        $value = $settings[ $key ];

        if ( is_string( $value ) ) {
            return trim( $value );
        }

        if ( null === $value ) {
            return '';
        }

        return trim( (string) $value );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_setting' ) ) {
    /**
     * Retrieve a specific plugin setting value.
     *
     * @param string $key           Setting key.
     * @param mixed  $default       Optional default value.
     * @param bool   $force_refresh Optional. Whether to bypass the cached result.
     *
     * @return mixed
     */
    function softone_wc_integration_get_setting( $key, $default = '', $force_refresh = false ) {
        $settings = softone_wc_integration_get_settings( $force_refresh );

        if ( isset( $settings[ $key ] ) ) {
            return $settings[ $key ];
        }

        return $default;
    }
}

if ( ! function_exists( 'softone_wc_integration_get_endpoint' ) ) {
    /**
     * Retrieve the configured SoftOne API endpoint URL.
     *
     * The endpoint determines the base URL used for every request the integration issues
     * to the SoftOne backend. Centralising the lookup behind this helper keeps service
     * classes decoupled from WordPress' Options API and makes it obvious where the value
     * originates from when debugging requests.
     *
     * @return string Fully qualified endpoint URL or an empty string when not configured.
     */
    function softone_wc_integration_get_endpoint() {
        return (string) softone_wc_integration_get_setting( 'endpoint', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_username' ) ) {
    /**
     * Retrieve the SoftOne username used for authentication.
     *
     * The username is stored alongside the rest of the connection settings and is required
     * when performing login and authentication calls. Exposing it through a dedicated
     * accessor ensures that authentication routines do not have to deal with low-level
     * option fetching or fallbacks.
     *
     * @return string Configured SoftOne username, or an empty string if none is saved.
     */
    function softone_wc_integration_get_username() {
        return (string) softone_wc_integration_get_setting( 'username', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_password' ) ) {
    /**
     * Retrieve the SoftOne password used for authentication.
     *
     * Although the password is stored as plain text within the options table, routing all
     * reads through this helper makes it easy to replace the storage mechanism in the
     * future (for example with an encrypted alternative) without touching every API call.
     *
     * @return string Configured SoftOne password, or an empty string if none is available.
     */
    function softone_wc_integration_get_password() {
        return (string) softone_wc_integration_get_setting( 'password', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_app_id' ) ) {
    /**
     * Retrieve the SoftOne application identifier (appId).
     *
     * Some SoftOne environments require the application identifier to be provided with
     * each request. By surfacing the value via a dedicated helper we keep consumers from
     * relying on magic array keys or duplicating fallback logic.
     *
     * @return string Application identifier defined in the plugin settings.
     */
    function softone_wc_integration_get_app_id() {
        return (string) softone_wc_integration_get_setting( 'app_id', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_company' ) ) {
    /**
     * Retrieve the SoftOne company identifier.
     *
     * The company (or "company id") is required for authenticate and setData calls so
     * that SoftOne can route the request to the correct tenant. Having a well documented
     * accessor reduces the risk of developers using the wrong option key when assembling
     * payloads.
     *
     * @return string Company identifier stored in the plugin settings.
     */
    function softone_wc_integration_get_company() {
        return (string) softone_wc_integration_get_setting( 'company', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_branch' ) ) {
    /**
     * Retrieve the SoftOne branch identifier used during authentication.
     *
     * Branch values are required by many SoftOne installations to scope inventory and
     * sales document operations. Exposing the value via this helper keeps the behaviour
     * consistent throughout the codebase.
     *
     * @return string Configured SoftOne branch identifier.
     */
    function softone_wc_integration_get_branch() {
        return (string) softone_wc_integration_get_setting( 'branch', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_module' ) ) {
    /**
     * Retrieve the SoftOne module identifier.
     *
     * Certain SoftOne services expect a module (such as "0" for commercial management)
     * to be present in the authenticate payload. Centralising access to the value keeps
     * those payload builders concise and easier to audit.
     *
     * @return string Module identifier defined in the plugin settings.
     */
    function softone_wc_integration_get_module() {
        return (string) softone_wc_integration_get_setting( 'module', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_refid' ) ) {
    /**
     * Retrieve the SoftOne reference identifier (refId).
     *
     * The reference identifier links API calls to a specific integration record inside
     * SoftOne. This helper documents the intent clearly so that other components do not
     * need to guess what the `refid` option represents.
     *
     * @return string Reference identifier saved in the plugin settings.
     */
    function softone_wc_integration_get_refid() {
        return (string) softone_wc_integration_get_setting( 'refid', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_default_saldoc_series' ) ) {
    /**
     * Retrieve the default SoftOne SALDOC series code.
     *
     * When creating sales documents the integration falls back to this series whenever
     * a more specific mapping is not available. Documenting the helper clarifies why the
     * value matters and where it comes from.
     *
     * @return string Configured SALDOC series or an empty string when not set.
     */
    function softone_wc_integration_get_default_saldoc_series() {
        return (string) softone_wc_integration_get_setting( 'default_saldoc_series', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_warehouse' ) ) {
    /**
     * Retrieve the default warehouse code used for stock synchronisation.
     *
     * SoftOne installations often expose multiple warehouses. The integration needs to
     * know which warehouse to reference when adjusting quantities or exporting orders,
     * so we keep the lookup in a single documented location.
     *
     * @return string Warehouse identifier chosen in the plugin settings.
     */
    function softone_wc_integration_get_warehouse() {
        return (string) softone_wc_integration_get_setting( 'warehouse', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_areas' ) ) {
    /**
     * Retrieve the default customer area identifier.
     *
     * Customer synchronisation relies on the area to categorise new records in SoftOne.
     * Centralising the accessor makes the dependency explicit and avoids "magic" strings
     * throughout the sync logic.
     *
     * @return string Customer area identifier or empty string when unset.
     */
    function softone_wc_integration_get_areas() {
        return (string) softone_wc_integration_get_setting( 'areas', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_socurrency' ) ) {
    /**
     * Retrieve the default SoftOne currency code for customer records.
     *
     * The currency influences how newly created customers are configured within SoftOne
     * and therefore needs to be available to the customer sync routines in a predictable
     * way.
     *
     * @return string Currency code as stored in the plugin settings.
     */
    function softone_wc_integration_get_socurrency() {
        return (string) softone_wc_integration_get_setting( 'socurrency', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_trdcategory' ) ) {
    /**
     * Retrieve the default SoftOne trading category (TRDCATEGORY).
     *
     * Customer synchronisation assigns this category whenever a WooCommerce customer is
     * exported without a more specific mapping. Documenting the helper highlights the
     * relationship between the option and the resulting SoftOne records.
     *
     * @return string Trading category value configured for the integration.
     */
    function softone_wc_integration_get_trdcategory() {
        return (string) softone_wc_integration_get_setting( 'trdcategory', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_should_force_minimum_stock' ) ) {
    /**
     * Determine whether zero stock values should be converted to one during sync.
     *
     * @return bool
     */
    function softone_wc_integration_should_force_minimum_stock() {
        return 'yes' === softone_wc_integration_get_setting( 'zero_stock_quantity_fallback', 'no' );
    }
}

if ( ! function_exists( 'softone_wc_integration_should_backorder_out_of_stock' ) ) {
    /**
     * Determine whether out of stock products should be marked as available on backorder.
     *
     * @return bool
     */
    function softone_wc_integration_should_backorder_out_of_stock() {
        return 'yes' === softone_wc_integration_get_setting( 'backorder_out_of_stock_products', 'no' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_country_mappings' ) ) {
    /**
     * Retrieve the configured ISO-to-SoftOne country mapping table.
     *
     * @param bool $force_refresh Optional. Whether to bypass the cached result.
     *
     * @return array<string,string>
     */
    function softone_wc_integration_get_country_mappings( $force_refresh = false ) {
        $mappings = softone_wc_integration_get_setting( 'country_mappings', array(), $force_refresh );

        if ( ! is_array( $mappings ) ) {
            return array();
        }

        return $mappings;
    }
}

add_action( 'add_option_' . Softone_API_Client::OPTION_SETTINGS_KEY, 'softone_wc_integration_flush_settings_cache', 10, 0 );
add_action( 'update_option_' . Softone_API_Client::OPTION_SETTINGS_KEY, 'softone_wc_integration_flush_settings_cache', 10, 0 );
add_action( 'delete_option_' . Softone_API_Client::OPTION_SETTINGS_KEY, 'softone_wc_integration_flush_settings_cache', 10, 0 );
