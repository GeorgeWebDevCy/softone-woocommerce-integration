=== Softone WooCommerce Integration ===
Contributors: georgenicolaou

Tags: woocommerce, integration, softone, api, synchronization
Requires at least: 5.0
Tested up to: 6.0
Stable tag: 2.2.26
Requires PHP: 7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Integrates WooCommerce with Softone API for customer, product, and order synchronization.

== Description ==

The Softone WooCommerce Integration plugin connects WooCommerce to the Softone
ERP system and keeps both platforms in sync.

* Synchronise customers, products and orders using the Softone API.
* Automatic cron-based sync runs in the background (products and menu every two
  minutes, customers and orders hourly).
* Manual sync pages with progress indicators for customers, products and
  orders.
* Navigation menu sync mirrors the WooCommerce product category hierarchy under
  your main menu.
* Creates a **product_brand** taxonomy and assigns Softone brand data to
  products.
* Logs every API request with an admin page for reviewing and clearing logs.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/softone-woocommerce-integration` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Use the Settings->Softone screen to configure the plugin.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =

Yes, this plugin requires WooCommerce to be installed and active.

= How often does the plugin sync data? =

The plugin uses WordPress cron jobs to sync data. Customers and orders are synced hourly, while products and the menu are synced every two minutes.

== Screenshots ==

1. Settings Page.
2. Customer Sync Page.
3. Product Sync Page.
4. Order Sync Page.
5. Logs Page.

== Divi Mega Menus ==

Divi creates a mega menu when a parent item includes the `mega-menu` CSS class
and its URL is `#`. The plugin automatically applies this class to the
"Products" menu entry so child categories are shown in columns.

== Softone Multi Level Menu ==

Softone categories are converted to WooCommerce product categories. The Menu
Sync tool mirrors this hierarchy under the **Products** menu item, creating
nested submenus for each level.

== Changelog ==

= 2.2.26 =
* Remove custom mega-menu classes from top level categories to rely on Divi's native styling.

= 2.2.25 =
* Skip the "Uncategorized" product category when building the menu.
* Apply Divi mega-menu class only to the Products menu parent.

= 2.2.22 =
* Add live log viewer with automatic updates in the admin area.

= 2.2.21 =
* Add debug logging when registering admin menu pages.

= 2.2.20 =
* Prevent debug notice by avoiding early translation loading.

= 2.2.19 =
* Automatically create the Products menu with mega-menu support.

= 2.2.18 =
* Ensure mega-menu class is added without trailing spaces.

= 2.2.17 =
* Restore original product category ordering in the navigation menu.

= 2.2.16 =
* Ensure product categories in the navigation menu are ordered alphabetically.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 2.2.26 =
* Removes unused mega-menu classes for better compatibility with Divi.

= 2.2.25 =
* Menu sync skips "Uncategorized" and applies Divi mega-menu class only to Products.

= 2.2.22 =
* View logs live from the WordPress admin.

= 2.2.21 =
* Adds debugging logs for admin menu registration.

= 2.2.20 =
* Fixes translation loading warning on plugin activation.

= 1.0.0 =
* Initial release.

= 2.2.19 =
* Automatically creates the mega-menu ready Products entry.

= 2.2.16 =
* Product categories are now ordered alphabetically in the menu.

= 2.2.17 =
* Restores WooCommerce category order in the menu.

= 2.2.18 =
* Prevent whitespace issues when setting mega-menu class.

= 2.0.0 =
* Agains.

= 2.0.1 =
* Agains.


== Arbitrary section ==

You may provide arbitrary sections, such as a "Features" section in the readme to provide more information to users.

== License ==

This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this program; if not, write to the Free Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
