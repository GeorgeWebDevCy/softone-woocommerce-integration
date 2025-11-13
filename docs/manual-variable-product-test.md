# Manual verification – variable product colour sync

Follow these steps to confirm that enabling variable product handling now produces colour-based variations during an import run.

1. **Enable the feature flag**
   * Navigate to **Softone Integration → Settings**, open the **Stock Behaviour** section, check **Enable variable product handling**, and save the settings.
2. **Prepare sample catalogue data**
   * Ensure Softone exposes at least two related items that share a `related_item_mtrl` relationship and distinct colour attribute values.
   * Confirm each item reports price, stock quantity, SKU, and the Softone material identifier (`MTRL`).
3. **Run an import**
   * Trigger **Softone Integration → Run Import Now** or wait for the scheduled sync to execute.
4. **Inspect the parent product**
   * Locate the WooCommerce product representing the parent Softone material.
   * Verify the product type has switched to **Variable product** and that the Colour attribute lists every related colour term.
5. **Validate generated variations**
   * Open the Variations tab and confirm a variation exists for each related Softone material.
   * Check that each variation inherits the expected SKU, price, stock quantity, and backorder status from Softone.
6. **Confirm single-product drafts**
   * The original standalone products for each Softone material should now be in the **Draft** post status, leaving the variable parent as the published catalogue entry.

Successful completion of these steps demonstrates that asynchronous imports create and maintain the required colour variations when the feature flag is enabled.
