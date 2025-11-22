<?php
/**
 * Order export log viewer markup.
 *
 * @package Softone_Woocommerce_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
exit;
}

$order_export_entries  = isset( $order_export_entries ) ? (array) $order_export_entries : array();
$order_export_metadata = isset( $order_export_metadata ) ? (array) $order_export_metadata : array();
$order_export_error    = isset( $order_export_error ) ? (string) $order_export_error : '';
$order_export_limit    = isset( $order_export_limit ) ? (int) $order_export_limit : 0;
?>
<div class="wrap softone-order-export-logs">
<h1><?php esc_html_e( 'Order Export Logs', 'softone-woocommerce-integration' ); ?></h1>
<p class="description"><?php esc_html_e( 'Track when WooCommerce orders trigger the SoftOne export workflow and inspect the payloads that were sent to the API.', 'softone-woocommerce-integration' ); ?></p>

<?php if ( '' !== $order_export_error ) : ?>
<div class="notice notice-error"><p><?php echo esc_html( $order_export_error ); ?></p></div>
<?php endif; ?>

<?php if ( $order_export_limit > 0 ) : ?>
<p><?php printf( esc_html__( 'Displaying up to %d recent events.', 'softone-woocommerce-integration' ), (int) $order_export_limit ); ?></p>
<?php endif; ?>

<?php if ( ! empty( $order_export_metadata ) ) : ?>
<p>
<?php if ( ! empty( $order_export_metadata['exists'] ) ) : ?>
<?php
printf(
esc_html__( 'Log file: %1$s (%2$s)', 'softone-woocommerce-integration' ),
isset( $order_export_metadata['file_path'] ) ? esc_html( $order_export_metadata['file_path'] ) : '',
isset( $order_export_metadata['size_display'] ) ? esc_html( $order_export_metadata['size_display'] ) : ''
);
?>
<?php else : ?>
<?php esc_html_e( 'The log file will be created automatically when the next event occurs.', 'softone-woocommerce-integration' ); ?>
<?php endif; ?>
</p>
<?php endif; ?>

<table class="widefat fixed striped">
<thead>
<tr>
<th scope="col"><?php esc_html_e( 'Time', 'softone-woocommerce-integration' ); ?></th>
<th scope="col"><?php esc_html_e( 'Action', 'softone-woocommerce-integration' ); ?></th>
<th scope="col"><?php esc_html_e( 'Message', 'softone-woocommerce-integration' ); ?></th>
<th scope="col"><?php esc_html_e( 'Context', 'softone-woocommerce-integration' ); ?></th>
</tr>
</thead>
<tbody>
<?php if ( empty( $order_export_entries ) ) : ?>
<tr>
<td colspan="4"><?php esc_html_e( 'No order export events have been recorded yet.', 'softone-woocommerce-integration' ); ?></td>
</tr>
<?php else : ?>
<?php foreach ( $order_export_entries as $entry ) : ?>
<tr>
<td><?php echo isset( $entry['time'] ) ? esc_html( $entry['time'] ) : ''; ?></td>
<td><?php echo isset( $entry['action'] ) ? esc_html( $entry['action'] ) : ''; ?></td>
<td><?php echo isset( $entry['message'] ) ? esc_html( $entry['message'] ) : ''; ?></td>
<td>
<?php if ( ! empty( $entry['request_display'] ) || ! empty( $entry['response_display'] ) ) : ?>
	<?php if ( ! empty( $entry['request_display'] ) ) : ?>
		<p><strong><?php esc_html_e( 'Request', 'softone-woocommerce-integration' ); ?></strong></p>
		<pre class="softone-order-export-logs__context"><code><?php echo esc_html( $entry['request_display'] ); ?></code></pre>
	<?php endif; ?>
	<?php if ( ! empty( $entry['response_display'] ) ) : ?>
		<p><strong><?php esc_html_e( 'Response', 'softone-woocommerce-integration' ); ?></strong></p>
		<pre class="softone-order-export-logs__context"><code><?php echo esc_html( $entry['response_display'] ); ?></code></pre>
	<?php endif; ?>
	<?php if ( ! empty( $entry['context_display'] ) ) : ?>
		<p><strong><?php esc_html_e( 'Context', 'softone-woocommerce-integration' ); ?></strong></p>
		<pre class="softone-order-export-logs__context"><code><?php echo esc_html( $entry['context_display'] ); ?></code></pre>
	<?php endif; ?>
<?php elseif ( ! empty( $entry['context_display'] ) ) : ?>
	<pre class="softone-order-export-logs__context"><code><?php echo esc_html( $entry['context_display'] ); ?></code></pre>
<?php else : ?>
<span><?php esc_html_e( 'No additional context provided.', 'softone-woocommerce-integration' ); ?></span>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>
