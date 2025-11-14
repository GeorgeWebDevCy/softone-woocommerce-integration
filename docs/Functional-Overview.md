# Softone WooCommerce Integration — Functional Overview (Code-Derived)

This document explains the plugin’s functionality based exclusively on the source code in this repository. It maps classes, hooks, settings, and data flows implemented by the codebase.

## Architecture

- Bootstrap: `softone-woocommerce-integration.php` defines activation/deactivation hooks, loads the core class, and boots the update checker. Version constant: `1.8.65`.
- Core class: `includes/class-softone-woocommerce-integration.php` wires up:
  - Internationalisation: `includes/class-softone-woocommerce-integration-i18n.php` loads the `softone-woocommerce-integration` textdomain.
  - Admin UI: `admin/class-softone-woocommerce-integration-admin.php` (settings, API tester, logs, import controls).
  - Public UI: `public/class-softone-woocommerce-integration-public.php` (asset enqueues) and menu population via `includes/class-softone-menu-populator.php`.
  - Services: `includes/class-softone-api-client.php`, `includes/class-softone-item-sync.php`, `includes/class-softone-item-cron-manager.php`, `includes/class-softone-item-stale-handler.php`, `includes/class-softone-customer-sync.php`, `includes/class-softone-order-sync.php`, `includes/class-softone-sku-image-attacher.php`, `includes/class-softone-sync-activity-logger.php`, `includes/class-softone-category-sync-logger.php`.
  - Loader: `includes/class-softone-woocommerce-integration-loader.php` registers actions/filters.
- Brand taxonomy: On `init`, `maybe_register_brand_taxonomy()` ensures a non-hierarchical `product_brand` taxonomy exists (slug `brand`, REST-enabled, nav menu-enabled). Filters: `softone_product_brand_taxonomy_objects`, `softone_product_brand_taxonomy_args`.

## Update Checker

- If available, boots `yahnis-elsts/plugin-update-checker` (vendor) to fetch releases from a VCS URL (default GitHub).
- Filters:
  - `softone_woocommerce_integration_update_url` (repository URL)
  - `softone_woocommerce_integration_update_branch` (branch, default `main`)
  - `softone_woocommerce_integration_use_release_assets` (bool)
  - Action: `softone_woocommerce_integration_update_checker_ready` with the checker instance

## Settings (Options API)

- Primary option: `softone_woocommerce_integration_settings`.
- Helper accessors in `includes/softone-woocommerce-integration-settings.php` expose:
  - Connection: `endpoint`, `username`, `password`, `app_id`, `company`, `branch`, `module`, `refid`.
  - Defaults: `default_saldoc_series`, `warehouse`, `areas`, `socurrency`, `trdcategory`.
  - Behaviour: `timeout` (int, seconds), `client_id_ttl` (int), `zero_stock_quantity_fallback` (`yes`/`no`), `backorder_out_of_stock_products` (`yes`/`no`).
  - Country mappings: `country_mappings` (array of ISO2 => SoftOne ID).
- Caching: accessor results are cached in-memory and flushed on add/update/delete of the option.

## Admin UI

- Menu entries (capability `manage_options`):
  - Top-level: “Softone Integration” with submenus: “Settings”, “Category Sync Logs”, “Sync Activity”, “API Tester”. Duplicates appear under the WooCommerce menu for quick access.
- Settings screen:
  - Registers/saves the settings above with server-side sanitization.
  - Connection test form: `admin_post_softone_wc_integration_test_connection`.
  - Item import controls (AJAX-driven):
    - “Run Item Import” (delta by default)
    - “Re-sync Categories & Menus” (forces full import and taxonomy refresh)
    - Progress bar and status messages (AJAX action: `softone_wc_integration_item_import`).
  - “Delete Main Menu” tool: batches deletion of the nav menu returned by `softone_wc_integration_get_main_menu_name()` (default “Main Menu”) via AJAX action `softone_wc_integration_delete_main_menu_batch` (also supports non-AJAX post to `softone_wc_integration_delete_main_menu`).
- Category Sync Logs screen: aggregates category-assignment log entries (via Woo logs and the dedicated logger).
- Sync Activity screen: streams JSON-lines entries from the file-based logger; polls at a configurable interval.
- API Tester screen: submits ad-hoc payloads to SoftOne; stores recent results per user in transients.
- Additional filters: `softone_wc_integration_item_import_max_batch_size`, `softone_wc_integration_sync_poll_interval`.

## SoftOne API Client

- Class: `includes/class-softone-api-client.php`.
- Responsibilities: wraps `wp_remote_post()` to call SoftOne services, handles login/auth/authenticated calls, request/response plumbing, and client ID caching.
- Key methods:
  - `login()`: POST `username`, `password`, optional `appId`; stores handshake values; requires both credentials.
  - `authenticate($clientID)`: POST handshake fields; returns a new/validated client ID.
  - `sql_data($sqlName, $arguments = [], $extra = [])`: calls `SqlData` with `SqlName`, optional `params` and `appId`.
  - `set_data($object, $data, $extra = [])`: calls `setData` with a payload like `{ OBJECT: [...], ... }`.
  - Internal client ID lifecycle: caches in transient `softone_woocommerce_integration_client_id` and persists metadata in option `softone_woocommerce_integration_client_meta`. TTL derived from responses (`EXPTIME`, `expires_in`, etc.) with fallback to `client_id_ttl`.
- Filters:
  - `softone_wc_integration_login_payload` (pre-login payload)
  - `softone_wc_integration_request_args` (HTTP args)
  - `softone_wc_integration_client_ttl` (client ID TTL)
  - `softone_wc_integration_settings` and `softone_wc_integration_endpoint` (normalize settings/endpoint)
- Error handling: throws `Softone_API_Client_Exception` with messages like `[SO-API-00X]` and contextual details.

## Item Synchronisation

- Classes: `includes/class-softone-item-sync.php` (core importer), `includes/class-softone-item-cron-manager.php` (scheduling), `includes/class-softone-item-stale-handler.php` (post-run cleanup).
- Scheduling:
  - Cron hook: `softone_wc_integration_sync_items`. Scheduled on `init` by `Softone_Item_Cron_Manager`; default interval `hourly` (filter `softone_wc_integration_item_sync_interval`).
  - Admin action: `softone_wc_integration_run_item_import` for manual triggers.
  - Last run timestamp: option `softone_wc_integration_last_item_sync`.
- Meta used on products/variations:
  - `_softone_mtrl_id` (SoftOne item MTRL ID)
  - `_softone_last_synced` (timestamp)
  - `_softone_payload_hash` (content hash)
  - `_softone_related_item_mtrl`, `_softone_related_item_mtrls` (related MTRLs)
- Import modes:
  - Delta mode: uses `pMins` based on `last_run` to fetch changes since the previous run.
  - Full mode: force via filters or admin “Re-sync Categories & Menus”; supports taxonomy refresh.
- Batch/asynchronous workflow:
  - `begin_async_import()` prepares the state: page size (filter `softone_wc_integration_item_sync_page_size`, default 250), flags, last run, stats, caches.
  - `run_async_import_batch($state, $batchSize = 25)` processes rows, updates state and stats, and persists item data.
- Product building highlights:
  - SKU detection from `sku`, `barcode`, or `code`.
  - Creates/updates simple/variable products; ensures colour variations when applicable.
  - Attributes and taxonomies:
    - Brand as product attribute `pa_brand` and taxonomy `product_brand`.
    - Colour handled via `pa_colour` or `pa_color` depending on installed taxonomies.
    - Safeguards ensure attribute taxonomies exist before assignment; logs when missing.
  - Categories: assigns `product_cat` terms; logs via `Softone_Category_Sync_Logger`.
  - Stock logic: honours settings:
    - `zero_stock_quantity_fallback = yes`: treat 0 as 1.
    - `backorder_out_of_stock_products = yes`: marks out-of-stock items as backorderable.
  - Images: `Softone_Sku_Image_Attacher::attach_gallery_from_sku($productId, $sku)` auto-sets featured/gallery images based on media filenames that start with the SKU (supports suffixes like `_1`, `-2`, spaces).
- Stale products handling:
  - `Softone_Item_Stale_Handler::handle($run_timestamp)` finds products managed by SoftOne that were not updated during this run and either drafts them or marks as out of stock.
  - Filters: `softone_wc_integration_stale_item_action` (`draft` or `stock_out`, default `stock_out`), `softone_wc_integration_stale_item_batch_size`.
- Performance/logging:
  - Caches for terms, attributes, and taxonomy lookups; optional memory limit bump via `softone_wc_integration_item_sync_memory_limit`.
  - Activity log entries emitted through `Softone_Sync_Activity_Logger`.

## Customer Synchronisation

- Class: `includes/class-softone-customer-sync.php`.
- Hooks: runs on customer creation, checkout-created/updated, account details saved, profile updates, and address saves.
- Identifiers:
  - User meta `_softone_trdr` stores SoftOne customer (TRDR) ID.
  - Code generation uses prefix `WEB` (e.g., `WEB000123`).
- Flow:
  - If `_softone_trdr` exists → performs an update via `set_data('CUSTOMER', ...)`.
  - Else tries to locate an existing SoftOne customer via `sql_data('getCustomers', ...)` using `CODE` and/or `EMAIL`.
  - If not found → creates a new SoftOne customer and stores TRDR back to user meta.
- Payload fields include name, email, phone, addresses, ZIP, city, SoftOne-specific `AREAS`, `SOCURRENCY`, `TRDCATEGORY`.
- Country mapping helpers/filters used when needed:
  - `softone_wc_integration_country_mappings` to normalize a mapping table
  - `softone_wc_integration_country_id` to derive the SoftOne country ID from a WooCommerce ISO code

## Order Export (SoftOne SALDOC)

- Class: `includes/class-softone-order-sync.php`.
- Triggers: on transitions to WooCommerce statuses from `['completed','processing']` (filter `softone_wc_integration_order_statuses`).
- Order meta:
  - `_softone_document_id` (resulting document ID)
  - `_softone_trdr` (copied if determined during export)
- Determine TRDR:
  - Prefer order meta; else use customer meta via `Softone_Customer_Sync::ensure_customer_trdr()`; for guests, may create a SoftOne customer using billing/shipping data (requires country mappings).
- Payload:
  - `SALDOC` header: includes `SERIES` (default from settings), `TRDR`, `VARCHAR01` (Woo order id), `TRNDATE` (order date), `COMMENTS` (compiled from payment/shipping methods, transaction and shipping ids, shipping/billing country).
  - `ITELINES`: collects order line items with `MTRL` from product meta `_softone_mtrl_id`, `QTY1`, and `COMMENTS1` line name. Skips lines without a known SoftOne `MTRL`.
  - `MTRDOC`: included when default `warehouse` is configured.
  - Filter: `softone_wc_integration_order_payload` to customize before transmission.
- Transmission and retry:
  - Calls `set_data('SALDOC', $payload)` with retries. Filters: `softone_wc_integration_order_sync_max_attempts`, `softone_wc_integration_order_sync_retry_delay`.
  - On success, stores `_softone_document_id` and adds private order notes.

## Public Menu Population

- Class: `includes/class-softone-menu-populator.php`.
- Hook: filters `wp_nav_menu_objects`.
- Scope: only acts on the navigation menu identified by `softone_wc_integration_get_main_menu_name()` (defaults to `Main Menu`; filterable via `softone_wc_integration_main_menu_name`).
- Behaviour:
  - Removes prior generated items marked with class `softone-dynamic-menu-item`.
  - Locates placeholder menu items (defaults to titles `Brands` and `Products`; filterable via `softone_wc_integration_menu_placeholder_titles`).
  - Adds child items under `Brands` for all `product_brand` terms (sorted by name).
  - Adds child items under `Products` for the full `product_cat` tree, excluding WooCommerce’s default “Uncategorized” (children re-parented to top-level).
  - Emits activity log entries when dynamic items are injected.
  - Placeholder detection can be extended via `softone_wc_integration_menu_placeholder_config` to match menu item classes or metadata, making translations or bespoke placeholders possible without renaming the defaults.

## Sync Activity Logging

- Class: `includes/class-softone-sync-activity-logger.php`.
- Persists JSON lines to: `wp-content/uploads/softone-sync-logs/softone-sync-activity.log`.
- API:
  - `log($channel, $action, $message, array $context = [])`
  - `get_entries($limit)` and `get_entries_since($timestamp, $limit)`
  - `get_metadata()` and `clear()`
- Used across item import, menu populator, and admin monitors.

## SKU-Based Image Attachment

- Class: `includes/class-softone-sku-image-attacher.php`.
- For a given product ID and SKU, finds image attachments whose filenames begin with the SKU (supports separators and numeric suffixes: `_1`, `-2`, spaces; extensions: jpg, jpeg, png, webp, gif).
- Featured image selection priority: `_1` first, then no suffix, then ascending numeric suffix.
- Sets featured image, replaces gallery, and re-parents images to the product.

## Developer Hooks (Quick Reference)

- Update checker: `softone_woocommerce_integration_update_url`, `softone_woocommerce_integration_update_branch`, `softone_woocommerce_integration_use_release_assets`, action `softone_woocommerce_integration_update_checker_ready`.
- Brand taxonomy: `softone_product_brand_taxonomy_objects`, `softone_product_brand_taxonomy_args`.
- API client: `softone_wc_integration_login_payload`, `softone_wc_integration_request_args`, `softone_wc_integration_client_ttl`, `softone_wc_integration_settings`, `softone_wc_integration_endpoint`.
- Item sync: `softone_wc_integration_item_sync_interval`, `softone_wc_integration_item_sync_page_size`, `softone_wc_integration_item_sync_force_full`, `softone_wc_integration_item_sync_memory_limit`, `softone_wc_integration_item_sync_max_pages`, `softone_wc_integration_stale_item_action`, `softone_wc_integration_stale_item_batch_size`, `softone_wc_integration_sku_unique_attempts`.
- Orders: `softone_wc_integration_order_statuses`, `softone_wc_integration_order_payload`, `softone_wc_integration_order_sync_max_attempts`, `softone_wc_integration_order_sync_retry_delay`.
- Admin: `softone_wc_integration_item_import_max_batch_size`, `softone_wc_integration_sync_poll_interval`.
- Country mapping: `softone_wc_integration_country_mappings`, `softone_wc_integration_country_id`.

## Tests and Utilities

- `tests/` contains lightweight regression scripts reflecting historic bugs (e.g., taxonomy refresh, login handshake, menu population, password sanitization).

## Uninstall

- `uninstall.php` contains the standard skeleton; it currently exits on non-WordPress uninstall contexts.
