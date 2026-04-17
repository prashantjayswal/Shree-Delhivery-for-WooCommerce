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
