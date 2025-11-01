<?php
/**
 * Regression tests for Softone_Item_Sync category hierarchy handling.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ );
}

require_once __DIR__ . '/../includes/class-softone-api-client.php';
require_once __DIR__ . '/../includes/class-softone-item-sync.php';

if ( ! function_exists( 'sanitize_title' ) ) {
    function sanitize_title( $value ) {
        $value = strtolower( (string) $value );
        $value = preg_replace( '/[^a-z0-9\-]+/', '-', $value );

        return trim( (string) $value, '-' );
    }
}

class Softone_Item_Sync_Test_Double extends Softone_Item_Sync {
    /**
     * @var array<int, array{name: string, parent: int}>
     */
    public $ensured_terms = array();

    public function __construct() {
        $this->reset_caches();
    }

    /**
     * @param array $data Normalised row data.
     *
     * @return array<int>
     */
    public function call_prepare_category_ids( array $data ) {
        $this->ensured_terms = array();

        return $this->prepare_category_ids( $data );
    }

    protected function ensure_term( $name, $tax, $parent = 0 ) {
        $term_id               = count( $this->ensured_terms ) + 1;
        $this->ensured_terms[] = array(
            'name'   => (string) $name,
            'parent' => (int) $parent,
        );

        return $term_id;
    }

    protected function is_uncategorized_term( $name ) {
        return false;
    }
}

function softone_assert_same( $expected, $actual, $message ) {
    if ( $expected !== $actual ) {
        fwrite( STDERR, $message . PHP_EOL );
        fwrite( STDERR, 'Expected: ' . var_export( $expected, true ) . PHP_EOL );
        fwrite( STDERR, 'Actual:   ' . var_export( $actual, true ) . PHP_EOL );
        exit( 1 );
    }
}

$sync = new Softone_Item_Sync_Test_Double();

$result = $sync->call_prepare_category_ids(
    array(
        'commercategory_name' => 'Parent --> Child --> Grandchild',
    )
);
softone_assert_same( array( 1, 2, 3 ), $result, 'Hierarchy string should create cascading category IDs.' );
softone_assert_same(
    array(
        array( 'name' => 'Parent', 'parent' => 0 ),
        array( 'name' => 'Child', 'parent' => 1 ),
        array( 'name' => 'Grandchild', 'parent' => 2 ),
    ),
    $sync->ensured_terms,
    'Hierarchy levels should retain their parent linkage.'
);

$result = $sync->call_prepare_category_ids(
    array(
        'commercategory_name' => 'Parent',
        'subcategory_name'    => 'Child',
    )
);
softone_assert_same( array( 1, 2 ), $result, 'Separate category fields should still cascade correctly.' );
softone_assert_same(
    array(
        array( 'name' => 'Parent', 'parent' => 0 ),
        array( 'name' => 'Child', 'parent' => 1 ),
    ),
    $sync->ensured_terms,
    'Separate category fields should keep the declared parent.'
);

$result = $sync->call_prepare_category_ids(
    array(
        'subcategory_name' => 'Top / Mid / Leaf',
    )
);
softone_assert_same( array( 1, 2, 3 ), $result, 'Alternative separators should be normalised.' );
softone_assert_same(
    array(
        array( 'name' => 'Top', 'parent' => 0 ),
        array( 'name' => 'Mid', 'parent' => 1 ),
        array( 'name' => 'Leaf', 'parent' => 2 ),
    ),
    $sync->ensured_terms,
    'Normalised separators should retain ordering.'
);

echo 'OK' . PHP_EOL;
