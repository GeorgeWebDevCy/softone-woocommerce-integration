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
     * @return array
     */
    function softone_wc_integration_get_settings() {
        $stored = get_option( Softone_API_Client::OPTION_SETTINGS_KEY, array() );
        $stored = is_array( $stored ) ? $stored : array();

        $defaults = array(
            'endpoint'              => '',
            'username'              => '',
            'password'              => '',
            'app_id'                => '',
            'company'               => '',
            'branch'                => '',
            'module'                => '',
            'refid'                 => '',
            'default_saldoc_series' => '',
            'warehouse'             => '',
            'country_mappings'      => array(),
            'timeout'               => Softone_API_Client::DEFAULT_TIMEOUT,
            'client_id_ttl'         => Softone_API_Client::DEFAULT_CLIENT_ID_TTL,
        );

        $settings = wp_parse_args( $stored, $defaults );

        /**
         * Filter the plugin settings before they are returned.
         *
         * @param array $settings Plugin settings.
         */
        return apply_filters( 'softone_wc_integration_settings_raw', $settings );
    }
}

if ( ! function_exists( 'softone_wc_integration_get_setting' ) ) {
    /**
     * Retrieve a specific plugin setting value.
     *
     * @param string $key     Setting key.
     * @param mixed  $default Optional default value.
     *
     * @return mixed
     */
    function softone_wc_integration_get_setting( $key, $default = '' ) {
        $settings = softone_wc_integration_get_settings();

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

if ( ! function_exists( 'softone_wc_integration_get_country_mappings' ) ) {
    /**
     * Retrieve the configured ISO-to-SoftOne country mapping table.
     *
     * @return array<string,string>
     */
    function softone_wc_integration_get_country_mappings() {
        $mappings = softone_wc_integration_get_setting( 'country_mappings', array() );

        if ( ! is_array( $mappings ) ) {
            return array();
        }

        return $mappings;
    }
}
