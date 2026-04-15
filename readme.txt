=== Shree Delhivery for WooCommerce ===
Contributors: shree
Tags: woocommerce, shipping, delhivery, logistics, ecommerce
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shree branded WooCommerce plugin for Delhivery shipping rates, serviceability, shipment creation, tracking, labels, pickup requests, and status sync.

== Description ==

This plugin adds:

* Delhivery shipping method for WooCommerce zones
* Checkout postcode serviceability validation
* Live TAT and approximate shipping-rate lookup
* Admin order actions for shipment creation, tracking sync, label generation, pickup, and cancellation
* REST webhook endpoint for Delhivery status updates
* PHP cURL requests for all Delhivery API calls
* Native WooCommerce settings tab for token management and connection testing
* Activation guard so the plugin cannot be activated without WooCommerce

== Installation ==

1. Copy the `shree-delhivery-woocommerce` folder into `wp-content/plugins/`.
2. Activate the plugin in WordPress.
3. Make sure WooCommerce is already installed and active before activation.
4. Go to `WooCommerce > Delhivery` and enter your Delhivery API token and warehouse defaults.
5. Add `Delhivery Shipping` to your WooCommerce shipping zone.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =

Yes. WooCommerce must be installed and active before this plugin can be activated.

= Where do I add the Delhivery token? =

Go to `WooCommerce > Settings > Delhivery`, save your token, and use the built-in `Test Delhivery Connection` button.

= Does this plugin use WordPress HTTP APIs or cURL? =

This plugin uses PHP cURL for Delhivery API requests.

== Webhook ==

Use this endpoint in Delhivery if webhook delivery is enabled for your account:

`/wp-json/delhivery/v1/webhook`

== Changelog ==

= 0.1.1 =

* Improved shipping rate parsing to avoid zero-value Delhivery lines when the cost API response is nested or formatted differently
* Added WooCommerce-native Delhivery settings tab with token masking, token testing, and token status badge
* Added WooCommerce dependency enforcement during plugin activation
* Added Shree branding and repository-ready metadata updates

= 0.1.0 =

* Initial release
* Delhivery serviceability, TAT, shipping cost, shipment creation, tracking, label, pickup, warehouse, and cancellation support
* Native WooCommerce settings tab
* Token testing and dependency checks
