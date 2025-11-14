<?php

/**
 * Shared hooks for the Softone WooCommerce Integration plugin.
 *
 * @package    Softone_Woocommerce_Integration
 * @subpackage Softone_Woocommerce_Integration\includes
 */
class Softone_Woocommerce_Integration_Shared {

	/**
	 * Register shared plugin hooks.
	 *
	 * @param Softone_Woocommerce_Integration_Loader $loader Loader instance.
	 *
	 * @return void
	 */
	public function register_hooks( Softone_Woocommerce_Integration_Loader $loader ) {
		$loader->add_action( 'init', $this, 'maybe_register_brand_taxonomy' );
		$loader->add_filter(
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
			'hierarchical'      => false,
			'labels'            => $labels,
			'show_ui'           => true,
			'show_admin_column' => true,
			'query_var'         => true,
			'rewrite'           => array( 'slug' => 'brand' ),
			'show_in_rest'      => true,
			'show_in_nav_menus' => true,
			'public'            => true,
			'show_tagcloud'     => false,
			'capabilities'      => $default_capabilities,
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
}
