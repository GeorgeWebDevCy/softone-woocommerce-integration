=== Softone WooCommerce Integration ===
Contributors: orionaselite
Donate link: https://www.georgenicolaou.me//
Tags: softone, erp, woocommerce, integration, inventory, orders, api
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.10.41
=======
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Softone WooCommerce Integration connects your WooCommerce storefront with your SoftOne ERP installation so stock, customers, and orders move between the two platforms without manual data entry.

== Description ==

Softone WooCommerce Integration keeps your catalogue, shoppers, and sales aligned between WooCommerce and SoftOne. The plugin authenticates against the SoftOne Web Services API, imports item information, exports WooCommerce orders, and offers tools for quickly validating credentials and monitoring synchronisation health.

= Core capabilities =

* **Item synchronisation** – Imports SoftOne items on a recurring schedule, updating prices, stock, categories, and attributes in WooCommerce.
* **Customer synchronisation** – Re-uses or creates SoftOne customer records while exporting WooCommerce orders, including guest checkout flows.
* **Order export** – Sends WooCommerce orders to SoftOne SALDOC documents once orders reach the configured statuses and records the resulting document ID back on the order.
* **API tester** – Provides an in-dashboard tester with sample payload presets so administrators can validate credentials, run ad-hoc calls, and inspect the raw responses returned by SoftOne.
* **Category log viewer** – Surfaces category synchronisation entries aggregated from WooCommerce logs to make diagnosing catalogue imports easier.
* **Menu population helpers** – Optionally extend WooCommerce menu structures to include synced SoftOne product categories, even when the site does not expose brand taxonomies. The Appearance → Menus preview and the public storefront both inject the virtual Softone category and brand entries beneath the configured placeholders at runtime via the `wp_get_nav_menu_items` filter so editors can verify the tree before saving and shoppers always see the latest taxonomy structure. Placeholder menu items can be translated or retitled via the `softone_wc_integration_menu_placeholder_titles` filter, or matched via metadata using `softone_wc_integration_menu_placeholder_config`.

= Prerequisites =

* A SoftOne installation with web services enabled and credentials for an API user (endpoint URL, username, password, optional App ID, company, branch, module, and reference ID).
* WordPress 6.0 or newer with WooCommerce 7.5 or later activated.
* PHP 7.4+ with the cURL and JSON extensions enabled.
* WP-Cron (or a system cron alternative) for unattended catalogue imports.

== Installation ==

1. Download the plugin archive or deploy the package via Composer (`composer require georgenicolaou/softone-woocommerce-integration`).
2. Upload the plugin to `/wp-content/plugins/` or install it from the Plugins → Add New screen.
3. Activate **Softone WooCommerce Integration**.
4. (Optional) Run `composer install` within the plugin directory to ensure the bundled update checker is available when developing from source.
5. Navigate to **Softone Integration → Settings** to enter your SoftOne credentials and default configuration before running your first sync.

== Configuration ==

The settings screen groups related options into three sections:

* **Softone API Credentials** – Provide the connection details returned by SoftOne: Endpoint URL, Username, Password, App ID, Company, Branch, Module, Ref ID, Default SALDOC Series, Default Warehouse, Default AREAS, Default SOCURRENCY, Default TRDCATEGORY, and Country Mappings. All credential fields accept alphanumeric strings. The endpoint URL is normalised without trailing slashes. Passwords are stored with minimal filtering so special characters are preserved.
* **Country Mappings** – Map WooCommerce ISO 3166-1 alpha-2 country codes to the numeric identifiers expected by SoftOne. Enter one mapping per line using the `GR:123` format; blank lines and malformed entries are ignored. Filters `softone_wc_integration_country_mappings` and `softone_wc_integration_country_id` let developers adjust mappings programmatically.
* **Stock Behaviour** – Choose between treating zero SoftOne stock as “1” to keep items purchasable, or marking depleted items as backorderable. Only one mode can be active at a time, and you can also enable colour-driven variable product handling from the same section so related SoftOne items publish as WooCommerce variations.

After saving credentials, visit the **API Tester** submenu to validate connectivity and inspect sample item and order payloads. The tester records results per user and supports retrying requests without leaving the page.

== Frequently Asked Questions ==

= How do I trigger my first item import? =
Use the **Run Import Now** button available under **Softone Integration → Settings**. The import will also run automatically on the schedule defined by the `softone_wc_integration_item_sync_interval` filter (hourly by default) once WP-Cron executes.

= Which WooCommerce order statuses create SoftOne documents? =
By default orders export when they transition to `processing` or `completed`. Developers can adjust the list with the `softone_wc_integration_order_statuses` filter. Successful exports add a private order note containing the SoftOne document number.

= Where can I review category synchronisation activity? =
Open **Softone Integration → Category Sync Logs** to review the latest entries gathered from WooCommerce log files. The viewer lists the source log file, severity, timestamp, and raw context for each matching entry.

= Can I customise the API calls before they are sent? =
Yes. Filters such as `softone_wc_integration_order_payload`, `softone_wc_integration_customer_payload`, and `softone_wc_integration_login_payload` allow developers to modify payloads, override defaults, and append diagnostic metadata.

== Screenshots ==

1. Softone Integration settings screen showing credential fields, stock behaviour toggles, and country mapping textarea.
2. API Tester interface displaying the request form, preset selector, and formatted response output.
3. Category Sync Logs list summarising recent synchronisation messages from WooCommerce logs.

== Troubleshooting ==

* **Authentication failures** – Recheck the endpoint URL formatting, confirm that the API user has access to the specified company/branch/module, and verify that firewalls allow outbound connections to the SoftOne server. Use the API tester to validate credentials with a simple `authenticate` request.
* **Orders not exporting** – Ensure the Default SALDOC Series is configured, confirm that the customer synchronisation completed (look for notes on the order), and inspect the WooCommerce order notes/logs for `[SO-ORD-###]` messages indicating what failed.
* **No categories appearing in menus** – Confirm that WooCommerce’s product categories exist and that recent item imports completed. The Category Sync Logs screen highlights any taxonomy creation issues.
* **Verify Softone placeholders** – Visit **Appearance → Menus** and load the configured main menu to confirm the virtual Softone category and brand entries appear beneath their placeholders. Both the admin preview and the public menu inject those runtime links under their placeholders, so confirming the tree here mirrors what visitors see on the storefront.
* **Cron events not running** – Verify WP-Cron execution by visiting `wp-cron.php` manually or configuring a real cron job. You can reschedule events programmatically via `Softone_Item_Cron_Manager::schedule_event()`.

== Changelog ==

= 1.10.40 =
* Change: Log SoftOne customer lookups in the order export log and carry customer context (TRDR/branch) into SALDOC payloads to match the documented request shape.

= 1.10.41 =
* Change: Cast SALDOC numeric fields (series, TRDR, warehouse, item lines) to integers when possible to mirror the documented request format and avoid SoftOne "Operation aborted" errors in strict environments.

= 1.10.39 =
* Change: Look up SoftOne customers by email before exporting orders so existing records (or newly created ones) populate SALDOC payloads with the correct TRDR and customer context.

= 1.10.38 =
* Feature: Add a SoftOne Customers admin screen to run the getCustomers SqlData query and review the current ERP customers directly from wp-admin.

= 1.10.37 =
* Maintenance: Revert the codebase to the 1.10.31 release while preparing a new package version.

= 1.10.31 =
* Fix: Send the SoftOne AppID using consistent casing across all payloads and documentation references.

= 1.10.30 =
* Fix: Treat SoftOne "Operation aborted" responses as expired sessions and refresh the client ID before retrying SALDOC exports.

= 1.10.29 =
* Fix: When SoftOne reports a duplicate customer code during sync, reuse the matching SoftOne record instead of failing the export.

= 1.10.28 =
* Maintenance: Prepare the plugin metadata for the 1.10.28 release.

= 1.10.27 =
* Maintenance: Bump the plugin version for the next release.

= 1.10.26 =
* Tweak: Log each SoftOne SALDOC export attempt, including successes and failures, so administrators can trace retries and last errors from the Order Export Logs screen.

= 1.10.25 =
* Feature: Add an Order Export Logs admin screen so store owners can review when WooCommerce orders hit the SoftOne exporter alongside the recorded payloads.
* Feature: Capture the payloads sent to SoftOne when creating customers (including guest checkouts) and SALDOC documents, making it easier to diagnose why a customer or document was not created.

= 1.10.24 =
* Fix: Read Softone stock quantities from keys such as `Stock QTY` so WooCommerce inventory matches the ERP payload casing.

= 1.10.23 =
* Tweak: Increase the Specifications heading font size to 36px so the section title remains prominent in product descriptions.

= 1.10.22 =
* Change: Raise the Specifications heading to an H2 when building product descriptions so specification details stand out more clearly.

= 1.10.21 =
* Change: Insert a “Specifications” heading between imported long descriptions and specification details in WooCommerce product descriptions.

= 1.10.20 =
* Fix: Preserve HTML markup from Softone long and short descriptions during product imports.

= 1.10.19 =
* Change: Build product descriptions using the Softone long description followed by the Softone Item Specifications field when available.

= 1.10.18 =
* Change: Prepend the Softone Item Specifications field to WooCommerce product descriptions while retaining the long description and notes fallback from earlier releases.

= 1.10.17 =
* Fix: Restore WooCommerce product descriptions when Softone payloads omit the `cccsocyre2` and `cccsocylodes` fields by falling back to legacy description values.

= 1.10.16 =
* Change: Populate WooCommerce product descriptions using `cccsocyre2` followed by `cccsocylodes` on a new line when provided by Softone.

= 1.10.15 =
* Fix: Build injected Softone menu entries using WordPress navigation helpers so brand and category placeholders populate with the correct data.

= 1.10.14 =
* Fix: Treat the configured main menu name case-insensitively (including slugged variants) so Softone menu items appear in wp-admin when the saved menu label differs slightly.

= 1.10.13 =
* Fix: Correct the injected Softone menu hierarchy so generated category and brand placeholders nest under the intended parents before saves.

= 1.10.12 =
* Fix: Guard nav menu saves on the server side so Softone placeholder entries are stripped from the POST payload, logged, and never passed to `wp_update_nav_menu_item`.

= 1.10.11 =
* Fix: Prevent WordPress from submitting Softone placeholder menu items by disabling their form inputs on Appearance → Menus so menu saves no longer trigger “The given object ID is not that of a menu item.”.

= 1.10.10 =
* Feature: Inject Softone categories and brands on the public storefront at runtime via `wp_get_nav_menu_items` so menu placeholders always expand without saving generated items.

= 1.10.9 =
* Fix: Skip injecting virtual Softone menu items during menu save requests so WordPress no longer triggers “The given object ID is not that of a menu item.” errors.

= 1.10.8 =
* Fix: Prevent the “The given object ID is not that of a menu item.” error when saving menus by marking the previewed Softone entries as unsaved placeholders.

= 1.10.7 =
* Change: Stop injecting Softone menu items on the storefront now that the admin preview populates the menu tree, ensuring the public menu reflects the saved structure.

= 1.10.x =
* Fix: Correct the Softone Integration admin menu registration so the submenu lands on the intended settings page.

= 1.10.5 =
* Fix: Run the menu item preparation helpers inside Appearance → Menus so the dynamically injected Softone entries mirror the public navigation preview.

= 1.10.4 =
* Fix: Prevent fatal errors by ensuring the menu populator registers `has_processed_menu()` only once per class definition.

= 1.10.3 =
* Enhancement: Populate Softone categories and brands on the Appearance → Menus screen so the backend preview mirrors the front-end while keeping the injected entries virtual.
* Fix: Guard the menu population workflow to avoid injecting duplicate items when both admin and public filters run during the same request.

= 1.10.2 =
* Version bump and housekeeping.

= 1.10.1 =
* Tweak: Automatically tick “Show images” for the colour attribute on created/updated WPC Linked Variation groups.
* Tweak: Use meaningful group titles, e.g. “Linked Variations (Color) – {Product Name}”, instead of timestamps.

= 1.10.0 =
* Change: Import all products as simple products and disable internal variation handling by default.
* Feature: Automatically create/update WPC Linked Variation groups that link related products by colour attribute.
* Dev: Honour the “Enable variable product handling” setting strictly so disabling it turns off all variation queues and conversions.

= 1.9.0 =
* Dev: Extract shared hooks into a dedicated module so the bootstrap remains modular and easier to extend.

= 1.8.98 =
* Fix: Detect existing variations by matching WooCommerce attribute values so Softone materials sharing a colour or size no longer create duplicates.

= 1.8.97 =
* Fix: Stop drafting single-product sources when generating variations so converted products remain published.

= 1.8.96 =
* Fix: Publish converted variable products so Softone parents no longer remain in draft after colour variation imports.

= 1.8.95 =
* Fix: Restore variable-product conversions when colour-based imports run so Softone parents publish as WooCommerce variable products again.

= 1.8.94 =
* Change: Always convert imported products into colour variations so every Softone item publishes as a WooCommerce variation.

= 1.8.92 =
* Fix: Convert related colour parents to variable products before creating variations so Softone sync no longer logs failures.

= 1.8.91 =
* Feature: Add a dedicated variable product activity log screen with paginated viewing and human-readable failure reasons.
* Enhancement: Register the variable product log viewer under both the plugin and WooCommerce admin menus for easier access.

= 1.8.90 =
* Restore colour-based variation creation when the variable product handling filter is enabled, including stock, pricing, and Softone metadata.

= 1.8.89 =
* Change: Enable Softone variable product handling by default so colour-linked items convert into WooCommerce variations without requiring a filter override.
* Feature: Carry size attributes into parent products and generated variations so catalogue syncs expose both colour and size options.

= 1.8.88 =
* Feature: Add a settings toggle that enables colour-based variable product handling without requiring a code snippet filter.
* Change: Honour the new setting in the variable product handling filter so variation queues and creation run when the checkbox is selected.

= 1.8.87 =
* Restore variable product conversion when `softone_wc_integration_enable_variable_product_handling` is enabled so colour-linked Softone items publish as WooCommerce variations with synced price, stock, and metadata.
* Persist queued variation data across asynchronous import batches and re-align parent colour attributes to match related materials.

= 1.8.86 =
* Fix: Preserve parent SKUs when colour variation queues are disabled so duplicate Softone items keep their identifiers.
* Dev: Introduce the `softone_wc_integration_enable_variable_product_handling` filter to prepare for future variation workflows.

= 1.8.85 =
* Change: Stop appending colour information to generated SKUs so identifiers stay aligned with Softone.

= 1.8.84 =
* Feature: Append the product colour to generated SKUs when available for clearer catalog management.
* Tweak: Rely on WooCommerce's native SKU uniqueness checks by removing the global override.

= 1.8.83 =
* Cap the duplicate-page detection history stored during async imports so large catalogues do not grow the session payload indefinitely.
* Add a regression harness covering thousand-row payloads to confirm async imports finish without duplicate warnings or memory build-up.

= 1.8.82 =
* Ensure asynchronous item imports resume partially processed pages so SoftOne responses with more rows than the batch size create every product.

= 1.8.81 =
* Record SoftOne item import successes, skips, and errors in the sync activity log so skipped products explain their outcome.

= 1.8.80 =
* Disable WooCommerce's unique SKU enforcement so SoftOne catalogue imports can intentionally reuse identifiers.

= 1.8.79 =
* Disabled variable product creation workflows to prepare for a redesign of the sync strategy.

= 1.8.78 =
* Prioritise the dedicated `related_item_mtrl` pointer from SoftOne while filtering out self-referential materials to keep parent relationships intact.
* Ensure colour variation queues aggregate parents, siblings, and descendants so variation creation receives the full related set.
* Add a regression harness covering mixed `related_item_mtrl` and `related_item_mtrls` payloads to guard the new pointer logic.

= 1.8.77 =
* Defer colour variation creation until after all single-product imports complete so catalogues stage as simple items first.
* Draft single-product entries once they are represented as variations to prevent duplicate listings while keeping SoftOne data accessible.

= 1.8.76 =
* Extend the SoftOne API debugger with variation diagnostics so queued batches, creation counts, and SKU adjustments are visible after a sync.
* Clarify the variation processing flow in the debugger to emphasise that base products persist before unique SKUs are generated for related items.

= 1.8.75 =
* Generate unique SKUs for colour variations sourced from related Softone materials so WooCommerce accepts every variant during synchronisation.
* Add logging around colour variation SKU adjustments to highlight when duplicates are resolved automatically.

= 1.8.74 =
* Defer parent colour aggregation and variation queuing until all related materials exist with colour terms, preventing partial colour lists when Softone omits or delays sibling imports.

= 1.8.73 =
* Preserve related SoftOne material references when SoftOne omits relationship fields so previously imported items retain their `_softone_related_item_mtrl` values.

= 1.8.72 =
* Capture SoftOne `softone_related_item_mtrll` lists when preparing related material attributes so colour variations queue every linked MTRL during full imports.

= 1.8.71 =
* Group process trace entries into per-product blocks with product metadata so debugging actions stay bundled per catalogue item.

= 1.8.70 =
* Introduce a process trace diagnostics screen that streams detailed logging for authentication, item imports, and variation decisions in real time.

= 1.8.69 =
* Keep colourable SoftOne items as simple products until a related material exists so standalone products retain their SKUs.

= 1.8.68 =
* Recover colour attribute assignments when WooCommerce helpers are unavailable so variation creation never drops back to simple products.
* Autoload WooCommerce variation classes before ensuring colour variants so early sync runs succeed even if WooCommerce has not finished booting.
* Derive related colour sync queues from stored relationships and existing children so sibling materials populate variations even when SoftOne omits `related_item_mtrl` data.
* Ignore conflicting SKU matches when a variation already points at a different SoftOne material to keep updates scoped to the intended product.

= 1.8.67 =
* Mark SoftOne-managed product attributes as taxonomy-backed so WooCommerce exposes selectable terms instead of raw IDs in the product editor.

= 1.8.66 =
* Queue colour variation synchronisation for parent products as soon as related SoftOne children are linked so new shades appear without a second import.

= 1.8.65 =
* Capture colour options from related Softone materials even when their WooCommerce records are stored as product variations.
* Resolve related variation data by matching Softone material identifiers against both products and product_variation posts.

= 1.8.64 =
* Prevent placeholder attribute values from creating terms. Size/Brand values like '-'/'n/a' are now ignored during item sync.

= 1.8.61 =
* Ensure parent products collect colour options from related items before creating variations so every linked shade receives a WooCommerce variant.

= 1.8.60 =
* Normalise stored colour attribute options back to taxonomy term IDs so related Softone items with slug-based assignments still generate variations and expose colour choices on the parent product.

= 1.8.59 =
* Defer colour variation synchronisation until after all products import so related items are available before generating variations.

= 1.8.58 =
* Ensure related SoftOne material assignments update reciprocal parent pointers so linked products remain synchronised.

= 1.8.57 =
* Expand related-item variation support so products mirror the colour options from all linked Softone materials and keep parent colour attributes in sync.

= 1.8.56 =
* Aggregate related Softone material identifiers on parent products so items without a direct `related_item_mtrl` value store the list of linked variations.

= 1.8.55 =
* Normalise SoftOne colour placeholders so derived shades (for example `| black`) populate the WooCommerce `pa_colour` taxonomy before the suffix is stripped from product names.
* Convert items with colour attributes into variable products and maintain a dedicated variation per colour with synced price, stock, and SKU metadata.

= 1.8.54 =
* Re-release the plugin using the codebase from commit 5ff7c3d to undo later changes while continuing the version sequence.

= 1.8.48 =
* Ensure WooCommerce item sync detects SKU-prefixed media files even when WordPress renames uploads, promoting `_1` images to featured status and assigning remaining matches to the gallery automatically.

= 1.8.47 =
* Revert the plugin codebase to the logic deployed in commit 2ae9c8e to restore stable item synchronisation behaviour.

= 1.8.46 =
* Ensure WooCommerce category terms are created and assigned even when the Softone payload hash has not changed so taxonomy sync runs for legacy imports.

= 1.8.38 =
* Guard WooCommerce category creation against race conditions so SoftOne category, subcategory, and sub-subcategory terms are consistently assigned during imports.
* Log additional debug context when re-using existing product categories to aid troubleshooting taxonomy sync issues.

= 1.8.37 =
* Map SoftOne category, subcategory, and sub-subcategory names to nested WooCommerce product category terms during item sync.

= 1.8.36 =
* Record the payload returned by Softone when item sync runs so administrators can inspect API responses from the sync activity log.

= 1.8.35 =
* Introduce a file-based sync activity viewer that surfaces product category, attribute, and menu operations with a single-click option to clear the log file.
* Capture sync activity directly to a lightweight uploads log so administrators can audit taxonomy assignments without bloating the database.

= 1.8.34 =
* Accept the SoftOne `color` field when building WooCommerce attributes so products populate the `pa_colour` taxonomy using the same logic as `pa_brand`.

= 1.8.33 =
* Ensure WooCommerce attribute taxonomies are registered before assigning colour terms so `pa_colour` is persisted reliably and log failures when assignments cannot complete.

= 1.8.32 =
* Ensure imported products correctly populate the `pa_colour` attribute when the value is extracted from the SoftOne item name.

= 1.8.31 =
* Strip colour suffixes appended to imported product names and use the extracted value to populate the Colour attribute with normalised casing.

= 1.8.30 =
* Automatically assign featured and gallery images from the media library when filenames share the product SKU and numeric suffixes.

= 1.8.29 =
* Introduce a dedicated category synchronisation logger that records the category names and identifiers applied to each product refresh.
* Log category assignments after resync operations so the Category Sync Logs screen surfaces the updated taxonomy mappings.

= 1.8.28 =
* Document the category and menu re-sync button so administrators can manually refresh taxonomy assignments after updating credentials.

= 1.8.27 =
* Log detailed context whenever categories map to WooCommerce's default uncategorized term to simplify debugging taxonomy imports.

= 1.8.26 =
* Update the listed WordPress.org contributor username.

= 1.8.25 =
* Add verbose documentation around SoftOne setting accessors and bump the plugin version number.

= 1.8.24 =
* Refresh the plugin documentation to highlight key synchronisation workflows and setup guidance.
* Clarify troubleshooting tips and manual QA steps to support the documentation overhaul.

= 1.8.23 =
* Refresh plugin documentation to reflect the latest synchronisation features, tools, and troubleshooting workflows.

= 1.8.22 =
* Continue populating WooCommerce navigation menus with SoftOne categories when product brand taxonomies are unavailable so storefronts maintain complete catalogue navigation.

= 1.8.21 =
* Preserve WooCommerce product category assignments on save to ensure synced SoftOne items inherit the expected hierarchy without manual rework.

= 1.8.20 =
* Expand the category synchronisation log viewer to aggregate entries across all WooCommerce log files, improving visibility into import issues.

= 1.8.19 =
* Introduce the category synchronisation log viewer within the admin menu to surface SoftOne taxonomy activity.
* Keep SoftOne-managed products published by default while marking them out of stock when inventory reaches zero.

= 1.8.17 =
* Capture additional diagnostic data when SoftOne API requests fail to speed up support investigations.

= 1.8.16 =
* Update internal version references in preparation for ongoing 1.8.x maintenance releases.

== Upgrade Notice ==

= 1.8.30 =
Automatically populate product images from media library assets that follow the SKU-number naming convention for faster catalogue updates.

= 1.8.29 =
Capture the categories applied during re-syncs with the new dedicated logger so administrators can audit taxonomy updates from the dashboard.

= 1.8.28 =
Highlight the category and menu re-sync button that allows on-demand taxonomy refreshes when catalogue changes are required immediately.

== Automatic Updates ==

The plugin bundles [yahnis-elsts/plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) via Composer so that installations can pull new releases directly from the Git repository.

1. Run `composer install` inside the plugin directory to install dependencies when working from source.
1. Adjust the repository URL, branch, or release asset usage using the `softone_woocommerce_integration_update_url`, `softone_woocommerce_integration_update_branch`, and `softone_woocommerce_integration_use_release_assets` filters if needed.
1. WordPress will automatically discover updates exposed by the configured repository and prompt you to update from the Plugins screen.

== Country Mapping Configuration ==

SoftOne installations typically expect a numeric country identifier instead of WooCommerce ISO codes. Use the **Country Mappings** textarea located under **Softone Integration → Settings** to provide the mapping. Enter one mapping per line using the format `GR:123`, where the part before the colon is the ISO 3166-1 alpha-2 code and the part after the colon is the numeric SoftOne identifier. The plugin automatically normalises the input and ignores empty lines.

Developers can further adjust the mapping in code by filtering the array exposed via the `softone_wc_integration_country_mappings` hook or by overriding individual results with the `softone_wc_integration_country_id` filter.

== Customer Default Configuration ==

Use the following settings located under **Softone Integration → Settings** to control the default values applied to new SoftOne customer records:

* **Default AREAS** — populates the `AREAS` field when creating or updating customers.
* **Default SOCURRENCY** — applied to the `SOCURRENCY` field to control the customer's currency.
* **Default TRDCATEGORY** — sets the `TRDCATEGORY` field for the trading category code.

Leave any field blank to skip sending the corresponding value. Developers can read the configured defaults programmatically via the `Softone_API_Client` accessors (`get_areas()`, `get_socurrency()`, and `get_trdcategory()`).

== Manual QA ==

Use the following smoke tests before deploying changes:

1. Populate credentials and run the API tester with the `authenticate` preset. Confirm a success response and that the result banner appears on refresh.
2. Run a manual item import and verify that products update stock levels and categories as expected. Check Category Sync Logs for new entries.
3. Place a WooCommerce order that meets the configured export status. Confirm a SoftOne document ID note is added to the order and that the WooCommerce order status remains unchanged.
4. Update the country mapping textarea with `GR:101`, place a Greek order, and confirm the SoftOne payload contains `COUNTRY => 101` instead of the ISO code.

== Login Handshake Behaviour ==

SoftOne expects the login request to include only the username, password, and optional App ID. Company, Branch, Module, and Ref ID are returned in the login response and re-used automatically for the follow-up `authenticate` request. When the response omits a value the plugin falls back to the values configured under **Softone Integration → Settings**.

The `softone_wc_integration_login_payload` filter remains available for integrations that need to adjust the final login payload before dispatch.
