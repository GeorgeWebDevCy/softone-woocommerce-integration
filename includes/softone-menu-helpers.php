<?php
/**
 * Helper functions for Softone navigation menu integration.
 *
 * @package Softone_Woocommerce_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'softone_wc_integration_get_main_menu_name' ) ) {
	/**
	 * Retrieve the navigation menu name targeted by the integration.
	 *
	 * Provides a filter so site owners can override the default "Main Menu" label
	 * while ensuring an empty or non-string value gracefully falls back to the
	 * original default.
	 *
	 * @since 1.9.1
	 *
	 * @return string
	 */
	function softone_wc_integration_get_main_menu_name() {
		$default_name = 'Main Menu';

		if ( function_exists( 'apply_filters' ) ) {
			$menu_name = apply_filters( 'softone_wc_integration_main_menu_name', $default_name );
		} else {
			$menu_name = $default_name;
		}

		if ( ! is_string( $menu_name ) ) {
			return $default_name;
		}

		$menu_name = trim( $menu_name );

		if ( '' === $menu_name ) {
			return $default_name;
		}

		return $menu_name;
	}
}
