# Shree Delhivery for WooCommerce

WooCommerce integration for Delhivery shipping rates, serviceability, shipment creation, tracking, labels, pickup requests, and status sync.

## Features

- Custom Order Statuses: Manifested & Pickup Scheduled
- Configurable Order Status Transitions (after manifest & pickup)
- Automatic Shipment Creation on Order Processing
- AWB (Waybill) Generation
- Shipping Label & POD Download
- Pickup Request Creation
- Real-time Order Tracking with Customer Timeline
- COD & Prepaid Support
- Pincode Serviceability Check
- Send Tracking Email to Customers
- Delhivery Status Column in Orders List
- Bulk Operations (Create Shipments, Sync Tracking, Generate Labels, Request Pickups)
- Reverse Pickup for Returns/Refunds
- NDR Management (Re-attempt / Return to Origin)
- Estimated Delivery Date on Order Pages & Emails
- Webhook Support for Real-time Status Updates
- Tracking Info in WooCommerce Emails
- Swagger-aligned REST operations for serviceability, EDD, shipping cost, warehouse, manifest, NDR, COD remittance, and order actions

## Requirements

- WordPress 6.4 or later
- WooCommerce (latest stable version)
- PHP 7.4 or later
- Active Delhivery account with API token

## Installation

Upload to `/wp-content/plugins/delhivery-woocommerce/` and activate from WordPress admin.

## Configuration

Go to **WooCommerce > Settings > Delhivery** and add:

- API Token
- Pickup Location Name
- Warehouse & Return Address

Use the built-in **Test Connection** button to verify your credentials.

## REST API

Public and customer-facing routes:

- `GET /wp-json/delhivery-wc/v1/serviceability?pin=110001`
- `GET /wp-json/delhivery-wc/v1/edd?pickup_postcode=110001&delivery_postcode=400001`
- `GET /wp-json/delhivery-wc/v1/cost?origin_pin=110001&destination_pin=400001&weight_grams=500&mode=S&payment_type=Prepaid&package_type=box`
- `GET /wp-json/delhivery-wc/v1/tracking?order_id=123`

Admin routes for operations and business workflows:

- `GET /wp-json/delhivery-wc/v1/admin/waybills?count=5`
- `POST /wp-json/delhivery-wc/v1/admin/warehouse/create`
- `POST /wp-json/delhivery-wc/v1/admin/warehouse/update`
- `POST /wp-json/delhivery-wc/v1/admin/manifest`
- `GET /wp-json/delhivery-wc/v1/admin/ndr`
- `GET /wp-json/delhivery-wc/v1/admin/finance/cod`
- `POST /wp-json/delhivery-wc/v1/admin/orders/<order_id>/shipment`
- `POST /wp-json/delhivery-wc/v1/admin/orders/<order_id>/tracking`
- `POST /wp-json/delhivery-wc/v1/admin/orders/<order_id>/label`
- `POST /wp-json/delhivery-wc/v1/admin/orders/<order_id>/pickup`
- `POST /wp-json/delhivery-wc/v1/admin/orders/<order_id>/cancel`
- `POST /wp-json/delhivery-wc/v1/admin/orders/<order_id>/update-shipment`
- `POST /wp-json/delhivery-wc/v1/admin/orders/<order_id>/reverse-pickup`
- `POST /wp-json/delhivery-wc/v1/admin/orders/<order_id>/pod`
- `POST /wp-json/delhivery-wc/v1/admin/orders/<order_id>/tracking-email`
- `POST /wp-json/delhivery-wc/v1/admin/orders/<order_id>/ndr` with `action=RE` or `action=RTO`

## Third-Party Service

This plugin connects to the **Delhivery logistics API** to provide shipping functionality.

- **Service URL:** https://www.delhivery.com
- **API Endpoints:** `https://track.delhivery.com/` (production), `https://staging-express.delhivery.com/` (sandbox)
- **Data transmitted:** Customer name, shipping address, phone number, postcode, order details (products, weight, amounts), and your API token for authentication.
- **When data is sent:** Checking postcode serviceability, calculating shipping rates, creating/updating/tracking/cancelling shipments, generating labels, requesting pickups, and receiving webhook updates.
- **Delhivery Terms of Service:** https://www.delhivery.com/terms-and-conditions
- **Delhivery Privacy Policy:** https://www.delhivery.com/privacy-policy

## Author

Prashant Jayswal

## License

GPLv2 or later. See [LICENSE](LICENSE) for details.
