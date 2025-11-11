<?php
/**
 * Process trace diagnostic page markup.
 *
 * @package Softone_Woocommerce_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
exit;
}
?>
<div class="wrap softone-process-trace" data-softone-process-trace>
<h1><?php esc_html_e( 'Process Trace', 'softone-woocommerce-integration' ); ?></h1>
<p class="description"><?php esc_html_e( 'Run a detailed Softone synchronisation trace to observe authentication, product import, and variation decisions step by step.', 'softone-woocommerce-integration' ); ?></p>

<div class="softone-process-trace__panel">
<div class="softone-process-trace__controls">
<div class="softone-process-trace__options" data-trace-options>
<label class="softone-process-trace__option">
<input type="checkbox" value="1" data-trace-option="force_full_import" />
<span class="softone-process-trace__option-label"><?php esc_html_e( 'Force full import', 'softone-woocommerce-integration' ); ?></span>
<span class="description"><?php esc_html_e( 'Ignore incremental syncing and request the complete catalogue.', 'softone-woocommerce-integration' ); ?></span>
</label>
<label class="softone-process-trace__option">
<input type="checkbox" value="1" data-trace-option="force_taxonomy_refresh" />
<span class="softone-process-trace__option-label"><?php esc_html_e( 'Refresh taxonomy assignments', 'softone-woocommerce-integration' ); ?></span>
<span class="description"><?php esc_html_e( 'Rebuild attribute and category relationships during the trace.', 'softone-woocommerce-integration' ); ?></span>
</label>
</div>
<button type="button" class="button button-primary" data-trace-trigger><?php esc_html_e( 'Run process trace', 'softone-woocommerce-integration' ); ?></button>
<span class="spinner" data-trace-spinner aria-hidden="true"></span>
</div>
<div class="softone-process-trace__status" data-trace-status role="status" aria-live="polite"></div>
</div>

<section class="softone-process-trace__summary" data-trace-summary hidden>
<h2><?php esc_html_e( 'Summary', 'softone-woocommerce-integration' ); ?></h2>
<dl class="softone-process-trace__summary-grid">
<div>
<dt><?php esc_html_e( 'Status', 'softone-woocommerce-integration' ); ?></dt>
<dd data-trace-summary="status"></dd>
</div>
<div>
<dt><?php esc_html_e( 'Started at', 'softone-woocommerce-integration' ); ?></dt>
<dd data-trace-summary="started_at"></dd>
</div>
<div>
<dt><?php esc_html_e( 'Finished at', 'softone-woocommerce-integration' ); ?></dt>
<dd data-trace-summary="finished_at"></dd>
</div>
<div>
<dt><?php esc_html_e( 'Duration', 'softone-woocommerce-integration' ); ?></dt>
<dd data-trace-summary="duration"></dd>
</div>
<div>
<dt><?php esc_html_e( 'Processed', 'softone-woocommerce-integration' ); ?></dt>
<dd data-trace-summary="processed"></dd>
</div>
<div>
<dt><?php esc_html_e( 'Created', 'softone-woocommerce-integration' ); ?></dt>
<dd data-trace-summary="created"></dd>
</div>
<div>
<dt><?php esc_html_e( 'Updated', 'softone-woocommerce-integration' ); ?></dt>
<dd data-trace-summary="updated"></dd>
</div>
<div>
<dt><?php esc_html_e( 'Skipped', 'softone-woocommerce-integration' ); ?></dt>
<dd data-trace-summary="skipped"></dd>
</div>
<div>
<dt><?php esc_html_e( 'Stale products updated', 'softone-woocommerce-integration' ); ?></dt>
<dd data-trace-summary="stale_processed"></dd>
</div>
</dl>
</section>

<section class="softone-process-trace__log" aria-label="<?php esc_attr_e( 'Trace output', 'softone-woocommerce-integration' ); ?>">
<p class="softone-process-trace__empty" data-trace-empty><?php esc_html_e( 'Run a trace to see step-by-step activity.', 'softone-woocommerce-integration' ); ?></p>
<ul class="softone-process-trace__entries" data-trace-output></ul>
</section>
</div>
