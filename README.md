# Softone WooCommerce Integration

Softone WooCommerce Integration keeps your WooCommerce store in sync with the
Softone ERP platform. The plugin communicates with the Softone API to transfer
customer, product and order data between the two systems.

## Features

- **Automatic synchronization** – WordPress cron jobs run in the background to
  fetch updates from Softone. Products and the navigation menu are refreshed
  every two minutes while customers and orders are processed hourly.
- **Manual sync tools** – Dedicated admin pages let you trigger customer,
  product and order synchronisation on demand. Product sync runs via AJAX and
  displays progress in real time.
- **Menu synchronisation** – Product categories are mirrored in the main
  WordPress menu so the store navigation always reflects the latest structure.
- **Brand taxonomy** – The plugin creates a `product_brand` taxonomy and assigns
  Softone brand information to your WooCommerce products.
- **Logging** – All API interactions are recorded. A log viewer and a button to
  clear logs are available in the WordPress admin area.
- **Custom cron schedules** – Adds 2‑minute and 2‑hour schedules that other
  tasks can use.

## Installation

1. Upload the plugin to `wp-content/plugins/softone-woocommerce-integration` or
   install it through the WordPress plugin screen.
2. Activate **Softone WooCommerce Integration**. WooCommerce must already be
   active.
3. Go to **Softone → Settings** in the WordPress dashboard and enter your API
   credentials.

Once configured, the plugin will automatically keep customers, products and
orders in sync with Softone.

## Divi Mega Menus

Divi supports mega menus by adding the `mega-menu` CSS class to a top level menu
item. When this class is present, any child menu items are displayed in a
multi‑column layout. The menu link should be set to `#` so it functions purely
as a trigger.

## Multi‑level Menu Sync

Product categories imported from Softone are mirrored in your WordPress menu.
The plugin ensures a **Products** menu item exists and is flagged with
`mega-menu`. Each Softone category becomes a submenu item beneath this parent,
and subcategories are nested to reflect their hierarchy.
