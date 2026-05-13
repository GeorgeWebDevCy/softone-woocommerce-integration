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

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! class_exists( 'Softone_Customer_Sync' ) ) {
	class Softone_Customer_Sync {
		const CODE_PREFIX = 'C';
		const CODE_WIDTH = 5;
		const CODE_RANGE_REGISTERED = '8';
		const CODE_RANGE_GUEST = '9';
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
	public $fake_rows = array();

	public function readiness_reason( WC_Order $order, $status ) {
		return $this->get_order_export_not_ready_reason( $order, $status );
	}

	public function available_code( WC_Order $order, $range_digit, $seed_id ) {
		$this->api_client = new class( $this ) {
			private $sync;

			public function __construct( $sync ) {
				$this->sync = $sync;
			}

			public function sql_data( $query, array $filters = array() ) {
				return array( 'rows' => $this->sync->fake_rows );
			}
		};

		return $this->find_available_order_customer_code( $order, $range_digit, $seed_id );
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

$sync->fake_rows = array(
	array(
		'TRDR'  => '2967',
		'CODE'  => 'C00006',
		'EMAIL' => 'spyros@cydigitalnet.com',
	),
);

softone_order_readiness_assert(
	'C00044' === $sync->available_code( new WC_Order(), '8', 44 ),
	'Unfiltered SoftOne getCustomers rows must not make every generated customer code look taken.'
);

echo "Order export readiness regression passed.\n";
