=== Softone WooCommerce Integration ===
Contributors: orionaselite
Donate link: https://www.georgenicolaou.me//
Tags: softone, erp, woocommerce, integration, inventory, orders, api
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.8.69
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
* **Menu population helpers** – Optionally extend WooCommerce menu structures to include synced SoftOne product categories, even when the site does not expose brand taxonomies.

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
* **Stock Behaviour** – Choose between treating zero SoftOne stock as “1” to keep items purchasable, or marking depleted items as backorderable. Only one mode can be active at a time.

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
* **Cron events not running** – Verify WP-Cron execution by visiting `wp-cron.php` manually or configuring a real cron job. You can reschedule events programmatically via `Softone_Item_Sync::schedule_event()`.

== Changelog ==

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
