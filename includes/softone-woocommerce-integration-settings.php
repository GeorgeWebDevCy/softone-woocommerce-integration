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
    function softone_wc_integration_get_endpoint() {
        return (string) softone_wc_integration_get_setting( 'endpoint', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_username' ) ) {
    function softone_wc_integration_get_username() {
        return (string) softone_wc_integration_get_setting( 'username', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_password' ) ) {
    function softone_wc_integration_get_password() {
        return (string) softone_wc_integration_get_setting( 'password', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_app_id' ) ) {
    function softone_wc_integration_get_app_id() {
        return (string) softone_wc_integration_get_setting( 'app_id', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_company' ) ) {
    function softone_wc_integration_get_company() {
        return (string) softone_wc_integration_get_setting( 'company', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_branch' ) ) {
    function softone_wc_integration_get_branch() {
        return (string) softone_wc_integration_get_setting( 'branch', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_module' ) ) {
    function softone_wc_integration_get_module() {
        return (string) softone_wc_integration_get_setting( 'module', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_refid' ) ) {
    function softone_wc_integration_get_refid() {
        return (string) softone_wc_integration_get_setting( 'refid', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_default_saldoc_series' ) ) {
    function softone_wc_integration_get_default_saldoc_series() {
        return (string) softone_wc_integration_get_setting( 'default_saldoc_series', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_warehouse' ) ) {
    function softone_wc_integration_get_warehouse() {
        return (string) softone_wc_integration_get_setting( 'warehouse', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_areas' ) ) {
    function softone_wc_integration_get_areas() {
        return (string) softone_wc_integration_get_setting( 'areas', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_socurrency' ) ) {
    function softone_wc_integration_get_socurrency() {
        return (string) softone_wc_integration_get_setting( 'socurrency', '' );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_trdcategory' ) ) {
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
