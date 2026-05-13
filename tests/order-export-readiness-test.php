<?php
/**
 * Verify order export skips checkout orders that are not ready for SoftOne.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

class WC_Order {
	private $items;
	private $email;
	private $first_name;
	private $last_name;

	public function __construct( array $args = array() ) {
		$this->items      = isset( $args['items'] ) ? $args['items'] : array();
		$this->email      = isset( $args['email'] ) ? $args['email'] : '';
		$this->first_name = isset( $args['first_name'] ) ? $args['first_name'] : '';
		$this->last_name  = isset( $args['last_name'] ) ? $args['last_name'] : '';
	}

	public function get_items( $types = array() ) {
		return $this->items;
	}

	public function get_billing_email() {
		return $this->email;
	}

	public function get_billing_first_name() {
		return $this->first_name;
	}

	public function get_billing_last_name() {
		return $this->last_name;
	}

	public function get_shipping_first_name() {
		return '';
	}

	public function get_shipping_last_name() {
		return '';
	}
}

require_once dirname( __DIR__ ) . '/includes/class-softone-order-sync.php';

class Softone_Order_Sync_Readiness_Test extends Softone_Order_Sync {
	public function readiness_reason( WC_Order $order, $status ) {
		return $this->get_order_export_not_ready_reason( $order, $status );
	}
}

function softone_order_readiness_assert( $condition, $message ) {
	if ( $condition ) {
		return;
	}

	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

$sync = ( new ReflectionClass( Softone_Order_Sync_Readiness_Test::class ) )->newInstanceWithoutConstructor();

softone_order_readiness_assert(
	'pending_checkout' === $sync->readiness_reason(
		new WC_Order(
			array(
				'items'      => array( 'line_item' ),
				'email'      => 'customer@example.com',
				'first_name' => 'Test',
				'last_name'  => 'Customer',
			)
		),
		'pending'
	),
	'Pending checkout orders should not export synchronously.'
);

softone_order_readiness_assert(
	'missing_line_items' === $sync->readiness_reason(
		new WC_Order(
			array(
				'email'      => 'customer@example.com',
				'first_name' => 'Test',
				'last_name'  => 'Customer',
			)
		),
		'processing'
	),
	'Orders without line items should be skipped.'
);

softone_order_readiness_assert(
	'missing_billing_email' === $sync->readiness_reason(
		new WC_Order(
			array(
				'items'      => array( 'line_item' ),
				'first_name' => 'Test',
				'last_name'  => 'Customer',
			)
		),
		'processing'
	),
	'Orders without a billing email should be skipped.'
);

softone_order_readiness_assert(
	'' === $sync->readiness_reason(
		new WC_Order(
			array(
				'items'      => array( 'line_item' ),
				'email'      => 'customer@example.com',
				'first_name' => 'Test',
				'last_name'  => 'Customer',
			)
		),
		'processing'
	),
	'Processing orders with line items and billing identity should export.'
);

echo "Order export readiness regression passed.\n";
