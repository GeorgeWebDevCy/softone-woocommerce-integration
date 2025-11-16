<?php
/**
 * Helper for dynamically populating the navigation menu with Softone data.
 *
 * @package Softone_Woocommerce_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	 exit;
}

if ( ! function_exists( 'softone_wc_integration_get_main_menu_name' ) ) {
	 require_once __DIR__ . '/softone-menu-helpers.php';
}

if ( ! class_exists( 'Softone_Sync_Activity_Logger' ) ) {
	 require_once __DIR__ . '/class-softone-sync-activity-logger.php';
}

/**
 * Populates the public navigation menu with product brands and categories.
 */
class Softone_Menu_Populator {

	 /**
	  * File-based activity logger.
	  *
	  * @var Softone_Sync_Activity_Logger|null
	  */
	 private $activity_logger;

	 /**
	  * Cache for product brand terms.
	  *
	  * @var array<int, WP_Term>|array<int, object>|false|null
	  */
	 private $brand_terms = null;

/**
 * Cache for grouped product category terms keyed by parent term ID.
 *
 * @var array<int, array<int, WP_Term|object>>|false|null
 */
private $category_terms = null;

/**
 * Configuration for locating placeholder menu items.
 *
 * @var array<string, array<string, mixed>>|null
 */
private $placeholder_config = null;

/**
 * Track menus already processed during the current request.
 *
 * @var array<string, bool>
 */
private $processed_menus = array();

	 /**
	  * Counter for generating temporary menu item IDs.
	  *
	  * @var int
	  */
	 private $id_counter = 0;

	 /**
	  * Constructor.
	  *
	  * @param Softone_Sync_Activity_Logger|null $activity_logger Optional activity logger instance.
	  */
	 public function __construct( ?Softone_Sync_Activity_Logger $activity_logger = null ) {
	         if ( $activity_logger && method_exists( $activity_logger, 'log' ) ) {
	                 $this->activity_logger = $activity_logger;
	         }
	 }

	 /**
	  * Filter callback that injects product brands and categories into the navigation menu.
	  *
	  * @param array<int, WP_Post|object> $menu_items Existing menu items.
	  * @param stdClass|array             $args       Menu arguments.
	  *
	  * @return array<int, WP_Post|object>
	  */
	 public function filter_menu_items( $menu_items, $args ) {
	         if ( ! is_array( $menu_items ) || empty( $menu_items ) ) {
	                 return $menu_items;
	         }

	         if ( ! $this->is_main_menu( $args ) ) {
	                 return $menu_items;
	         }

	         $menu_name            = $this->get_menu_name( $args );

		if ( '' !== $menu_name && $this->has_processed_menu( $menu_name ) ) {
			return $menu_items;
		}

	         $menu_items = $this->strip_dynamic_items( $menu_items );

	         $brand_items_added    = 0;
	         $category_items_added = 0;

$brands_menu_item   = $this->find_placeholder_item( $menu_items, 'brands' );
$products_menu_item = $this->find_placeholder_item( $menu_items, 'products' );

	         $brand_terms     = $this->get_brand_terms();
	         $category_groups = $this->get_category_terms();

	         if ( false === $brand_terms && false === $category_groups ) {
	                 return $menu_items;
	         }

	         if ( false === $brand_terms ) {
	                 $brand_terms = array();
	         }

	         if ( false === $category_groups ) {
	                 $category_groups = array();
	         }

	         if ( empty( $brand_terms ) && empty( $category_groups ) ) {
	                 return $menu_items;
	         }

	         $max_menu_order = $this->get_max_menu_order( $menu_items );

	         if ( $brands_menu_item && ! empty( $brand_terms ) ) {
	                 foreach ( $brand_terms as $term ) {
	                         $new_item = $this->create_menu_item_from_term( $term, $brands_menu_item, ++$max_menu_order );

	                         if ( $new_item ) {
	                                 $menu_items[] = $new_item;
	                                 $brand_items_added++;
	                         }
	                 }
	         }

	         if ( $products_menu_item && ! empty( $category_groups ) ) {
	                 list( $menu_items, $max_menu_order, $category_items_added ) =
	                         $this->append_category_items( $menu_items, $products_menu_item, $category_groups, $max_menu_order );
	         }

	         if ( $brand_items_added > 0 || $category_items_added > 0 ) {
	                 $this->log_activity(
	                         'dynamic_items_added',
	                         __( 'Injected Softone menu items into the navigation.', 'softone-woocommerce-integration' ),
	                         array(
	                                 'menu_name'              => $menu_name,
	                                 'brand_items_added'      => $brand_items_added,
	                                 'category_items_added'   => $category_items_added,
	                                 'brand_terms_available'  => is_array( $brand_terms ) ? count( $brand_terms ) : 0,
	                                 'category_groups_source' => is_array( $category_groups ) ? count( $category_groups ) : 0,
	                         )
	                 );
	         }

	         return $menu_items;
	 }

	/**
	 * Filter callback that mirrors front-end menu injection inside wp-admin.
	 *
	 * @param array<int, WP_Post|object> $items Menu items retrieved via wp_get_nav_menu_items().
	 * @param WP_Term|object|null        $menu  Menu object for the current screen.
	 * @param array<string, mixed>|object $args Original arguments passed to wp_get_nav_menu_items().
	 *
	 * @return array<int, WP_Post|object>
	 */
	public function filter_admin_menu_items( $items, $menu, $args ) {
		if ( ! is_admin() ) {
			return $items;
		}

		if ( ! is_array( $items ) || empty( $items ) ) {
			return $items;
		}

		$normalised_args = $this->normalise_admin_menu_args( $menu, $args );

		return $this->filter_menu_items( $items, $normalised_args );
	}

	/**
	 * Merge admin menu context into a standard wp_nav_menu style argument object.
	 *
	 * @param WP_Term|object|null         $menu Menu term currently being edited.
	 * @param array<string, mixed>|object $args Original arguments from wp_get_nav_menu_items().
	 *
	 * @return array<string, mixed>|object
	 */
	private function normalise_admin_menu_args( $menu, $args ) {
		$menu_id = 0;

		if ( is_object( $menu ) && isset( $menu->term_id ) ) {
			$menu_id = (int) $menu->term_id;
		}

		if ( is_array( $args ) ) {
			$args['menu'] = $menu;

			if ( $menu_id && ! isset( $args['menu_id'] ) ) {
				$args['menu_id'] = $menu_id;
			}

			return $args;
		}

		if ( ! is_object( $args ) ) {
			$args = new stdClass();
		}

		$args->menu = $menu;

		if ( $menu_id && ! isset( $args->menu_id ) ) {
			$args->menu_id = $menu_id;
		}

		return $args;
	}

	 /**
	  * Determine whether the current menu is the main menu.
	  *
	  * @param stdClass|array $args Menu arguments.
	  *
	  * @return bool
	  */
	 private function is_main_menu( $args ) {
	         return softone_wc_integration_get_main_menu_name() === $this->get_menu_name( $args );
	 }

	 /**
	  * Determine the current menu name based on filter arguments.
	  *
	  * @param stdClass|array $args Menu arguments.
	  *
	  * @return string
	  */
	 private function get_menu_name( $args ) {
	         $menu_name = '';

	         if ( is_object( $args ) && isset( $args->menu ) ) {
	                 if ( is_object( $args->menu ) && isset( $args->menu->name ) ) {
	                         $menu_name = (string) $args->menu->name;
	                 } elseif ( isset( $args->menu ) && function_exists( 'wp_get_nav_menu_object' ) ) {
	                         $menu_obj = wp_get_nav_menu_object( $args->menu );
	                         if ( $menu_obj && isset( $menu_obj->name ) ) {
	                                 $menu_name = (string) $menu_obj->name;
	                         }
	                 }
	         } elseif ( is_array( $args ) && isset( $args['menu'] ) ) {
	                 if ( is_object( $args['menu'] ) && isset( $args['menu']->name ) ) {
	                         $menu_name = (string) $args['menu']->name;
	                 } elseif ( function_exists( 'wp_get_nav_menu_object' ) ) {
	                         $menu_obj = wp_get_nav_menu_object( $args['menu'] );
	                         if ( $menu_obj && isset( $menu_obj->name ) ) {
	                                 $menu_name = (string) $menu_obj->name;
	                         }
	                 }
	         }

	         if ( '' === $menu_name && is_object( $args ) && isset( $args->menu_id ) && function_exists( 'wp_get_nav_menu_object' ) ) {
	                 $menu_obj = wp_get_nav_menu_object( (int) $args->menu_id );
	                 if ( $menu_obj && isset( $menu_obj->name ) ) {
	                         $menu_name = (string) $menu_obj->name;
	                 }
	         }

	         return $menu_name;
	 }

	 /**
	  * Remove previously generated dynamic menu items.
	  *
	  * @param array<int, WP_Post|object> $menu_items Menu items.
	  *
	  * @return array<int, WP_Post|object>
	  */
	 private function strip_dynamic_items( array $menu_items ) {
	         $filtered = array();

	         foreach ( $menu_items as $item ) {
	                 $classes = array();
	                 if ( isset( $item->classes ) ) {
	                         if ( is_array( $item->classes ) ) {
	                                 $classes = $item->classes;
	                         } elseif ( is_string( $item->classes ) ) {
	                                 $classes = array( $item->classes );
	                         }
	                 }

	                 if ( in_array( 'softone-dynamic-menu-item', $classes, true ) ) {
	                         continue;
	                 }

	                 $filtered[] = $item;
	         }

	         return $filtered;
	 }

	 /**
	  * Record menu building activity when a logger is available.
	  *
	  * @param string               $action  Action identifier.
	  * @param string               $message Human readable message.
	  * @param array<string, mixed> $context Context data.
	  *
	  * @return void
	  */
	 private function log_activity( $action, $message, array $context = array() ) {
	         if ( ! $this->activity_logger || ! method_exists( $this->activity_logger, 'log' ) ) {
	                 return;
	         }

	         $this->activity_logger->log( 'menu_build', $action, $message, $context );
	 }

	/**
	 * Determine whether the provided menu already received dynamic items this request.
	 *
	 * @param string $menu_name Menu name identifier.
	 *
	 * @return bool True when the menu has already been processed.
	 */
	private function has_processed_menu( $menu_name ) {
		if ( '' === $menu_name ) {
			return false;
		}

		if ( isset( $this->processed_menus[ $menu_name ] ) ) {
			return true;
		}

		$this->processed_menus[ $menu_name ] = true;

		return false;
	}

	/**
	 * Locate the placeholder menu item for a given dynamic item group.
	 *
	 * @param array<int, WP_Post|object> $menu_items Menu items.
	 * @param string                     $type       Placeholder type identifier.
	 *
	 * @return WP_Post|object|null
	 */
	private function find_placeholder_item( array $menu_items, $type ) {
	        $config = $this->get_placeholder_config();

	        if ( empty( $config[ $type ] ) || ! is_array( $config[ $type ] ) ) {
	                return null;
	        }

	        foreach ( $menu_items as $item ) {
	                if ( $this->matches_placeholder_definition( $item, $config[ $type ] ) ) {
	                        return $item;
	                }
	        }

	        return null;
	}

/**
 * Retrieve configuration for locating placeholder menu items.
 *
 * The defaults target menu items titled "Brands" and "Products" to preserve
 * backwards compatibility. Site owners can override these values using the
 * `softone_wc_integration_menu_placeholder_titles` filter, or provide a richer
 * configuration (including CSS classes and menu item meta matching rules) via
 * the `softone_wc_integration_menu_placeholder_config` filter.
 *
 * @return array<string, array<string, mixed>>
 */
	private function get_placeholder_config() {
	        if ( null !== $this->placeholder_config ) {
	                return $this->placeholder_config;
	        }

	        $default_titles = array(
	                'brands'   => array( 'Brands' ),
	                'products' => array( 'Products' ),
	        );

	        if ( function_exists( 'apply_filters' ) ) {
	                $filtered_titles = apply_filters( 'softone_wc_integration_menu_placeholder_titles', $default_titles );
	                $default_titles  = $this->merge_placeholder_titles( $default_titles, $filtered_titles );
	        }

	        $config = array(
	                'brands'   => array(
	                        'titles'  => $default_titles['brands'],
	                        'classes' => array(),
	                        'meta'    => array(),
	                ),
	                'products' => array(
	                        'titles'  => $default_titles['products'],
	                        'classes' => array(),
	                        'meta'    => array(),
	                ),
	        );

	        if ( function_exists( 'apply_filters' ) ) {
	                $filtered_config = apply_filters( 'softone_wc_integration_menu_placeholder_config', $config, $default_titles );
	                if ( is_array( $filtered_config ) ) {
	                        $config = $this->merge_placeholder_config( $config, $filtered_config );
	                }
	        }

	        $this->placeholder_config = $config;

	        return $this->placeholder_config;
	}

	/**
	 * Merge provided placeholder titles with defaults.
	 *
	 * @param array<string, array<int, string>> $defaults Default titles.
	 * @param mixed                             $provided Provided titles.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function merge_placeholder_titles( array $defaults, $provided ) {
	        if ( ! is_array( $provided ) ) {
	                return $defaults;
	        }

	        foreach ( array( 'brands', 'products' ) as $key ) {
	                if ( array_key_exists( $key, $provided ) ) {
	                        $titles = $this->normalise_strings( $provided[ $key ] );

	                        if ( ! empty( $titles ) || ( is_array( $provided[ $key ] ) && empty( $provided[ $key ] ) ) ) {
	                                $defaults[ $key ] = $titles;
	                        }
	                }
	        }

	        return $defaults;
	}

	/**
	 * Merge provided placeholder configuration with defaults.
	 *
	 * @param array<string, array<string, mixed>> $defaults Default configuration.
	 * @param array<string, array<string, mixed>> $provided Provided configuration.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function merge_placeholder_config( array $defaults, array $provided ) {
	        foreach ( $defaults as $type => $definition ) {
	                if ( ! isset( $provided[ $type ] ) || ! is_array( $provided[ $type ] ) ) {
	                        continue;
	                }

	                $current = $provided[ $type ];

	                if ( array_key_exists( 'titles', $current ) ) {
	                        $titles = $this->normalise_strings( $current['titles'] );

	                        if ( ! empty( $titles ) || ( is_array( $current['titles'] ) && empty( $current['titles'] ) ) ) {
	                                $defaults[ $type ]['titles'] = $titles;
	                        }
	                }

	                if ( array_key_exists( 'classes', $current ) ) {
	                        $classes = $this->normalise_class_names( $current['classes'] );

	                        if ( ! empty( $classes ) || ( is_array( $current['classes'] ) && empty( $current['classes'] ) ) ) {
	                                $defaults[ $type ]['classes'] = $classes;
	                        }
	                }

	                if ( array_key_exists( 'meta', $current ) ) {
	                        $defaults[ $type ]['meta'] = $this->normalise_meta_rules( $current['meta'] );
	                }
	        }

	        return $defaults;
	}

	/**
	 * Determine whether a menu item matches the provided placeholder definition.
	 *
	 * @param WP_Post|object          $item       Menu item.
	 * @param array<string, mixed>    $definition Placeholder definition.
	 *
	 * @return bool
	 */
	private function matches_placeholder_definition( $item, array $definition ) {
	        $titles = array();

	        if ( isset( $definition['titles'] ) ) {
	                $titles = $this->normalise_strings( $definition['titles'] );
	        }

	        $item_title = $this->get_menu_item_title( $item );

	        foreach ( $titles as $title ) {
	                if ( 0 === strcasecmp( $item_title, $title ) ) {
	                        return true;
	                }
	        }

	        $classes = array();

	        if ( isset( $definition['classes'] ) ) {
	                $classes = $this->normalise_class_names( $definition['classes'] );
	        }

	        if ( ! empty( $classes ) ) {
	                $item_classes = $this->extract_menu_item_classes( $item );

	                foreach ( $classes as $class ) {
	                        if ( in_array( $class, $item_classes, true ) ) {
	                                return true;
	                        }
	                }
	        }

	        $meta_rules = array();

	        if ( isset( $definition['meta'] ) ) {
	                $meta_rules = $this->normalise_meta_rules( $definition['meta'] );
	        }

	        if ( ! empty( $meta_rules ) && $this->menu_item_matches_meta_rules( $item, $meta_rules ) ) {
	                return true;
	        }

	        return false;
	}

	/**
	 * Extract a normalised list of class names from a menu item.
	 *
	 * @param WP_Post|object $item Menu item.
	 *
	 * @return array<int, string>
	 */
	private function extract_menu_item_classes( $item ) {
	        $classes = array();

	        if ( isset( $item->classes ) ) {
	                if ( is_array( $item->classes ) ) {
	                        $classes = $item->classes;
	                } elseif ( is_string( $item->classes ) ) {
	                        $classes = array( $item->classes );
	                }
	        }

	        $normalised = array();

	        foreach ( $classes as $class ) {
	                if ( ! is_string( $class ) ) {
	                        continue;
	                }

	                $class = strtolower( trim( $class ) );

	                if ( '' !== $class ) {
	                        $normalised[] = $class;
	                }
	        }

	        return $normalised;
	}

	/**
	 * Retrieve the display title for a menu item.
	 *
	 * @param WP_Post|object $item Menu item.
	 *
	 * @return string
	 */
	private function get_menu_item_title( $item ) {
	        if ( isset( $item->title ) && is_string( $item->title ) ) {
	                return $item->title;
	        }

	        if ( isset( $item->post_title ) && is_string( $item->post_title ) ) {
	                return $item->post_title;
	        }

	        return '';
	}

	/**
	 * Determine whether a menu item satisfies a metadata rule list.
	 *
	 * @param WP_Post|object               $item  Menu item.
	 * @param array<int, array<string, mixed>> $rules Metadata rules.
	 *
	 * @return bool
	 */
	private function menu_item_matches_meta_rules( $item, array $rules ) {
	        if ( empty( $rules ) || ! function_exists( 'get_post_meta' ) ) {
	                return false;
	        }

	        $item_id = 0;

	        if ( isset( $item->ID ) ) {
	                $item_id = (int) $item->ID;
	        } elseif ( isset( $item->db_id ) ) {
	                $item_id = (int) $item->db_id;
	        }

	        if ( $item_id <= 0 ) {
	                return false;
	        }

	        foreach ( $rules as $rule ) {
	                if ( empty( $rule['key'] ) || ! is_string( $rule['key'] ) ) {
	                        continue;
	                }

	                $meta_value = get_post_meta( $item_id, $rule['key'], true );

	                if ( array_key_exists( 'value', $rule ) ) {
	                        $expected = $rule['value'];

	                        if ( is_array( $expected ) ) {
	                                $expected_values = $expected;

	                                if ( is_scalar( $meta_value ) ) {
	                                        $meta_value = (string) $meta_value;
	                                }

	                                if ( is_string( $meta_value ) && in_array( $meta_value, $expected_values, true ) ) {
	                                        return true;
	                                }

	                                if ( is_array( $meta_value ) ) {
	                                        foreach ( $meta_value as $candidate ) {
	                                                if ( is_scalar( $candidate ) && in_array( (string) $candidate, $expected_values, true ) ) {
	                                                        return true;
	                                                }
	                                        }
	                                }
	                        } else {
	                                if ( is_scalar( $meta_value ) && (string) $meta_value === (string) $expected ) {
	                                        return true;
	                                }

	                                if ( is_array( $meta_value ) ) {
	                                        foreach ( $meta_value as $candidate ) {
	                                                if ( is_scalar( $candidate ) && (string) $candidate === (string) $expected ) {
	                                                        return true;
	                                                }
	                                        }
	                                }
	                        }
	                } else {
	                        if ( is_array( $meta_value ) ) {
	                                if ( ! empty( $meta_value ) ) {
	                                        return true;
	                                }
	                        } elseif ( is_scalar( $meta_value ) && '' !== (string) $meta_value ) {
	                                return true;
	                        }
	                }
	        }

	        return false;
	}

	/**
	 * Normalise a value into a list of trimmed strings.
	 *
	 * @param mixed $value Value to normalise.
	 *
	 * @return array<int, string>
	 */
	private function normalise_strings( $value ) {
	        if ( is_string( $value ) ) {
	                $value = array( $value );
	        }

	        if ( ! is_array( $value ) ) {
	                return array();
	        }

	        $strings = array();

	        foreach ( $value as $entry ) {
	                if ( ! is_string( $entry ) ) {
	                        continue;
	                }

	                $entry = trim( $entry );

	                if ( '' !== $entry ) {
	                        $strings[] = $entry;
	                }
	        }

	        return $strings;
	}

	/**
	 * Normalise a value into a list of class names.
	 *
	 * @param mixed $value Value to normalise.
	 *
	 * @return array<int, string>
	 */
	private function normalise_class_names( $value ) {
	        if ( is_string( $value ) ) {
	                $value = array( $value );
	        }

	        if ( ! is_array( $value ) ) {
	                return array();
	        }

	        $classes = array();

	        foreach ( $value as $entry ) {
	                if ( ! is_string( $entry ) ) {
	                        continue;
	                }

	                $entry = strtolower( trim( $entry ) );

	                if ( '' !== $entry ) {
	                        $classes[] = $entry;
	                }
	        }

	        return $classes;
	}

	/**
	 * Normalise metadata rules into a consistent structure.
	 *
	 * @param mixed $meta Meta rule configuration.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function normalise_meta_rules( $meta ) {
	        if ( is_array( $meta ) && isset( $meta['key'] ) ) {
	                $rule = $this->sanitise_meta_rule( $meta );

	                return $rule ? array( $rule ) : array();
	        }

	        if ( ! is_array( $meta ) ) {
	                return array();
	        }

	        $rules = array();

	        foreach ( $meta as $rule ) {
	                if ( ! is_array( $rule ) ) {
	                        continue;
	                }

	                $sanitised = $this->sanitise_meta_rule( $rule );

	                if ( $sanitised ) {
	                        $rules[] = $sanitised;
	                }
	        }

	        return $rules;
	}

	/**
	 * Sanitise a metadata rule configuration entry.
	 *
	 * @param array<string, mixed> $rule Metadata rule.
	 *
	 * @return array<string, mixed>|null
	 */
	private function sanitise_meta_rule( array $rule ) {
	        if ( empty( $rule['key'] ) || ! is_string( $rule['key'] ) ) {
	                return null;
	        }

	        $sanitised = array(
	                'key' => $rule['key'],
	        );

	        if ( array_key_exists( 'value', $rule ) ) {
	                $value = $rule['value'];

	                if ( is_array( $value ) ) {
	                        $values = array();

	                        foreach ( $value as $entry ) {
	                                if ( is_scalar( $entry ) ) {
	                                        $values[] = (string) $entry;
	                                }
	                        }

	                        $sanitised['value'] = $values;
	                } elseif ( is_scalar( $value ) ) {
	                        $sanitised['value'] = (string) $value;
	                } else {
	                        $sanitised['value'] = '';
	                }
	        }

	        return $sanitised;
	}

	 /**
	  * Retrieve cached product brand terms.
	  *
	  * @return array<int, WP_Term|object>|false
	  */
	 private function get_brand_terms() {
	         if ( null !== $this->brand_terms ) {
	                 return $this->brand_terms;
	         }

	         if ( ! function_exists( 'taxonomy_exists' ) || ! taxonomy_exists( 'product_brand' ) ) {
	                 $this->brand_terms = false;
	                 return $this->brand_terms;
	         }

	         if ( ! function_exists( 'get_terms' ) ) {
	                 $this->brand_terms = array();
	                 return $this->brand_terms;
	         }

	         $terms = get_terms(
	                 array(
	                         'taxonomy'   => 'product_brand',
	                         'hide_empty' => false,
	                         'orderby'    => 'name',
	                         'order'      => 'ASC',
	                 )
	         );

	         if ( $this->is_wp_error( $terms ) ) {
	                 $this->brand_terms = array();
	                 return $this->brand_terms;
	         }

	         if ( ! is_array( $terms ) ) {
	                 $this->brand_terms = array();
	                 return $this->brand_terms;
	         }

	         usort(
	                 $terms,
	                 static function ( $a, $b ) {
	                         $name_a = isset( $a->name ) ? (string) $a->name : '';
	                         $name_b = isset( $b->name ) ? (string) $b->name : '';

	                         return strcasecmp( $name_a, $name_b );
	                 }
	         );

	         $this->brand_terms = $terms;

	         return $this->brand_terms;
	 }

	 /**
	  * Fetch and group product categories for building the menu,
	  * excluding the WooCommerce default "Uncategorized" category.
	  *
	  * Any children of "Uncategorized" are re-parented to top level.
	  *
	  * @return array<int, array<int, WP_Term>> Grouped by parent term_id
	  */
	 public function get_category_terms() {
	         if ( null !== $this->category_terms ) {
	                 return $this->category_terms;
	         }

	         if ( ! function_exists( 'taxonomy_exists' ) || ! taxonomy_exists( 'product_cat' ) ) {
	                 $this->category_terms = false;
	                 return $this->category_terms;
	         }

	         if ( ! function_exists( 'get_terms' ) ) {
	                 $this->category_terms = array();
	                 return $this->category_terms;
	         }

	         // Pull every category (even empty), ordered by name
	         $terms = get_terms(
	                 array(
	                         'taxonomy'   => 'product_cat',
	                         'hide_empty' => false,
	                         'orderby'    => 'name',
	                         'order'      => 'ASC',
	                 )
	         );

	         if ( $this->is_wp_error( $terms ) ) {
	                 $this->category_terms = array();
	                 return $this->category_terms;
	         }

	         if ( ! is_array( $terms ) ) {
	                 $this->category_terms = array();
	                 return $this->category_terms;
	         }

	         // Default product category id (usually "Uncategorized")
	         $default_cat_id = 0;
	         if ( function_exists( 'get_option' ) ) {
	                 $default_cat_id = (int) get_option( 'default_product_cat', 0 );
	         }

	         $removed_ids = array();

	         // Mark "Uncategorized" for removal by ID or by slug/name fallback.
	         foreach ( $terms as $t ) {
	                 $tid  = isset( $t->term_id ) ? (int) $t->term_id : 0;
	                 $slug = isset( $t->slug ) ? (string) $t->slug : '';
	                 $name = isset( $t->name ) ? (string) $t->name : '';

	                 $is_default_id = ( $default_cat_id > 0 && $tid === $default_cat_id );

	                 $is_uncat_slug = false;
	                 if ( '' !== $slug && function_exists( 'sanitize_title' ) ) {
	                         $is_uncat_slug = ( sanitize_title( $slug ) === 'uncategorized' );
	                 } elseif ( '' !== $name && function_exists( 'sanitize_title' ) ) {
	                         $is_uncat_slug = ( sanitize_title( $name ) === 'uncategorized' );
	                 }

	                 if ( $is_default_id || $is_uncat_slug ) {
	                         $removed_ids[ $tid ] = true;
	                 }
	         }

	         // Build grouped list; skip removed IDs; lift children of "Uncategorized" to top-level.
	         $grouped = array();

	         foreach ( $terms as $t ) {
	                 $tid = isset( $t->term_id ) ? (int) $t->term_id : 0;
	                 if ( 0 === $tid ) {
	                         continue;
	                 }

	                 if ( isset( $removed_ids[ $tid ] ) ) {
	                         // Skip "Uncategorized" itself
	                         continue;
	                 }

	                 $parent_id = isset( $t->parent ) ? (int) $t->parent : 0;

	                 if ( $parent_id > 0 && isset( $removed_ids[ $parent_id ] ) ) {
	                         // Re-parent children of "Uncategorized" to top-level
	                         $parent_id = 0;
	                         // Update the term object for downstream logic clarity
	                         $t->parent = 0;
	                 }

	                 if ( ! isset( $grouped[ $parent_id ] ) ) {
	                         $grouped[ $parent_id ] = array();
	                 }

	                 $grouped[ $parent_id ][] = $t;
	         }

	         $this->category_terms = $grouped;
	         return $this->category_terms;
	 }

	 /**
	  * Append category menu items preserving hierarchy.
	  *
	  * @param array<int, WP_Post|object>                 $menu_items     Existing menu items.
	  * @param WP_Post|object                             $products_item  Parent products menu item.
	  * @param array<int, array<int, WP_Term|object>>     $category_terms Grouped category terms.
	  * @param int                                        $menu_order     Current menu order counter.
	  *
	  * @return array{0: array<int, WP_Post|object>, 1: int, 2: int}
	  */
	 private function append_category_items( array $menu_items, $products_item, array $category_terms, $menu_order ) {
	         if ( empty( $category_terms ) || ! isset( $category_terms[0] ) ) {
	                 return array( $menu_items, $menu_order, 0 );
	         }

	         $added = 0;

	         foreach ( $category_terms[0] as $term ) {
	                 list( $menu_items, $menu_order, $child_added ) =
	                         $this->add_category_term( $menu_items, $term, $products_item, $category_terms, $menu_order );
	                 $added += (int) $child_added;
	         }

	         return array( $menu_items, $menu_order, $added );
	 }

	 /**
	  * Recursively append category menu items for a given term and its descendants.
	  *
	  * @param array<int, WP_Post|object>             $menu_items     Existing menu items.
	  * @param WP_Term|object                         $term           Current category term.
	  * @param WP_Post|object                         $parent_item    Parent menu item.
	  * @param array<int, array<int, WP_Term|object>> $category_terms Grouped category terms.
	  * @param int                                    $menu_order     Current menu order counter.
	  *
	  * @return array{0: array<int, WP_Post|object>, 1: int, 2: int}
	  */
	 private function add_category_term( array $menu_items, $term, $parent_item, array $category_terms, $menu_order ) {
	         $new_item = $this->create_menu_item_from_term( $term, $parent_item, $menu_order + 1 );

	         if ( ! $new_item ) {
	                 return array( $menu_items, $menu_order, 0 );
	         }

	         $menu_order++;
	         $menu_items[] = $new_item;
	         $added        = 1;

	         $term_id = isset( $term->term_id ) ? (int) $term->term_id : 0;

	         if ( $term_id && isset( $category_terms[ $term_id ] ) ) {
	                 foreach ( $category_terms[ $term_id ] as $child_term ) {
	                         list( $menu_items, $menu_order, $child_added ) =
	                                 $this->add_category_term( $menu_items, $child_term, $new_item, $category_terms, $menu_order );
	                         $added += (int) $child_added;
	                 }
	         }

	         return array( $menu_items, $menu_order, $added );
	 }

	 /**
	  * Create a new menu item from a taxonomy term.
	  *
	  * @param WP_Term|object $term          Source term.
	  * @param WP_Post|object $parent_item   Parent menu item.
	  * @param int            $menu_order    Menu order value.
	  *
	  * @return WP_Post|object|null
	  */
	 private function create_menu_item_from_term( $term, $parent_item, $menu_order ) {
	         if ( ! isset( $term->taxonomy, $term->term_id, $term->name ) ) {
	                 return null;
	         }

	         if ( ! function_exists( 'get_term_link' ) ) {
	                 return null;
	         }

	         $url = get_term_link( $term );

	         if ( $this->is_wp_error( $url ) || ! is_string( $url ) ) {
	                 return null;
	         }

	         $item = clone $parent_item;

	         $item->ID                 = $this->next_id();
	         $item->db_id              = $item->ID;
	         $item->menu_item_parent   = isset( $parent_item->db_id ) ? (int) $parent_item->db_id : ( isset( $parent_item->ID ) ? (int) $parent_item->ID : 0 );
	         $item->object             = (string) $term->taxonomy;
	         $item->object_id          = (int) $term->term_id;
	         $item->type               = 'taxonomy';
	         $item->type_label         = 'Taxonomy';
	         $item->title              = (string) $term->name;
	         $item->post_title         = (string) $term->name;
	         $item->post_name          = $this->generate_post_name( $term );
	         $item->url                = $url;
	         $item->classes            = array( 'softone-dynamic-menu-item' );
	         $item->xfn                = '';
	         $item->target             = '';
	         $item->attr_title         = '';
	         $item->description        = '';
	         $item->menu_order         = (int) $menu_order;
	         $item->post_parent        = isset( $parent_item->post_parent ) ? (int) $parent_item->post_parent : 0;
	         $item->post_status        = 'publish';
	         $item->post_type          = 'nav_menu_item';

	         return $item;
	 }

	 /**
	  * Generate a sanitized post name for the new menu item.
	  *
	  * @param WP_Term|object $term Term reference.
	  *
	  * @return string
	  */
	 private function generate_post_name( $term ) {
	         $value = isset( $term->slug ) ? (string) $term->slug : ( ( isset( $term->name ) ? (string) $term->name : '' ) );

	         if ( function_exists( 'sanitize_title' ) ) {
	                 return sanitize_title( $value );
	         }

	         $value = strtolower( $value );
	         $value = preg_replace( '/[^a-z0-9\-]+/', '-', $value );
	         $value = trim( $value, '-' );

	         return $value;
	 }

	 /**
	  * Retrieve the maximum menu order from the existing items.
	  *
	  * @param array<int, WP_Post|object> $menu_items Menu items.
	  *
	  * @return int
	  */
	 private function get_max_menu_order( array $menu_items ) {
	         $max = 0;

	         foreach ( $menu_items as $item ) {
	                 if ( isset( $item->menu_order ) ) {
	                         $max = max( $max, (int) $item->menu_order );
	                 }
	         }

	         return $max;
	 }

	 /**
	  * Generate the next temporary ID.
	  *
	  * @return int
	  */
	 private function next_id() {
	         $this->id_counter--;
	         return $this->id_counter;
	 }

	 /**
	  * Lightweight check for WP_Error compatibility.
	  *
	  * @param mixed $maybe_error Value to test.
	  *
	  * @return bool
	  */
	 private function is_wp_error( $maybe_error ) {
	         if ( function_exists( 'is_wp_error' ) ) {
	                 return is_wp_error( $maybe_error );
	         }

	         return ( $maybe_error instanceof WP_Error );
	 }
}
