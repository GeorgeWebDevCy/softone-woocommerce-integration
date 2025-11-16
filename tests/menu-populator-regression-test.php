<?php
/**
 * Regression test for the Softone_Menu_Populator helper.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ );
}

class WP_Error {
    /**
     * @var array<string, array<int, string>>
     */
    public $errors = array();

    /**
     * @param string $code    Error code.
     * @param string $message Error message.
     */
    public function __construct( $code = '', $message = '' ) {
        if ( $code ) {
            $this->errors[ $code ] = array( $message );
        }
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    /**
     * Lightweight WP_Error detection.
     *
     * @param mixed $thing Value to test.
     *
     * @return bool
     */
    function is_wp_error( $thing ) {
        return $thing instanceof WP_Error;
    }
}

$GLOBALS['softone_taxonomy_exists'] = array(
    'product_brand' => true,
    'product_cat'   => true,
);

$GLOBALS['softone_mock_terms'] = array();

if ( ! function_exists( 'taxonomy_exists' ) ) {
    /**
     * Mock taxonomy_exists implementation.
     *
     * @param string $taxonomy Taxonomy name.
     *
     * @return bool
     */
    function taxonomy_exists( $taxonomy ) {
        return ! empty( $GLOBALS['softone_taxonomy_exists'][ $taxonomy ] );
    }
}

if ( ! function_exists( 'get_terms' ) ) {
    /**
     * Mock get_terms implementation.
     *
     * @param array $args Arguments.
     *
     * @return array<int, object>
     */
function get_terms( $args ) {
    if ( empty( $args['taxonomy'] ) ) {
        return array();
    }

    $taxonomy = (array) $args['taxonomy'];
    $taxonomy = reset( $taxonomy );

    if ( empty( $GLOBALS['softone_mock_terms'][ $taxonomy ] ) ) {
        return array();
    }

    $terms   = $GLOBALS['softone_mock_terms'][ $taxonomy ];
    $orderby = isset( $args['orderby'] ) ? (string) $args['orderby'] : '';

    if ( $orderby ) {
        $order = isset( $args['order'] ) ? strtoupper( (string) $args['order'] ) : 'ASC';

        usort(
            $terms,
            static function ( $a, $b ) use ( $orderby, $order ) {
                $value_a = null;
                $value_b = null;

                switch ( $orderby ) {
                    case 'menu_order':
                    case 'term_order':
                        $value_a = isset( $a->menu_order ) ? (int) $a->menu_order : ( isset( $a->term_order ) ? (int) $a->term_order : 0 );
                        $value_b = isset( $b->menu_order ) ? (int) $b->menu_order : ( isset( $b->term_order ) ? (int) $b->term_order : 0 );
                        break;
                    case 'name':
                        $value_a = isset( $a->name ) ? (string) $a->name : '';
                        $value_b = isset( $b->name ) ? (string) $b->name : '';

                        return ( 'DESC' === $order ) ? strcasecmp( $value_b, $value_a ) : strcasecmp( $value_a, $value_b );
                    default:
                        return 0;
                }

                if ( $value_a === $value_b ) {
                    $id_a = isset( $a->term_id ) ? (int) $a->term_id : 0;
                    $id_b = isset( $b->term_id ) ? (int) $b->term_id : 0;

                    if ( $id_a === $id_b ) {
                        return 0;
                    }

                    if ( 'DESC' === $order ) {
                        return ( $id_a < $id_b ) ? 1 : -1;
                    }

                    return ( $id_a < $id_b ) ? -1 : 1;
                }

                if ( 'DESC' === $order ) {
                    return ( $value_a < $value_b ) ? 1 : -1;
                }

                return ( $value_a < $value_b ) ? -1 : 1;
            }
        );
    }

    return $terms;
}
}

if ( ! function_exists( 'get_term_link' ) ) {
    /**
     * Mock get_term_link implementation.
     *
     * @param object $term Term object.
     *
     * @return string|WP_Error
     */
    function get_term_link( $term ) {
        if ( isset( $term->slug ) && false !== strpos( $term->slug, 'broken' ) ) {
            return new WP_Error( 'broken', 'Broken link' );
        }

        return 'https://example.com/' . $term->taxonomy . '/' . $term->slug;
    }
}

if ( ! function_exists( 'sanitize_title' ) ) {
    /**
     * Simplified sanitize_title implementation.
     *
     * @param string $value Raw value.
     *
     * @return string
     */
    function sanitize_title( $value ) {
        $value = strtolower( $value );
        $value = preg_replace( '/[^a-z0-9\-]+/', '-', $value );

        return trim( $value, '-' );
    }
}

if ( ! function_exists( '__' ) ) {
    /**
     * Mock translation function to satisfy class requirements.
     *
     * @param string $text Text to translate.
     *
     * @return string
     */
    function __( $text ) {
        return $text;
    }
}

if ( ! function_exists( 'wp_unslash' ) ) {
    /**
     * Mimic wp_unslash for test expectations.
     *
     * @param mixed $value Value to unslash.
     *
     * @return mixed
     */
    function wp_unslash( $value ) {
        if ( is_array( $value ) ) {
            return array_map( 'wp_unslash', $value );
        }

        return is_string( $value ) ? stripslashes( $value ) : $value;
    }
}

$GLOBALS['softone_filters']           = array();
$GLOBALS['softone_nav_menu_meta']     = array();
$GLOBALS['softone_is_admin_context']  = false;
$GLOBALS['softone_wp_setup_calls']    = array();
$GLOBALS['softone_wp_update_nav_menu_item_calls'] = array();

if ( ! function_exists( 'add_filter' ) ) {
    /**
     * Register a filter callback for the test harness.
     *
     * @param string   $tag             Filter name.
     * @param callable $function_to_add Callback.
     * @param int      $priority        Priority.
     * @param int      $accepted_args   Accepted args.
     *
     * @return bool
     */
    function add_filter( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
        if ( ! isset( $GLOBALS['softone_filters'][ $tag ] ) ) {
            $GLOBALS['softone_filters'][ $tag ] = array();
        }

        if ( ! isset( $GLOBALS['softone_filters'][ $tag ][ $priority ] ) ) {
            $GLOBALS['softone_filters'][ $tag ][ $priority ] = array();
        }

        $GLOBALS['softone_filters'][ $tag ][ $priority ][] = array(
            'function'      => $function_to_add,
            'accepted_args' => (int) $accepted_args,
        );

        return true;
    }
}

if ( ! function_exists( 'is_admin' ) ) {
    /**
     * Toggle admin context for the test harness.
     *
     * @return bool
     */
    function is_admin() {
        return ! empty( $GLOBALS['softone_is_admin_context'] );
    }
}

if ( ! function_exists( 'wp_setup_nav_menu_item' ) ) {
    /**
     * Record menu items processed via wp_setup_nav_menu_item().
     *
     * @param object $menu_item Menu item.
     *
     * @return object
     */
    function wp_setup_nav_menu_item( $menu_item ) {
        if ( ! isset( $GLOBALS['softone_wp_setup_calls'] ) ) {
            $GLOBALS['softone_wp_setup_calls'] = array();
        }

        $GLOBALS['softone_wp_setup_calls'][] = isset( $menu_item->ID ) ? $menu_item->ID : null;
        $menu_item->processed_by_setup       = true;

        return $menu_item;
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    /**
     * Execute filter callbacks for the test harness.
     *
     * @param string $tag   Filter name.
     * @param mixed  $value Initial value.
     *
     * @return mixed
     */
    function apply_filters( $tag, $value ) {
        $args = func_get_args();

        if ( empty( $GLOBALS['softone_filters'][ $tag ] ) ) {
            return $value;
        }

        ksort( $GLOBALS['softone_filters'][ $tag ] );

        foreach ( $GLOBALS['softone_filters'][ $tag ] as $callbacks ) {
            foreach ( $callbacks as $callback ) {
                if ( ! is_callable( $callback['function'] ) ) {
                    continue;
                }

                $args[1] = $value;
                $value   = call_user_func_array(
                    $callback['function'],
                    array_slice( $args, 1, $callback['accepted_args'] )
                );
            }
        }

        return $value;
    }
}

if ( ! function_exists( 'get_post_meta' ) ) {
    /**
     * Retrieve mock menu item metadata.
     *
     * @param int    $object_id Object ID.
     * @param string $key       Meta key.
     * @param bool   $single    Whether to return a single value.
     *
     * @return mixed
     */
    function get_post_meta( $object_id, $key = '', $single = false ) {
        $object_id = (int) $object_id;

        if ( $object_id <= 0 || empty( $GLOBALS['softone_nav_menu_meta'][ $object_id ] ) ) {
            return $single ? '' : array();
        }

        if ( '' === $key ) {
            return $GLOBALS['softone_nav_menu_meta'][ $object_id ];
        }

        if ( ! isset( $GLOBALS['softone_nav_menu_meta'][ $object_id ][ $key ] ) ) {
            return $single ? '' : array();
        }

        $value = $GLOBALS['softone_nav_menu_meta'][ $object_id ][ $key ];

        if ( $single ) {
            if ( is_array( $value ) ) {
                $first = reset( $value );

                return false === $first ? '' : $first;
            }

            return $value;
        }

        if ( is_array( $value ) ) {
            return $value;
        }

        return array( $value );
    }
}

/**
 * Reset registered filters for the test harness.
 */
function softone_reset_filters() {
    $GLOBALS['softone_filters'] = array();
}

/**
 * Reset mock menu item metadata.
 */
function softone_reset_menu_meta() {
    $GLOBALS['softone_nav_menu_meta'] = array();
}

/**
 * Store mock metadata for a nav menu item.
 *
 * @param int    $item_id Menu item ID.
 * @param string $key     Meta key.
 * @param mixed  $value   Meta value.
 */
function softone_set_menu_item_meta( $item_id, $key, $value ) {
    $item_id = (int) $item_id;

    if ( ! isset( $GLOBALS['softone_nav_menu_meta'][ $item_id ] ) ) {
        $GLOBALS['softone_nav_menu_meta'][ $item_id ] = array();
    }

    $GLOBALS['softone_nav_menu_meta'][ $item_id ][ $key ] = $value;
}

/**
 * Record that wp_update_nav_menu_item would have been invoked for a menu item.
 *
 * @param string|int $menu_item_key Menu item key from the POST payload.
 */
function softone_track_nav_menu_save( $menu_item_key ) {
    if ( ! isset( $GLOBALS['softone_wp_update_nav_menu_item_calls'] ) ) {
        $GLOBALS['softone_wp_update_nav_menu_item_calls'] = array();
    }

    $GLOBALS['softone_wp_update_nav_menu_item_calls'][] = (string) $menu_item_key;
}

/**
 * Reset the recorded wp_update_nav_menu_item calls.
 */
function softone_reset_nav_menu_save_tracker() {
    $GLOBALS['softone_wp_update_nav_menu_item_calls'] = array();
}

require_once dirname( __DIR__ ) . '/includes/softone-menu-helpers.php';
require_once dirname( __DIR__ ) . '/includes/class-softone-menu-populator.php';

/**
 * Create a mock nav menu item object.
 *
 * @param int    $id     ID.
 * @param string $title  Menu title.
 * @param int    $parent Parent ID.
 * @param int    $order  Menu order.
 *
 * @return object
 */
function softone_create_menu_item( $id, $title, $parent = 0, $order = 0 ) {
    $item                     = new stdClass();
    $item->ID                 = $id;
    $item->db_id              = $id;
    $item->menu_item_parent   = $parent;
    $item->post_parent        = 0;
    $item->object             = 'custom';
    $item->object_id          = $id;
    $item->type               = 'custom';
    $item->title              = $title;
    $item->post_title         = $title;
    $item->post_name          = sanitize_title( $title );
    $item->url                = '#';
    $item->classes            = array();
    $item->menu_order         = $order;
    $item->post_status        = 'publish';
    $item->post_type          = 'nav_menu_item';

    return $item;
}

/**
 * Retrieve the best available identifier for a nav menu item.
 *
 * @param object $item Menu item reference.
 *
 * @return int
 */
function softone_get_menu_item_identifier( $item ) {
    if ( isset( $item->db_id ) && (int) $item->db_id !== 0 ) {
        return (int) $item->db_id;
    }

    if ( isset( $item->ID ) ) {
        return (int) $item->ID;
    }

    return 0;
}

/**
 * Summarise dynamic menu output for assertions.
 *
 * @param array<int, object> $items              Menu items.
 * @param int                $brand_parent_id    Placeholder ID for brands.
 * @param int                $product_parent_id  Placeholder ID for products.
 *
 * @return array<string, mixed>
 */
function softone_summarise_menu_output( array $items, $brand_parent_id, $product_parent_id ) {
    $summary = array(
        'brand_children' => array(),
        'top_categories' => array(),
        'category_tree'  => array(),
        'dynamic_count'  => 0,
    );

    $category_id_to_title = array();

    foreach ( $items as $item ) {
        $classes = array();

        if ( isset( $item->classes ) ) {
            if ( is_array( $item->classes ) ) {
                $classes = $item->classes;
            } elseif ( is_string( $item->classes ) ) {
                $classes = array( $item->classes );
            }
        }

        $is_dynamic = in_array( 'softone-dynamic-menu-item', $classes, true );

        if ( $is_dynamic ) {
            $summary['dynamic_count']++;
        }

        if ( $is_dynamic && (int) $brand_parent_id === (int) $item->menu_item_parent ) {
            $summary['brand_children'][] = $item->title;
        }

        if ( isset( $item->object ) && 'product_cat' === $item->object && $is_dynamic ) {
            if ( ! isset( $summary['category_tree'] ) ) {
                $summary['category_tree'] = array();
            }

            $category_id = softone_get_menu_item_identifier( $item );

            if ( 0 === $category_id ) {
                continue;
            }

            $category_id_to_title[ $category_id ] = $item->title;

            if ( (int) $product_parent_id === (int) $item->menu_item_parent ) {
                $summary['top_categories'][]           = $item->title;
                $summary['category_tree'][ $item->title ] = array();
            } else {
                $parent_id = (int) $item->menu_item_parent;

                if ( isset( $category_id_to_title[ $parent_id ] ) ) {
                    $parent_title = $category_id_to_title[ $parent_id ];

                    if ( ! isset( $summary['category_tree'][ $parent_title ] ) ) {
                        $summary['category_tree'][ $parent_title ] = array();
                    }

                    $summary['category_tree'][ $parent_title ][] = $item->title;
                }
            }
        }
    }

    return $summary;
}

/**
 * Build the mock taxonomy terms used by the test.
 */
function softone_build_mock_terms() {
    $brand_terms = array();

    foreach ( array(
        array( 'term_id' => 101, 'name' => 'Gamma', 'slug' => 'gamma' ),
        array( 'term_id' => 100, 'name' => 'Alpha', 'slug' => 'alpha' ),
        array( 'term_id' => 102, 'name' => 'Beta', 'slug' => 'beta' ),
        array( 'term_id' => 103, 'name' => 'Broken', 'slug' => 'broken-link' ),
    ) as $data ) {
        $term            = (object) $data;
        $term->taxonomy  = 'product_brand';
        $term->parent    = 0;
        $brand_terms[]   = $term;
    }

    $category_terms = array();

    foreach ( array(
        array( 'term_id' => 20, 'name' => 'Clothing', 'slug' => 'clothing', 'parent' => 0, 'menu_order' => 1, 'term_order' => 1 ),
        array( 'term_id' => 21, 'name' => 'Pants', 'slug' => 'pants', 'parent' => 20, 'menu_order' => 2, 'term_order' => 2 ),
        array( 'term_id' => 23, 'name' => 'Jeans', 'slug' => 'jeans', 'parent' => 21, 'menu_order' => 2, 'term_order' => 2 ),
        array( 'term_id' => 24, 'name' => 'Chinos', 'slug' => 'chinos', 'parent' => 21, 'menu_order' => 1, 'term_order' => 1 ),
        array( 'term_id' => 22, 'name' => 'Shirts', 'slug' => 'shirts', 'parent' => 20, 'menu_order' => 1, 'term_order' => 1 ),
        array( 'term_id' => 10, 'name' => 'Accessories', 'slug' => 'accessories', 'parent' => 0, 'menu_order' => 2, 'term_order' => 2 ),
        array( 'term_id' => 11, 'name' => 'Scarves', 'slug' => 'scarves', 'parent' => 10, 'menu_order' => 1, 'term_order' => 1 ),
        array( 'term_id' => 12, 'name' => 'Belts', 'slug' => 'belts', 'parent' => 10, 'menu_order' => 2, 'term_order' => 2 ),
    ) as $data ) {
        $term            = (object) $data;
        $term->taxonomy  = 'product_cat';
        $category_terms[] = $term;
    }

    $GLOBALS['softone_mock_terms']['product_brand'] = $brand_terms;
    $GLOBALS['softone_mock_terms']['product_cat']   = $category_terms;
}

softone_build_mock_terms();

softone_reset_filters();
softone_reset_menu_meta();

$main_menu_items = array(
    softone_create_menu_item( 1, 'Home', 0, 1 ),
    softone_create_menu_item( 2, 'Brands', 0, 2 ),
    softone_create_menu_item( 3, 'Products', 0, 3 ),
);

$main_args = (object) array(
    'menu' => (object) array(
        'name' => softone_wc_integration_get_main_menu_name(),
    ),
);

$menu_populator = new Softone_Menu_Populator();

$result = $menu_populator->filter_menu_items( $main_menu_items, $main_args );

$summary = softone_summarise_menu_output( $result, 2, 3 );

$expected_brand_children = array( 'Alpha', 'Beta', 'Gamma' );
$expected_top_categories = array( 'Clothing', 'Accessories' );
$expected_category_tree  = array(
    'Clothing'    => array( 'Shirts', 'Pants' ),
    'Pants'       => array( 'Chinos', 'Jeans' ),
    'Accessories' => array( 'Scarves', 'Belts' ),
);
$expected_dynamic_total  = count( $expected_brand_children ) + count( $expected_top_categories ) + array_sum( array_map( 'count', $expected_category_tree ) );

if ( count( $result ) !== count( $main_menu_items ) + $expected_dynamic_total ) {
    fwrite( STDERR, 'Unexpected number of menu items returned.' . PHP_EOL );
    exit( 1 );
}

if ( $expected_brand_children !== $summary['brand_children'] ) {
    fwrite( STDERR, 'Brand children were not appended in alphabetical order or were missing.' . PHP_EOL );
    exit( 1 );
}

if ( $expected_top_categories !== $summary['top_categories'] ) {
    fwrite( STDERR, 'Top-level categories were not appended correctly.' . PHP_EOL );
    exit( 1 );
}

if ( $expected_category_tree !== $summary['category_tree'] ) {
    fwrite( STDERR, 'Category hierarchy was not preserved.' . PHP_EOL );
    exit( 1 );
}

if ( $summary['dynamic_count'] !== $expected_dynamic_total ) {
    fwrite( STDERR, 'Dynamic menu items were not tagged correctly.' . PHP_EOL );
    exit( 1 );
}

// Verify duplicate protection by invoking the filter again.
$second_pass = $menu_populator->filter_menu_items( $result, $main_args );

if ( count( $second_pass ) !== count( $result ) ) {
    fwrite( STDERR, 'Duplicate menu items were detected on the second pass.' . PHP_EOL );
    exit( 1 );
}

$secondary_menu_items = array(
    softone_create_menu_item( 1, 'Home', 0, 1 ),
    softone_create_menu_item( 4, 'Brands', 0, 2 ),
);

$secondary_args = (object) array(
    'menu' => (object) array(
        'name' => 'Footer Menu',
    ),
);

$secondary_result = $menu_populator->filter_menu_items( $secondary_menu_items, $secondary_args );

if ( count( $secondary_result ) !== count( $secondary_menu_items ) ) {
    fwrite( STDERR, 'A non-main menu was unexpectedly modified.' . PHP_EOL );
    exit( 1 );
}

// Simulate missing taxonomy handling.
$GLOBALS['softone_taxonomy_exists']['product_brand'] = false;
$GLOBALS['softone_taxonomy_exists']['product_cat']   = true;

$missing_taxonomy_populator = new Softone_Menu_Populator();
$missing_result             = $missing_taxonomy_populator->filter_menu_items( $main_menu_items, $main_args );

$missing_summary = softone_summarise_menu_output( $missing_result, 2, 3 );

foreach ( $missing_result as $item ) {
    $classes = array();

    if ( isset( $item->classes ) ) {
        if ( is_array( $item->classes ) ) {
            $classes = $item->classes;
        } elseif ( is_string( $item->classes ) ) {
            $classes = array( $item->classes );
        }
    }

    if ( isset( $item->object ) && 'product_brand' === $item->object && in_array( 'softone-dynamic-menu-item', $classes, true ) ) {
        fwrite( STDERR, 'Brand menu items were appended despite the taxonomy being unavailable.' . PHP_EOL );
        exit( 1 );
    }
}

$expected_category_count = count( $expected_top_categories ) + array_sum( array_map( 'count', $expected_category_tree ) );

if ( count( $missing_result ) !== count( $main_menu_items ) + $expected_category_count ) {
    fwrite( STDERR, 'Category menu items were not appended when the brand taxonomy was unavailable.' . PHP_EOL );
    exit( 1 );
}

if ( $expected_top_categories !== $missing_summary['top_categories'] ) {
    fwrite( STDERR, 'Top-level categories were not appended when the brand taxonomy was unavailable.' . PHP_EOL );
    exit( 1 );
}

if ( $expected_category_tree !== $missing_summary['category_tree'] ) {
    fwrite( STDERR, 'Category hierarchy changed when the brand taxonomy was unavailable.' . PHP_EOL );
    exit( 1 );
}

$GLOBALS['softone_taxonomy_exists']['product_brand'] = true;

softone_reset_filters();
softone_reset_menu_meta();

add_filter(
    'softone_wc_integration_menu_placeholder_titles',
    static function ( $titles ) {
        $titles['brands']   = array( 'Marcas', 'Nuestras Marcas' );
        $titles['products'] = array( 'Catálogo', 'Productos' );

        return $titles;
    }
);

$translated_menu_items = array(
    softone_create_menu_item( 10, 'Inicio', 0, 1 ),
    softone_create_menu_item( 11, 'Marcas', 0, 2 ),
    softone_create_menu_item( 12, 'Catálogo', 0, 3 ),
);

$translated_args = (object) array(
    'menu' => (object) array(
        'name' => softone_wc_integration_get_main_menu_name(),
    ),
);

$translated_populator = new Softone_Menu_Populator();
$translated_result    = $translated_populator->filter_menu_items( $translated_menu_items, $translated_args );
$translated_summary   = softone_summarise_menu_output( $translated_result, 11, 12 );

if ( count( $translated_result ) !== count( $translated_menu_items ) + $expected_dynamic_total ) {
    fwrite( STDERR, 'Translated placeholders did not receive the expected number of dynamic items.' . PHP_EOL );
    exit( 1 );
}

if ( $expected_brand_children !== $translated_summary['brand_children'] ) {
    fwrite( STDERR, 'Translated brand placeholder did not receive the expected children.' . PHP_EOL );
    exit( 1 );
}

if ( $expected_top_categories !== $translated_summary['top_categories'] ) {
    fwrite( STDERR, 'Translated products placeholder did not receive the expected top-level categories.' . PHP_EOL );
    exit( 1 );
}

if ( $expected_category_tree !== $translated_summary['category_tree'] ) {
    fwrite( STDERR, 'Translated placeholders altered the expected category hierarchy.' . PHP_EOL );
    exit( 1 );
}

if ( $translated_summary['dynamic_count'] !== $expected_dynamic_total ) {
    fwrite( STDERR, 'Translated placeholders produced an unexpected number of dynamic menu items.' . PHP_EOL );
    exit( 1 );
}

softone_reset_filters();
softone_reset_menu_meta();

add_filter(
    'softone_wc_integration_menu_placeholder_config',
    static function ( $config ) {
        $config['brands']['titles'] = array();
        $config['brands']['meta']   = array(
            array(
                'key'   => '_softone_placeholder',
                'value' => array( 'brands' ),
            ),
        );

        $config['products']['titles'] = array();
        $config['products']['meta']   = array(
            'key'   => '_softone_placeholder',
            'value' => array( 'products', 'product-tree' ),
        );

        return $config;
    }
);

$meta_menu_items = array(
    softone_create_menu_item( 20, 'Inicio', 0, 1 ),
    softone_create_menu_item( 21, 'Colecciones', 0, 2 ),
    softone_create_menu_item( 22, 'Catálogo Completo', 0, 3 ),
);

softone_set_menu_item_meta( 21, '_softone_placeholder', 'brands' );
softone_set_menu_item_meta( 22, '_softone_placeholder', 'product-tree' );

$meta_args       = (object) array(
    'menu' => (object) array(
        'name' => softone_wc_integration_get_main_menu_name(),
    ),
);
$meta_populator  = new Softone_Menu_Populator();
$meta_result     = $meta_populator->filter_menu_items( $meta_menu_items, $meta_args );
$meta_summary    = softone_summarise_menu_output( $meta_result, 21, 22 );

if ( count( $meta_result ) !== count( $meta_menu_items ) + $expected_dynamic_total ) {
    fwrite( STDERR, 'Metadata-based placeholders did not receive the expected number of dynamic items.' . PHP_EOL );
    exit( 1 );
}

if ( $expected_brand_children !== $meta_summary['brand_children'] ) {
    fwrite( STDERR, 'Metadata-based brand placeholder did not receive the expected children.' . PHP_EOL );
    exit( 1 );
}

if ( $expected_top_categories !== $meta_summary['top_categories'] ) {
    fwrite( STDERR, 'Metadata-based products placeholder did not receive the expected top-level categories.' . PHP_EOL );
    exit( 1 );
}

if ( $expected_category_tree !== $meta_summary['category_tree'] ) {
    fwrite( STDERR, 'Metadata-based placeholders altered the expected category hierarchy.' . PHP_EOL );
    exit( 1 );
}

if ( $meta_summary['dynamic_count'] !== $expected_dynamic_total ) {
    fwrite( STDERR, 'Metadata-based placeholders produced an unexpected number of dynamic menu items.' . PHP_EOL );
    exit( 1 );
}

softone_reset_filters();
softone_reset_menu_meta();

$GLOBALS['softone_is_admin_context'] = true;
$GLOBALS['softone_wp_setup_calls']   = array();

$admin_menu_items = array(
    softone_create_menu_item( 30, 'Home', 0, 1 ),
    softone_create_menu_item( 31, 'Brands', 0, 2 ),
    softone_create_menu_item( 32, 'Products', 0, 3 ),
);

$admin_menu = (object) array(
    'term_id' => 501,
    'name'    => softone_wc_integration_get_main_menu_name(),
);

$admin_populator = new Softone_Menu_Populator();
$admin_result    = $admin_populator->filter_admin_menu_items( $admin_menu_items, $admin_menu, array() );
$admin_summary   = softone_summarise_menu_output( $admin_result, 31, 32 );

if ( $admin_summary['dynamic_count'] !== $expected_dynamic_total ) {
    fwrite( STDERR, 'Admin filter did not append the expected dynamic menu items.' . PHP_EOL );
    exit( 1 );
}

$setup_call_count = count( $GLOBALS['softone_wp_setup_calls'] );

if ( $setup_call_count !== $expected_dynamic_total ) {
    fwrite( STDERR, 'Admin filter failed to prepare each dynamic item via wp_setup_nav_menu_item().' . PHP_EOL );
    exit( 1 );
}

$admin_second_pass  = $admin_populator->filter_admin_menu_items( $admin_menu_items, $admin_menu, array() );
$admin_second_check = softone_summarise_menu_output( $admin_second_pass, 31, 32 );

if ( $admin_second_check['dynamic_count'] !== $expected_dynamic_total ) {
    fwrite( STDERR, 'Repeated admin filtering did not produce the expected dynamic menu items.' . PHP_EOL );
    exit( 1 );
}

if ( count( $GLOBALS['softone_wp_setup_calls'] ) !== ( $expected_dynamic_total * 2 ) ) {
    fwrite( STDERR, 'Second admin filter pass failed to prepare each dynamic item via wp_setup_nav_menu_item().' . PHP_EOL );
    exit( 1 );
}

$menu_save_requests = array(
    'save_menu_button'      => array( 'save_menu' => 'Save Menu' ),
    'update-nav-menu'       => array( 'action' => 'update-nav-menu' ),
    'update-nav_menu'       => array( 'action' => 'update-nav_menu' ),
    'update-menu-item'      => array( 'action' => ' UPDATE-MENU-ITEM ' ),
    'delete-menu-item'      => array( 'action' => 'delete-menu-item' ),
    'wp_ajax_update_nav'    => array( 'action' => 'wp_ajax_update_nav_menu' ),
    'customizer_action_key' => array( 'customize_menus_action' => 'delete-menu-item' ),
    'menu_payload_keys'     => array( 'menu-item-db-id' => array( 'new-0' => 0 ) ),
);

foreach ( $menu_save_requests as $scenario => $post_data ) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST                    = $post_data;

    $save_result = $admin_populator->filter_admin_menu_items( $admin_menu_items, $admin_menu, array() );

    if ( $save_result !== $admin_menu_items ) {
        fwrite( STDERR, 'Menu save request "' . $scenario . '" should not alter menu items.' . PHP_EOL );
        exit( 1 );
    }
}

// Ensure dynamic placeholder items are stripped before persistence.
softone_reset_nav_menu_save_tracker();

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST                     = array(
    'menu-item-db-id'     => array(
        30              => 30,
        'softone-temp'  => 0,
    ),
    'menu-item-title'     => array(
        30              => 'Home',
        'softone-temp'  => 'Brands',
    ),
    'menu-item-type'      => array(
        30              => 'custom',
        'softone-temp'  => 'custom',
    ),
    'menu-item-classes'   => array(
        30              => '',
        'softone-temp'  => 'softone-dynamic-menu-item menu-item',
    ),
    'menu-item-object-id' => array(
        30              => 0,
        'softone-temp'  => 0,
    ),
);

$admin_populator->guard_menu_save_payload( null, $admin_menu->term_id );

if ( isset( $_POST['menu-item-db-id']['softone-temp'] ) ) {
    fwrite( STDERR, 'Softone placeholder entries should be removed from menu-item-db-id payloads.' . PHP_EOL );
    exit( 1 );
}

if ( isset( $_POST['menu-item-classes']['softone-temp'] ) ) {
    fwrite( STDERR, 'Softone placeholder entries should be removed from menu-item-classes payloads.' . PHP_EOL );
    exit( 1 );
}

foreach ( (array) $_POST['menu-item-db-id'] as $item_key => $db_id ) {
    softone_track_nav_menu_save( $item_key );
}

if ( in_array( 'softone-temp', $GLOBALS['softone_wp_update_nav_menu_item_calls'], true ) ) {
    fwrite( STDERR, 'wp_update_nav_menu_item would have been called for a Softone placeholder.' . PHP_EOL );
    exit( 1 );
}

if ( 1 !== count( $GLOBALS['softone_wp_update_nav_menu_item_calls'] ) ) {
    fwrite( STDERR, 'Unexpected number of menu items would have been persisted.' . PHP_EOL );
    exit( 1 );
}

$_POST                     = array();
$_SERVER['REQUEST_METHOD'] = 'GET';

$GLOBALS['softone_is_admin_context'] = false;

echo 'Menu population regression checks passed.' . PHP_EOL;
exit( 0 );
