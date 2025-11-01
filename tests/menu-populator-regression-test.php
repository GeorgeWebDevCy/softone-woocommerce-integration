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

        return $GLOBALS['softone_mock_terms'][ $taxonomy ];
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
        array( 'term_id' => 10, 'name' => 'Accessories', 'slug' => 'accessories', 'parent' => 0 ),
        array( 'term_id' => 11, 'name' => 'Belts', 'slug' => 'belts', 'parent' => 10 ),
        array( 'term_id' => 12, 'name' => 'Scarves', 'slug' => 'scarves', 'parent' => 10 ),
        array( 'term_id' => 20, 'name' => 'Clothing', 'slug' => 'clothing', 'parent' => 0 ),
        array( 'term_id' => 21, 'name' => 'Pants', 'slug' => 'pants', 'parent' => 20 ),
        array( 'term_id' => 22, 'name' => 'Shirts', 'slug' => 'shirts', 'parent' => 20 ),
    ) as $data ) {
        $term            = (object) $data;
        $term->taxonomy  = 'product_cat';
        $category_terms[] = $term;
    }

    $GLOBALS['softone_mock_terms']['product_brand'] = $brand_terms;
    $GLOBALS['softone_mock_terms']['product_cat']   = $category_terms;
}

softone_build_mock_terms();

$main_menu_items = array(
    softone_create_menu_item( 1, 'Home', 0, 1 ),
    softone_create_menu_item( 2, 'Brands', 0, 2 ),
    softone_create_menu_item( 3, 'Products', 0, 3 ),
);

$main_args = (object) array(
    'menu' => (object) array(
        'name' => 'Main Menu',
    ),
);

$menu_populator = new Softone_Menu_Populator();

$result = $menu_populator->filter_menu_items( $main_menu_items, $main_args );

$brand_children = array();
$top_level_categories = array();
$category_tree = array();
$dynamic_count = 0;
$category_id_to_title = array();

foreach ( $result as $item ) {
    $classes = isset( $item->classes ) ? $item->classes : array();

    if ( in_array( 'softone-dynamic-menu-item', $classes, true ) ) {
        $dynamic_count++;
    }

    if ( 2 === (int) $item->menu_item_parent && in_array( 'softone-dynamic-menu-item', $classes, true ) ) {
        $brand_children[] = $item->title;
    }

    if ( isset( $item->object ) && 'product_cat' === $item->object && in_array( 'softone-dynamic-menu-item', $classes, true ) ) {
        $category_id_to_title[ (int) $item->db_id ] = $item->title;

        if ( 3 === (int) $item->menu_item_parent ) {
            $top_level_categories[]           = $item->title;
            $category_tree[ $item->title ] = array();
        } else {
            $parent_id = (int) $item->menu_item_parent;

            if ( isset( $category_id_to_title[ $parent_id ] ) ) {
                $parent_title = $category_id_to_title[ $parent_id ];

                if ( ! isset( $category_tree[ $parent_title ] ) ) {
                    $category_tree[ $parent_title ] = array();
                }

                $category_tree[ $parent_title ][] = $item->title;
            }
        }
    }
}

$expected_brand_children = array( 'Alpha', 'Beta', 'Gamma' );
$expected_top_categories = array( 'Accessories', 'Clothing' );
$expected_category_tree  = array(
    'Accessories' => array( 'Belts', 'Scarves' ),
    'Clothing'    => array( 'Pants', 'Shirts' ),
);

if ( count( $result ) !== count( $main_menu_items ) + count( $expected_brand_children ) + array_sum( array_map( 'count', $expected_category_tree ) ) + count( $expected_top_categories ) ) {
    fwrite( STDERR, 'Unexpected number of menu items returned.' . PHP_EOL );
    exit( 1 );
}

if ( $expected_brand_children !== $brand_children ) {
    fwrite( STDERR, 'Brand children were not appended in alphabetical order or were missing.' . PHP_EOL );
    exit( 1 );
}

if ( $expected_top_categories !== $top_level_categories ) {
    fwrite( STDERR, 'Top-level categories were not appended correctly.' . PHP_EOL );
    exit( 1 );
}

if ( $expected_category_tree !== $category_tree ) {
    fwrite( STDERR, 'Category hierarchy was not preserved.' . PHP_EOL );
    exit( 1 );
}

if ( $dynamic_count !== count( $expected_brand_children ) + count( $expected_top_categories ) + array_sum( array_map( 'count', $expected_category_tree ) ) ) {
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

$missing_top_categories      = array();
$missing_category_tree       = array();
$missing_category_id_to_title = array();

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

    if ( isset( $item->object ) && 'product_cat' === $item->object && in_array( 'softone-dynamic-menu-item', $classes, true ) ) {
        $missing_category_id_to_title[ (int) $item->db_id ] = $item->title;

        if ( 3 === (int) $item->menu_item_parent ) {
            $missing_top_categories[]      = $item->title;
            $missing_category_tree[ $item->title ] = array();
        } else {
            $parent_id = (int) $item->menu_item_parent;

            if ( isset( $missing_category_id_to_title[ $parent_id ] ) ) {
                $parent_title = $missing_category_id_to_title[ $parent_id ];

                if ( ! isset( $missing_category_tree[ $parent_title ] ) ) {
                    $missing_category_tree[ $parent_title ] = array();
                }

                $missing_category_tree[ $parent_title ][] = $item->title;
            }
        }
    }
}

$expected_category_count = count( $expected_top_categories ) + array_sum( array_map( 'count', $expected_category_tree ) );

if ( count( $missing_result ) !== count( $main_menu_items ) + $expected_category_count ) {
    fwrite( STDERR, 'Category menu items were not appended when the brand taxonomy was unavailable.' . PHP_EOL );
    exit( 1 );
}

if ( $expected_top_categories !== $missing_top_categories ) {
    fwrite( STDERR, 'Top-level categories were not appended when the brand taxonomy was unavailable.' . PHP_EOL );
    exit( 1 );
}

if ( $expected_category_tree !== $missing_category_tree ) {
    fwrite( STDERR, 'Category hierarchy changed when the brand taxonomy was unavailable.' . PHP_EOL );
    exit( 1 );
}

echo 'Menu population regression checks passed.' . PHP_EOL;
exit( 0 );
