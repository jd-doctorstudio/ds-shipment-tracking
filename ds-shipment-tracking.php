<?php
/**
 * Plugin Name: DS Product Shipment Tracking
 * Plugin URI: https://store.doctorsstudio.com
 * Description: Custom shipment tracking with per-product/line-item support for POS integration.
 *              Reads/writes the standard _wc_shipment_tracking_items order meta so entries
 *              are fully compatible with the WooCommerce Shipment Tracking plugin UI.
 * Version: 2.0.0
 * Author: joy@doctorsstudio.com
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class DS_Shipment_Tracking {

    private static $instance = null;

    // Standard WooCommerce Shipment Tracking meta key
    private $meta_key = '_wc_shipment_tracking_items';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    /* ================================================================
     *  REST route registration
     * ================================================================ */
    public function register_rest_routes() {
        // POST – create tracking
        register_rest_route('ds-shipment/v1', '/orders/(?P<order_id>\d+)/trackings', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'create_tracking'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // GET – list trackings
        register_rest_route('ds-shipment/v1', '/orders/(?P<order_id>\d+)/trackings', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_trackings'),
            'permission_callback' => array($this, 'check_permission'),
        ));

        // DELETE – remove tracking
        register_rest_route('ds-shipment/v1', '/orders/(?P<order_id>\d+)/trackings/(?P<tracking_id>[a-fA-F0-9]+)', array(
            'methods'             => 'DELETE',
            'callback'            => array($this, 'delete_tracking'),
            'permission_callback' => array($this, 'check_permission'),
        ));
    }

    /* ================================================================
     *  Permission check – WooCommerce consumer key / secret (HTTP Basic
     *  Auth or query-string) or logged-in user with manage_woocommerce
     * ================================================================ */
    public function check_permission($request) {
        // 1. HTTP Basic Auth (consumer_key as username)
        if (!empty($_SERVER['PHP_AUTH_USER'])) {
            return $this->validate_consumer_key($_SERVER['PHP_AUTH_USER']);
        }

        // 2. Query-string auth
        if (!empty($_GET['consumer_key'])) {
            return $this->validate_consumer_key($_GET['consumer_key']);
        }

        // 3. Logged-in WordPress user
        if (is_user_logged_in()) {
            return current_user_can('manage_woocommerce') || current_user_can('edit_shop_orders');
        }

        return false;
    }

    private function validate_consumer_key($consumer_key) {
        global $wpdb;
        $key_id = $wpdb->get_var($wpdb->prepare(
            "SELECT key_id FROM {$wpdb->prefix}woocommerce_api_keys
             WHERE consumer_key = %s AND permissions IN ('read_write', 'write')",
            wc_api_hash($consumer_key)
        ));
        return !empty($key_id);
    }

    /* ================================================================
     *  GET  /wp-json/ds-shipment/v1/orders/{order_id}/trackings
     *  Returns all tracking items including products_list.
     * ================================================================ */
    public function get_trackings($request) {
        $order_id = absint($request->get_param('order_id'));
        $order    = wc_get_order($order_id);

        if (!$order) {
            return new WP_Error('invalid_order', 'Order not found', array('status' => 404));
        }

        $tracking_items = $order->get_meta($this->meta_key, true);

        if (empty($tracking_items) || !is_array($tracking_items)) {
            return new WP_REST_Response(array(), 200);
        }

        $result = array();
        foreach ($tracking_items as $tracking) {
            $result[] = $this->normalize_tracking_for_response($tracking);
        }

        return new WP_REST_Response($result, 200);
    }

    /* ================================================================
     *  POST /wp-json/ds-shipment/v1/orders/{order_id}/trackings
     *  Creates a tracking entry with optional products_list.
     *
     *  Body:
     *  {
     *    "tracking_number":  "ABC123",
     *    "tracking_provider": "fedex",
     *    "custom_tracking_provider": "",       // optional
     *    "custom_tracking_link": "",            // optional
     *    "tracking_product_code": "",           // optional
     *    "date_shipped": "2025-02-17",          // or unix ts
     *    "status_shipped": "shipped"|"partial"|"1"|"2",
     *    "products_list": [
     *      { "product": "287347", "item_id": "18299", "qty": "1" }
     *    ]
     *  }
     * ================================================================ */
    public function create_tracking($request) {
        $order_id = absint($request->get_param('order_id'));
        $order    = wc_get_order($order_id);

        if (!$order) {
            return new WP_Error('invalid_order', 'Order not found', array('status' => 404));
        }

        // --- Validate required fields ---
        $tracking_number = sanitize_text_field($request->get_param('tracking_number'));
        if (empty($tracking_number)) {
            return new WP_Error('missing_tracking_number', 'Tracking number is required', array('status' => 400));
        }

        $tracking_provider       = sanitize_text_field($request->get_param('tracking_provider') ?? '');
        $custom_tracking_provider = sanitize_text_field($request->get_param('custom_tracking_provider') ?? '');

        $provider = !empty($custom_tracking_provider) ? $custom_tracking_provider : $tracking_provider;
        if (empty($provider)) {
            return new WP_Error('missing_provider', 'Tracking provider is required', array('status' => 400));
        }

        // --- Optional fields ---
        $custom_tracking_link  = esc_url_raw($request->get_param('custom_tracking_link') ?? '');
        $tracking_product_code = sanitize_text_field($request->get_param('tracking_product_code') ?? '');

        // --- Date shipped → unix timestamp string ---
        $date_shipped_raw = $request->get_param('date_shipped');
        if (!empty($date_shipped_raw)) {
            if (is_numeric($date_shipped_raw)) {
                $date_shipped = (string) $date_shipped_raw;
            } else {
                $ts = strtotime($date_shipped_raw);
                $date_shipped = $ts ? (string) $ts : (string) time();
            }
        } else {
            $date_shipped = (string) time();
        }

        // --- Status shipped ---
        $status_raw = sanitize_text_field($request->get_param('status_shipped') ?? '1');
        if ($status_raw === 'shipped') {
            $status_shipped = '1';
        } elseif ($status_raw === 'partial') {
            $status_shipped = '2';
        } else {
            $status_shipped = in_array($status_raw, array('1', '2'), true) ? $status_raw : '1';
        }

        // --- Generate tracking ID (32-char hex, same format as WC plugin) ---
        $tracking_id = md5($order_id . '-' . $tracking_number . '-' . microtime(true));

        // --- Build products_list as array of stdClass (same shape WC uses) ---
        $products_list     = array();
        $products_list_raw = $request->get_param('products_list');

        if (!empty($products_list_raw) && is_array($products_list_raw)) {
            foreach ($products_list_raw as $item_data) {
                $item_id = isset($item_data['item_id']) ? absint($item_data['item_id']) : 0;

                // Validate item belongs to this order (skip invalid)
                if ($item_id > 0) {
                    $order_item = $order->get_item($item_id);
                    if (!$order_item) {
                        continue;
                    }
                }

                $obj           = new stdClass();
                $obj->product  = isset($item_data['product'])  ? (string) $item_data['product']  : '';
                $obj->item_id  = isset($item_data['item_id'])  ? (string) $item_data['item_id']  : '';
                $obj->qty      = isset($item_data['qty'])      ? (string) $item_data['qty']      : '1';
                $products_list[] = $obj;
            }
        }

        // --- Assemble tracking entry (matches WC Shipment Tracking format) ---
        $new_tracking = array(
            'tracking_number'       => $tracking_number,
            'tracking_provider'     => $provider,
            'custom_tracking_link'  => $custom_tracking_link,
            'tracking_product_code' => $tracking_product_code,
            'date_shipped'          => $date_shipped,
            'source'                => 'ds_pos',
            'products_list'         => $products_list,
            'status_shipped'        => $status_shipped,
            'tracking_id'           => $tracking_id,
            'user_id'               => get_current_user_id() ?: 0,
        );

        // --- Append to existing tracking items ---
        $tracking_items = $order->get_meta($this->meta_key, true);
        if (empty($tracking_items) || !is_array($tracking_items)) {
            $tracking_items = array();
        }
        $tracking_items[] = $new_tracking;

        // Save using WooCommerce order meta API (HPOS compatible)
        $order->update_meta_data($this->meta_key, $tracking_items);
        $order->save();

        // --- Order note ---
        $note = sprintf('Shipment tracking added via POS: %s – %s', $provider, $tracking_number);
        if (!empty($products_list)) {
            $ids = array_map(function ($p) { return $p->item_id; }, $products_list);
            $note .= sprintf(' (Line items: %s)', implode(', ', $ids));
        }
        $order->add_order_note($note);

        // --- Return response ---
        return new WP_REST_Response(array(
            'success' => true,
            'data'    => $this->normalize_tracking_for_response($new_tracking),
            'message' => 'Shipment tracking created successfully',
        ), 201);
    }

    /* ================================================================
     *  DELETE /wp-json/ds-shipment/v1/orders/{order_id}/trackings/{tracking_id}
     * ================================================================ */
    public function delete_tracking($request) {
        $order_id    = absint($request->get_param('order_id'));
        $tracking_id = sanitize_text_field($request->get_param('tracking_id'));
        $order       = wc_get_order($order_id);

        if (!$order) {
            return new WP_Error('invalid_order', 'Order not found', array('status' => 404));
        }

        $tracking_items = $order->get_meta($this->meta_key, true);
        if (empty($tracking_items) || !is_array($tracking_items)) {
            return new WP_Error('tracking_not_found', 'Tracking not found', array('status' => 404));
        }

        $found         = false;
        $updated_items = array();
        $deleted_number = '';

        foreach ($tracking_items as $tracking) {
            if (isset($tracking['tracking_id']) && $tracking['tracking_id'] === $tracking_id) {
                $found          = true;
                $deleted_number = isset($tracking['tracking_number']) ? $tracking['tracking_number'] : $tracking_id;
                continue; // skip = delete
            }
            $updated_items[] = $tracking;
        }

        if (!$found) {
            return new WP_Error('tracking_not_found', 'Tracking not found', array('status' => 404));
        }

        if (empty($updated_items)) {
            $order->delete_meta_data($this->meta_key);
        } else {
            $order->update_meta_data($this->meta_key, array_values($updated_items));
        }
        $order->save();

        $order->add_order_note(sprintf('Shipment tracking deleted via POS: %s', $deleted_number));

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Tracking deleted successfully',
        ), 200);
    }

    /* ================================================================
     *  Helpers
     * ================================================================ */

    /**
     * Convert a tracking entry (which may contain stdClass objects in
     * products_list) into a plain-array structure safe for JSON output.
     */
    private function normalize_tracking_for_response($tracking) {
        $item = $tracking;

        if (!empty($item['products_list']) && is_array($item['products_list'])) {
            $products = array();
            foreach ($item['products_list'] as $product_obj) {
                if (is_object($product_obj)) {
                    $products[] = array(
                        'product' => isset($product_obj->product) ? $product_obj->product : '',
                        'item_id' => isset($product_obj->item_id) ? $product_obj->item_id : '',
                        'qty'     => isset($product_obj->qty)     ? $product_obj->qty     : '1',
                    );
                } elseif (is_array($product_obj)) {
                    $products[] = array(
                        'product' => isset($product_obj['product']) ? $product_obj['product'] : '',
                        'item_id' => isset($product_obj['item_id']) ? $product_obj['item_id'] : '',
                        'qty'     => isset($product_obj['qty'])     ? $product_obj['qty']     : '1',
                    );
                }
            }
            $item['products_list'] = $products;
        } else {
            $item['products_list'] = array();
        }

        return $item;
    }
}

// Initialize plugin
add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        DS_Shipment_Tracking::get_instance();
    }
});

// Add "Shipped" status to WooCommerce if it doesn't exist
add_action('init', function () {
    if (!function_exists('wc_get_order_statuses')) {
        return;
    }

    $order_statuses = wc_get_order_statuses();
    if (!isset($order_statuses['wc-shipped'])) {
        register_post_status('wc-shipped', array(
            'label'                     => _x('Shipped', 'Order status', 'woocommerce'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop(
                'Shipped <span class="count">(%s)</span>',
                'Shipped <span class="count">(%s)</span>',
                'woocommerce'
            ),
        ));
    }
});

// Add "Shipped" to order status list
add_filter('wc_order_statuses', function ($order_statuses) {
    $new_order_statuses = array();

    foreach ($order_statuses as $key => $status) {
        $new_order_statuses[$key] = $status;

        if ('wc-processing' === $key) {
            $new_order_statuses['wc-shipped'] = _x('Shipped', 'Order status', 'woocommerce');
        }
    }

    return $new_order_statuses;
});
