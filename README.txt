=== Plugin Name ===
Contributors: (this should be a list of wordpress.org userid's)
Donate link: https://www.georgenicolaou.me//
Tags: comments, spam
Requires at least: 3.0.1
Tested up to: 3.4
Stable tag: 1.8.21
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Here is a short description of the plugin.  This should be no more than 150 characters.  No markup here.

== Description ==

This is the long description.  No limit, and you can use Markdown (as well as in the following sections).

For backwards compatibility, if this section is missing, the full length of the short description will be used, and
Markdown parsed.

A few notes about the sections above:

*   "Contributors" is a comma separated list of wp.org/wp-plugins.org usernames
*   "Tags" is a comma separated list of tags that apply to the plugin
*   "Requires at least" is the lowest version that the plugin will work on
*   "Tested up to" is the highest version that you've *successfully used to test the plugin*. Note that it might work on
higher versions... this is just the highest one you've verified.
*   Stable tag should indicate the Subversion "tag" of the latest stable version, or "trunk," if you use `/trunk/` for
stable.

    Note that the `readme.txt` of the stable tag is the one that is considered the defining one for the plugin, so
if the `/trunk/readme.txt` file says that the stable tag is `4.3`, then it is `/tags/4.3/readme.txt` that'll be used
for displaying information about the plugin.  In this situation, the only thing considered from the trunk `readme.txt`
is the stable tag pointer.  Thus, if you develop in trunk, you can update the trunk `readme.txt` to reflect changes in
your in-development version, without having that information incorrectly disclosed about the current stable version
that lacks those changes -- as long as the trunk's `readme.txt` points to the correct stable tag.

    If no stable tag is provided, it is assumed that trunk is stable, but you should specify "trunk" if that's where
you put the stable version, in order to eliminate any doubt.

== Installation ==

This section describes how to install the plugin and get it working.

e.g.

1. Upload `softone-woocommerce-integration.php` to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Place `<?php do_action('plugin_name_hook'); ?>` in your templates

== Frequently Asked Questions ==

= A question that someone might have =

An answer to that question.

= What about foo bar? =

Answer to foo bar dilemma.

== Screenshots ==

1. This screen shot description corresponds to screenshot-1.(png|jpg|jpeg|gif). Note that the screenshot is taken from
the /assets directory or the directory that contains the stable readme.txt (tags or trunk). Screenshots in the /assets
directory take precedence. For example, `/assets/screenshot-1.png` would win over `/tags/4.3/screenshot-1.png`
(or jpg, jpeg, gif).
2. This is the second screen shot

== Changelog ==

= 1.8.21 =
* Ensure product category assignments persist after saving WooCommerce products so synced items inherit the expected hierarchy.

= 1.8.20 =
* Improve the category synchronisation log viewer to detect entries across all WooCommerce log files.

= 1.8.19 =
* Added a category synchronisation log viewer in the admin menu to surface SoftOne taxonomy creation activity.
* Keep stale products published by default while marking them out of stock.

= 1.8.17 =
* Improve logging to capture additional diagnostic details when API requests fail.

= 1.8.16 =
* Bump plugin version constants and readme stable tag to 1.8.16.

= 1.0 =
* A change since the previous version.
* Another change.

= 0.5 =
* List versions from most recent at top to oldest at bottom.

== Upgrade Notice ==

= 1.0 =
Upgrade notices describe the reason a user should upgrade.  No more than 300 characters.

= 0.5 =
This version fixes a security related bug.  Upgrade immediately.

== Automatic Updates ==

The plugin now bundles [yahnis-elsts/plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) via Composer so that installations can pull new releases directly from the Git repository.

1. Run `composer install` inside the plugin directory to install dependencies.
1. Adjust the repository URL, branch or release asset usage using the `softone_woocommerce_integration_update_url`, `softone_woocommerce_integration_update_branch` and `softone_woocommerce_integration_use_release_assets` filters if needed.
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

Use the following smoke test to verify country mapping behaviour:

1. Navigate to **Softone Integration → Settings** and populate the **Country Mappings** textarea with a sample mapping such as `GR:101`. Save the settings.
1. Create a WooCommerce customer (or place a guest order) using Greece as the billing country.
1. Confirm that the SoftOne payload written to the debug log or sent to the API contains `COUNTRY => 101` instead of the ISO code. If the mapping is missing, the plugin now logs an error mentioning the ISO code and skips the payload so the missing configuration can be corrected.

Repeat the steps for each supported country to ensure the expected numeric IDs are emitted.

Additional optional checks:

1. Populate the **Default AREAS**, **Default SOCURRENCY**, and **Default TRDCATEGORY** fields with representative values and save the settings.
1. Create a WooCommerce customer (or place a guest order) and confirm that the SoftOne payload now contains the configured defaults (for example `AREAS => 22`). Remove the values or adjust them to match the production environment once verified.

== Login Handshake Behaviour ==

SoftOne now expects the login request to contain **only** the username, password, and (optionally) the App ID. Handshake details (Company, Branch, Module, and Ref ID) are supplied by the SoftOne server in the login response and are re-used automatically for the follow-up `authenticate` request. When SoftOne does not return a value the plugin falls back to the values configured under **Softone Integration → Settings**.

The `softone_wc_integration_login_payload` filter remains available for integrations that need to adjust the final login payload before it is dispatched.

== Arbitrary section ==

You may provide arbitrary sections, in the same format as the ones above.  This may be of use for extremely complicated
plugins where more information needs to be conveyed that doesn't fit into the categories of "description" or
"installation."  Arbitrary sections will be shown below the built-in sections outlined above.

== A brief Markdown Example ==

Ordered list:

1. Some feature
1. Another feature
1. Something else about the plugin

Unordered list:

* something
* something else
* third thing

Here's a link to [WordPress](http://wordpress.org/ "Your favorite software") and one to [Markdown's Syntax Documentation][markdown syntax].
Titles are optional, naturally.

[markdown syntax]: http://daringfireball.net/projects/markdown/syntax
            "Markdown is what the parser uses to process much of the readme file"

Markdown uses email style notation for blockquotes and I've been told:
> Asterisks for *emphasis*. Double it up  for **strong**.

`<?php code(); // goes in backticks ?>`
