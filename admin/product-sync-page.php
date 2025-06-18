<?php
/**
 * Displays the AJAX product sync page for the Softone WooCommerce Integration.
 */
function softone_products_page() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Product Sync', 'softone-woocommerce-integration'); ?></h1>
        <button id="sync-products-btn" class="button button-primary"><?php esc_html_e('Start Sync', 'softone-woocommerce-integration'); ?></button>

        <div id="sync-progress" style="margin-top: 20px; width: 100%; background: #eee; height: 20px; border-radius: 4px; overflow: hidden;">
            <div id="sync-bar" style="height: 100%; background: #0073aa; width: 0%; transition: width 0.3s;"></div>
        </div>

        <pre id="sync-log" style="background: #111; color: #0f0; padding: 10px; margin-top: 20px; height: 300px; overflow: auto; font-size: 13px;"></pre>
    </div>
    <script>
document.addEventListener('DOMContentLoaded', () => {
    const logEl = document.getElementById('sync-log');
    const bar = document.getElementById('sync-bar');
    const btn = document.getElementById('sync-products-btn');

    let offset = 0;
    let added = 0;
    let updated = 0;
    let failed = 0;

    function syncNext() {
        fetch(ajaxurl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: 'softone_sync_products',
                offset,
                _ajax_nonce: softone_sync_products.nonce
            })
        })
        .then(res => res.json())
        .then(data => {
            offset = data.offset ?? 0;
            added += data.added ?? 0;
            updated += data.updated ?? 0;
            failed += data.failed ?? 0;

            bar.style.width = data.progress + '%';

            // Log output (no extra \n)
            logEl.textContent += data.message;
            if (!data.message.endsWith("\n")) logEl.textContent += "\n";

            logEl.scrollTop = logEl.scrollHeight;

            if (data.done) {
                logEl.textContent += `\n✅ Sync complete: ${added} added, ${updated} updated, ${failed} failed.\n`;
                btn.disabled = false;
            } else {
                syncNext();
            }
        })
        .catch(err => {
            logEl.textContent += '❌ AJAX error: ' + err.message + '\n';
            btn.disabled = false;
        });
    }

    btn.addEventListener('click', () => {
        btn.disabled = true;
        logEl.textContent = '🚀 Starting product sync...\n';
        bar.style.width = '0%';
        offset = 0;
        added = updated = failed = 0;
        syncNext();
    });
});
</script>

    <?php
}
