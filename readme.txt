=== Shree Delhivery for WooCommerce ===
Contributors: shree
Tags: woocommerce, shipping, delhivery, logistics, ecommerce
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shree branded WooCommerce plugin for Delhivery shipping rates, serviceability, shipment creation, tracking, labels, pickup requests, and status sync.

== Description ==

This plugin adds:

* Delhivery shipping method for WooCommerce zones
* Checkout postcode serviceability validation
* Live TAT and approximate shipping-rate lookup
* Admin order actions for shipment creation, tracking sync, label generation, pickup, and cancellation
* NDR management: re-attempt delivery or return-to-origin directly from order admin
* Shipment update: modify shipment details after creation
* Reverse pickup: create return pickups for refunded orders (manual or automatic)
* Download POD (Proof of Delivery) documents for delivered orders
* Estimated delivery date display on order details, My Account, and thank-you page
* Customer-facing tracking timeline with scan history on My Account order view
* Delhivery tracking info in WooCommerce order emails (processing, completed, invoice)
* Send Tracking Email: one-click branded tracking email to customers with live status, EDD, and scan history
* Delhivery status column in the admin orders list with badge indicators
* Bulk operations: create shipments, sync tracking, generate labels, and request pickups in bulk
* REST webhook endpoint for Delhivery status updates with scan history
* REST tracking endpoint for authenticated customers
* Swagger-aligned REST endpoints for serviceability, EDD, cost, warehouse, manifest, NDR, COD remittance, and order actions
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

This plugin uses the WordPress HTTP API for Delhivery API requests.

== Webhook ==

Use this endpoint in Delhivery if webhook delivery is enabled for your account:

`/wp-json/delhivery-wc/v1/webhook`

== REST API ==

Additional REST endpoints:

* `GET /wp-json/delhivery-wc/v1/serviceability?pin=XXXXXX` - public pincode check
* `GET /wp-json/delhivery-wc/v1/edd?pickup_postcode=110001&delivery_postcode=400001` - public expected delivery date lookup
* `GET /wp-json/delhivery-wc/v1/cost?origin_pin=110001&destination_pin=400001&weight_grams=500&mode=S&payment_type=Prepaid&package_type=box` - public shipping cost lookup
* `GET /wp-json/delhivery-wc/v1/tracking?order_id=123` - authenticated customer tracking with live scan history
* `GET /wp-json/delhivery-wc/v1/admin/waybills?count=5` - admin waybill generation helper
* `POST /wp-json/delhivery-wc/v1/admin/warehouse/create` - admin warehouse creation
* `POST /wp-json/delhivery-wc/v1/admin/warehouse/update` - admin warehouse update
* `POST /wp-json/delhivery-wc/v1/admin/manifest` - admin manifest generation
* `GET /wp-json/delhivery-wc/v1/admin/ndr` - admin NDR list
* `GET /wp-json/delhivery-wc/v1/admin/finance/cod` - admin COD remittance lookup
* `POST /wp-json/delhivery-wc/v1/admin/orders/<order_id>/shipment` - create shipment for an order
* `POST /wp-json/delhivery-wc/v1/admin/orders/<order_id>/tracking` - sync tracking for an order
* `POST /wp-json/delhivery-wc/v1/admin/orders/<order_id>/label` - generate shipping label for an order
* `POST /wp-json/delhivery-wc/v1/admin/orders/<order_id>/pickup` - schedule pickup for an order
* `POST /wp-json/delhivery-wc/v1/admin/orders/<order_id>/cancel` - cancel shipment for an order
* `POST /wp-json/delhivery-wc/v1/admin/orders/<order_id>/update-shipment` - update shipment address details for an order
* `POST /wp-json/delhivery-wc/v1/admin/orders/<order_id>/reverse-pickup` - create reverse pickup for an order
* `POST /wp-json/delhivery-wc/v1/admin/orders/<order_id>/pod` - fetch POD details for an order
* `POST /wp-json/delhivery-wc/v1/admin/orders/<order_id>/tracking-email` - send customer tracking email
* `POST /wp-json/delhivery-wc/v1/admin/orders/<order_id>/ndr` - submit `RE` or `RTO` action for an order

== Changelog ==

= 0.3.0 =

* Added custom WooCommerce order statuses: "Manifested" and "Pickup Scheduled"
* Added settings to configure order status after shipment manifest and pickup request
* Order status automatically updates through the Delhivery lifecycle: Processing -> Manifested -> Pickup Scheduled -> Processing (in transit) -> Completed
* Hourly tracking sync now includes orders in Manifested and Pickup Scheduled statuses
* Pickup-related Delhivery statuses now display with info-style badge in admin

= 0.2.0 =

* Added NDR (Non-Delivery Report) management: re-attempt delivery or return-to-origin from order admin
* Added shipment update capability for manifested shipments
* Added reverse pickup creation for returns/refunds
* Added auto-reverse pickup on refund setting
* Added Download POD action for delivered orders
* Added estimated delivery date on order details, My Account, and thank-you page
* Added customer-facing tracking timeline with scan history on My Account order view
* Added Delhivery tracking info in WooCommerce order emails (processing, completed, on-hold, invoice)
* Added Send Tracking Email button to manually email customers a branded tracking notification with scan history
* Added Delhivery status column in admin orders list
* Added bulk operations: create shipments, sync tracking, generate labels, request pickups
* Added REST tracking endpoint for authenticated customers
* Enhanced webhook handler to store scan history, location, and EDD
* Context-aware meta box buttons: show only relevant actions based on shipment state
* NDR status auto-sets WooCommerce order to on-hold for admin attention
* Stores tracking URL, reverse waybill, and NDR action metadata

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
