<?php
/**
 * Helper for dynamically populating the navigation menu with Softone data.
 *
 * @package Softone_Woocommerce_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

/**
 * Populates the public navigation menu with product brands and categories.
 */
class Softone_Menu_Populator {

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
         * Counter for generating temporary menu item IDs.
         *
         * @var int
         */
        private $id_counter = 0;

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

                $menu_items = $this->strip_dynamic_items( $menu_items );

                $brands_menu_item   = $this->find_menu_item_by_title( $menu_items, 'Brands' );
                $products_menu_item = $this->find_menu_item_by_title( $menu_items, 'Products' );

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
                                }
                        }
                }

                if ( $products_menu_item && ! empty( $category_groups ) ) {
                        list( $menu_items, $max_menu_order ) = $this->append_category_items( $menu_items, $products_menu_item, $category_groups, $max_menu_order );
                }

                return $menu_items;
        }

        /**
         * Determine whether the current menu is the main menu.
         *
         * @param stdClass|array $args Menu arguments.
         *
         * @return bool
         */
        private function is_main_menu( $args ) {
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

                return 'Main Menu' === $menu_name;
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
         * Find a menu item by its displayed title.
         *
         * @param array<int, WP_Post|object> $menu_items Menu items.
         * @param string                     $title      Target title.
         *
         * @return WP_Post|object|null
         */
        private function find_menu_item_by_title( array $menu_items, $title ) {
                foreach ( $menu_items as $item ) {
                        $item_title = '';

                        if ( isset( $item->title ) ) {
                                $item_title = (string) $item->title;
                        } elseif ( isset( $item->post_title ) ) {
                                $item_title = (string) $item->post_title;
                        }

                        if ( 0 === strcasecmp( $item_title, $title ) ) {
                                return $item;
                        }
                }

                return null;
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
         * Retrieve cached grouped product category terms.
         *
         * @return array<int, array<int, WP_Term|object>>|false
         */
        private function get_category_terms() {
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

                $terms = get_terms(
                        array(
                                'taxonomy'   => 'product_cat',
                                'hide_empty' => false,
                                'orderby'    => 'name',
                                'order'      => 'ASC',
                        )
                );

                if ( $this->is_wp_error( $terms ) || ! is_array( $terms ) ) {
                        $this->category_terms = array();
                        return $this->category_terms;
                }

                $grouped = array();

                foreach ( $terms as $term ) {
                        $parent_id = isset( $term->parent ) ? (int) $term->parent : 0;
                        if ( ! isset( $grouped[ $parent_id ] ) ) {
                                $grouped[ $parent_id ] = array();
                        }
                        $grouped[ $parent_id ][] = $term;
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
         * @return array<int, WP_Post|object>
         */
        private function append_category_items( array $menu_items, $products_item, array $category_terms, $menu_order ) {
                if ( empty( $category_terms ) || ! isset( $category_terms[0] ) ) {
                        return array( $menu_items, $menu_order );
                }

                foreach ( $category_terms[0] as $term ) {
                        list( $menu_items, $menu_order ) = $this->add_category_term( $menu_items, $term, $products_item, $category_terms, $menu_order );
                }

                return array( $menu_items, $menu_order );
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
         * @return array{0: array<int, WP_Post|object>, 1: int}
         */
        private function add_category_term( array $menu_items, $term, $parent_item, array $category_terms, $menu_order ) {
                $new_item = $this->create_menu_item_from_term( $term, $parent_item, $menu_order + 1 );

                if ( ! $new_item ) {
                        return array( $menu_items, $menu_order );
                }

                $menu_order++;
                $menu_items[] = $new_item;

                $term_id = isset( $term->term_id ) ? (int) $term->term_id : 0;

                if ( $term_id && isset( $category_terms[ $term_id ] ) ) {
                        foreach ( $category_terms[ $term_id ] as $child_term ) {
                                list( $menu_items, $menu_order ) = $this->add_category_term( $menu_items, $child_term, $new_item, $category_terms, $menu_order );
                        }
                }

                return array( $menu_items, $menu_order );
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
