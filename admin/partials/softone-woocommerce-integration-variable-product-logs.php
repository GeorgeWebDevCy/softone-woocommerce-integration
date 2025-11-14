<?php
/**
 * Variable product log viewer template.
 *
 * @package Softone_Woocommerce_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
exit;
}

$entries_for_display = isset( $entries_for_display ) && is_array( $entries_for_display ) ? $entries_for_display : array();
$error_state         = isset( $error_state ) ? (string) $error_state : '';
$entries_limit       = isset( $entries_limit ) ? (int) $entries_limit : 0;
$page_size_display   = isset( $page_size_display ) ? (int) $page_size_display : 0;
$has_entries         = ! empty( $entries_for_display );
?>
<div class="wrap softone-variable-product-logs">
<h1><?php esc_html_e( 'Variable Product Sync Logs', 'softone-woocommerce-integration' ); ?></h1>
<p class="description"><?php esc_html_e( 'Inspect variation creation attempts recorded during Softone synchronisation.', 'softone-woocommerce-integration' ); ?></p>

<?php if ( '' !== $error_state ) : ?>
<div class="notice notice-error"><p><?php echo esc_html( $error_state ); ?></p></div>
<?php endif; ?>

<p class="softone-variable-logs__summary"><?php echo esc_html( sprintf( __( 'Displaying up to %1$d recent entries in batches of %2$d.', 'softone-woocommerce-integration' ), $entries_limit, $page_size_display ) ); ?></p>

<div class="softone-variable-logs" data-softone-variable-logs>
<table class="widefat fixed striped">
<thead>
<tr>
<th scope="col"><?php esc_html_e( 'Time', 'softone-woocommerce-integration' ); ?></th>
<th scope="col"><?php esc_html_e( 'Action', 'softone-woocommerce-integration' ); ?></th>
<th scope="col"><?php esc_html_e( 'Message', 'softone-woocommerce-integration' ); ?></th>
<th scope="col"><?php esc_html_e( 'Failure reason', 'softone-woocommerce-integration' ); ?></th>
<th scope="col"><?php esc_html_e( 'Context', 'softone-woocommerce-integration' ); ?></th>
</tr>
</thead>
<tbody data-logs-body>
<?php
$empty_row_hidden = $has_entries ? ' hidden' : '';
?>
<tr data-logs-empty<?php echo $empty_row_hidden; ?>>
<td colspan="5"><?php esc_html_e( 'No variable product activity has been recorded yet.', 'softone-woocommerce-integration' ); ?></td>
</tr>
<?php if ( $has_entries ) : ?>
<?php foreach ( $entries_for_display as $entry ) :
$time            = isset( $entry['time'] ) ? (string) $entry['time'] : '';
$action          = isset( $entry['action'] ) ? (string) $entry['action'] : '';
$message         = isset( $entry['message'] ) ? (string) $entry['message'] : '';
$reason          = isset( $entry['reason'] ) ? (string) $entry['reason'] : '';
$context_display = isset( $entry['context_display'] ) ? (string) $entry['context_display'] : '';
?>
<tr>
<td><?php echo esc_html( $time ); ?></td>
<td><?php echo esc_html( $action ); ?></td>
<td><?php echo esc_html( $message ); ?></td>
<td><?php echo '' !== $reason ? esc_html( $reason ) : esc_html__( 'Not specified', 'softone-woocommerce-integration' ); ?></td>
<td class="softone-variable-logs__context">
<?php if ( '' !== $context_display ) : ?>
<pre><?php echo esc_html( $context_display ); ?></pre>
<?php else : ?>
<span><?php esc_html_e( 'No additional context provided.', 'softone-woocommerce-integration' ); ?></span>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>

<div class="softone-variable-logs__pagination" data-logs-pagination hidden>
<button type="button" class="button" data-logs-prev><?php esc_html_e( 'Previous', 'softone-woocommerce-integration' ); ?></button>
<span data-logs-page-indicator></span>
<button type="button" class="button" data-logs-next><?php esc_html_e( 'Next', 'softone-woocommerce-integration' ); ?></button>
</div>
</div>

<noscript>
<p><?php esc_html_e( 'JavaScript is required to paginate the log table.', 'softone-woocommerce-integration' ); ?></p>
</noscript>
</div>
