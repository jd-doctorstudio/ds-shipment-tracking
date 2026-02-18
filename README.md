# DS Product Shipment Tracking Plugin

**Version:** 2.0.0  
**Author:** joy@doctorsstudio.com  
**WordPress:** 5.8+  
**WooCommerce:** 5.0 - 9.0+  
**PHP:** 7.4+

## Overview

Custom WordPress plugin that enables the POS system to create and manage shipment tracking entries with **per-product line-item support** for partial fulfillment tracking. This plugin writes to the standard WooCommerce `_wc_shipment_tracking_items` order meta, ensuring full compatibility with the native WooCommerce Shipment Tracking plugin UI.

## Why This Plugin?

The standard WooCommerce Shipment Tracking REST API doesn't support attaching specific products to tracking entries. This plugin bridges that gap by:

- ✅ Supporting `products_list` with product IDs, line item IDs, and quantities
- ✅ Writing to the standard `_wc_shipment_tracking_items` meta key
- ✅ Storing product line-items as `stdClass` objects (WooCommerce format)
- ✅ Full backward compatibility with WooCommerce Shipment Tracking UI
- ✅ HPOS (High-Performance Order Storage) compatible
- ✅ Proper authentication via WooCommerce REST API keys

## Features

### 1. Create Tracking with Product Line-Items
```http
POST /wp-json/ds-shipment/v1/orders/{order_id}/trackings
```

**Request Body:**
```json
{
  "tracking_number": "1Z999AA10123456784",
  "tracking_provider": "UPS",
  "date_shipped": "2026-02-18",
  "status_shipped": "partial",
  "products_list": [
    {
      "product": "12345",
      "item_id": "67890",
      "qty": "2"
    }
  ]
}
```

**Response:**
```json
{
  "tracking_id": "abc123def456",
  "tracking_number": "1Z999AA10123456784",
  "tracking_provider": "UPS",
  "date_shipped": 1708214400,
  "products_list": [
    {
      "product": "12345",
      "item_id": "67890",
      "qty": "2"
    }
  ]
}
```

### 2. Get All Trackings for an Order
```http
GET /wp-json/ds-shipment/v1/orders/{order_id}/trackings
```

**Response:**
```json
[
  {
    "tracking_id": "abc123def456",
    "tracking_number": "1Z999AA10123456784",
    "tracking_provider": "UPS",
    "date_shipped": 1708214400,
    "products_list": [
      {
        "product": "12345",
        "item_id": "67890",
        "qty": "2"
      }
    ]
  }
]
```

### 3. Delete a Tracking Entry
```http
DELETE /wp-json/ds-shipment/v1/orders/{order_id}/trackings/{tracking_id}
```

**Response:**
```json
{
  "success": true,
  "message": "Tracking deleted successfully"
}
```

## Installation

1. **Upload the plugin:**
   ```bash
   # Copy to WordPress plugins directory
   cp ds-shipment-tracking.php /path/to/wordpress/wp-content/plugins/ds-shipment-tracking/
   ```

2. **Activate the plugin:**
   - Go to WordPress Admin → Plugins
   - Find "DS Product Shipment Tracking"
   - Click "Activate"

3. **Verify the endpoint:**
   ```bash
   curl -X GET "https://your-site.com/wp-json/ds-shipment/v1/orders/12345/trackings" \
     -u "consumer_key:consumer_secret"
   ```

## Authentication

The plugin uses WooCommerce REST API authentication:

- **Consumer Key & Secret:** Standard WooCommerce API credentials
- **User Capabilities:** Checks for `edit_shop_orders` capability
- **HTTPS Recommended:** For secure API key transmission

## Data Format

### products_list Structure

Each product in `products_list` is stored as a `stdClass` object with:

| Field | Type | Description |
|-------|------|-------------|
| `product` | string | WooCommerce product ID |
| `item_id` | string | WooCommerce order line item ID |
| `qty` | string | Quantity shipped |

### Order Meta Storage

The plugin writes to `_wc_shipment_tracking_items` as a serialized PHP array:

```php
[
  [
    'tracking_id' => 'abc123def456',
    'tracking_provider' => 'UPS',
    'tracking_number' => '1Z999AA10123456784',
    'date_shipped' => 1708214400,
    'products_list' => [
      (object) ['product' => '12345', 'item_id' => '67890', 'qty' => '2']
    ]
  ]
]
```

## HPOS Compatibility

The plugin uses WooCommerce HPOS-compatible meta APIs:

```php
$order->get_meta('_wc_shipment_tracking_items', true);
$order->update_meta_data('_wc_shipment_tracking_items', $trackings);
$order->save();
```

This ensures compatibility with both traditional and HPOS-enabled WooCommerce installations.

## Custom Order Status

The plugin registers a "Shipped" order status if it doesn't already exist:

- **Status Slug:** `shipped`
- **Label:** "Shipped"
- **Shows in:** Bulk actions, order reports

## Error Handling

The plugin validates:

- ✅ Order exists and is valid
- ✅ Tracking number is provided
- ✅ Provider is specified (standard or custom)
- ✅ Product IDs and item IDs belong to the order
- ✅ User has proper permissions

**Error Response Example:**
```json
{
  "code": "invalid_order",
  "message": "Order not found",
  "data": {
    "status": 404
  }
}
```

## Integration with POS System

### Django Backend
The Django backend calls this plugin endpoint instead of the standard WooCommerce API:

```python
# woocommerce.py
endpoint = f"ds-shipment/v1/orders/{order_id}/trackings"
response = requests.post(
    f"{base_url}/wp-json/{endpoint}",
    auth=HTTPBasicAuth(consumer_key, consumer_secret),
    json=payload
)
```

### React Frontend
The POS frontend sends `item_id` along with `product_id`:

```typescript
const products_list = order.items.map(item => ({
  product_id: String(item.woo_product_id),
  item_id: String(item.id),
  qty: selectedProducts[item.id]
}));
```

## Backward Compatibility

- **Existing trackings without `products_list`:** Still work correctly
- **WooCommerce Shipment Tracking UI:** Displays all trackings including those created via this plugin
- **Standard API calls:** Can coexist with this plugin

## Troubleshooting

### Tracking not showing in WooCommerce admin?
- Verify the plugin is activated
- Check that `_wc_shipment_tracking_items` meta key is being written
- Ensure WooCommerce Shipment Tracking plugin is installed

### Products not attaching to tracking?
- Verify `item_id` values match actual order line item IDs
- Check that `product` values are valid WooCommerce product IDs
- Review WordPress debug logs for validation errors

### HPOS compatibility issues?
- Ensure WooCommerce 7.0+ is installed
- Verify HPOS is enabled in WooCommerce → Settings → Advanced → Features
- Check that order meta is being saved via `$order->save()`

## Development

### Logging
The plugin logs to WooCommerce logs:

```php
// View logs in: WooCommerce → Status → Logs → ds-shipment-tracking-*
```

### Testing
```bash
# Create tracking
curl -X POST "https://site.com/wp-json/ds-shipment/v1/orders/12345/trackings" \
  -u "ck_xxx:cs_xxx" \
  -H "Content-Type: application/json" \
  -d '{
    "tracking_number": "TEST123",
    "tracking_provider": "USPS",
    "products_list": [{"product": "100", "item_id": "200", "qty": "1"}]
  }'

# Get trackings
curl -X GET "https://site.com/wp-json/ds-shipment/v1/orders/12345/trackings" \
  -u "ck_xxx:cs_xxx"

# Delete tracking
curl -X DELETE "https://site.com/wp-json/ds-shipment/v1/orders/12345/trackings/abc123" \
  -u "ck_xxx:cs_xxx"
```

## Changelog

### Version 2.0.0 (2026-02-18)
- **BREAKING:** Complete rewrite to use `_wc_shipment_tracking_items` meta key
- Added proper `products_list` support with `stdClass` objects
- HPOS compatibility using WooCommerce meta APIs
- Improved validation and error handling
- Added "Shipped" custom order status
- Full backward compatibility with WooCommerce Shipment Tracking UI

### Version 1.0.0 (Initial)
- Basic shipment tracking REST API
- Used custom `_ds_shipment_trackings` meta key (deprecated)

## Support

For issues or questions:
- **Author:** joy@doctorsstudio.com
- **Documentation:** See this README

## License

Proprietary - Internal use only for Doctor's Studio POS system.

