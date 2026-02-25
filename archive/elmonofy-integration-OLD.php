<?php
/**
 * Plugin Name: Elmonofy ERP Integration
 * Description: Synchronizes WooCommerce orders with ERP and updates stock/prices via REST API.
 * Version: 5.1.0
 * Author: Bido & Najdi
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Constants
 */
define( 'ELMONOFY_VERSION', '5.1.0' );
define( 'ELMONOFY_SYNC_META', '_elmonofy_synced' );
define( 'ELMONOFY_RETRY_COUNT', '_elmonofy_retry_count' );

/**
 * 1. Initialize Settings & Admin Menu
 */
add_action( 'admin_menu', 'elmonofy_add_settings_page' );
add_action( 'admin_init', 'elmonofy_register_settings' );

function elmonofy_add_settings_page() {
    add_options_page( 'Elmonofy Integration', 'Elmonofy ERP', 'manage_options', 'elmonofy-erp', 'elmonofy_settings_html' );
}

function elmonofy_register_settings() {
    register_setting( 'elmonofy_settings_group', 'elmonofy_erp_url' );
    register_setting( 'elmonofy_settings_group', 'elmonofy_erp_token' );
    register_setting( 'elmonofy_settings_group', 'elmonofy_pickup_warehouse' );
    
    if ( false === get_option( 'elmonofy_pickup_warehouse' ) ) {
        update_option( 'elmonofy_pickup_warehouse', 'Online - EG' );
    }
}

function elmonofy_settings_html() {
    ?>
    <div class="wrap">
        <h1>Elmonofy ERP Integration Settings v<?php echo ELMONOFY_VERSION; ?></h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'elmonofy_settings_group' ); ?>
            <table class="form-table">
                <tr>
                    <th>ERP Base URL</th>
                    <td><input type="text" name="elmonofy_erp_url" value="<?php echo esc_attr( get_option('elmonofy_erp_url') ); ?>" class="regular-text" placeholder="https://erp.example.com" /></td>
                </tr>
                <tr>
                    <th>ERP Auth Token</th>
                    <td><input type="password" name="elmonofy_erp_token" value="<?php echo esc_attr( get_option('elmonofy_erp_token') ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th>Pickup Warehouse</th>
                    <td><input type="text" name="elmonofy_pickup_warehouse" value="<?php echo esc_attr( get_option('elmonofy_pickup_warehouse', 'Online - EG') ); ?>" class="regular-text" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <hr>
        <h2>Connection Test</h2>
        <button id="elmonofy-test-connection" class="button button-secondary">Test Connection to ERP</button>
        <div id="elmonofy-test-result" style="margin-top:10px; padding:10px; border-radius:4px; display:none;"></div>

        <script>
jQuery(document).ready(function($) {
            .on('click', function(e) {
                e.preventDefault();
                var btn = ;
                btn.prop('disabled', true).text('Testing...');
                .hide();

                $.post(ajaxurl, {
                    action: 'elmonofy_test_connection',
                    nonce: '<?php echo wp_create_nonce("elmonofy_test_nonce"); ?>'
                }, function(response) {
                    btn.prop('disabled', false).text('Test Connection to ERP');
                    var color = response.success ? '#d4edda' : '#f8d7da';
                    var border = response.success ? '#c3e6cb' : '#f5c6cb';
                    var textColor = response.success ? '#155724' : '#721c24';
                    
                    
                        .css({'background': color, 'border': '1px solid ' + border, 'color': textColor})
                        .html(response.data.message)
                        .show();
                });
            });
        });
        </script>
    </div>
    <?php
}

/**
 * 2. AJAX Handler: Test Connection
 */
add_action( 'wp_ajax_elmonofy_test_connection', 'elmonofy_handle_test_connection' );
function elmonofy_handle_test_connection() {
    check_ajax_referer( 'elmonofy_test_nonce', 'nonce' );
    $erp_url = get_option('elmonofy_erp_url');
    $token = get_option('elmonofy_erp_token');
    if ( empty($erp_url) || empty($token) ) {
        wp_send_json_error( ['message' => 'Please save ERP URL and Token first.'] );
    }
    $ping_url = trailingslashit($erp_url) . 'api/method/frappe.auth.get_logged_user';
    $response = wp_remote_get( $ping_url, [
        'headers' => [ 'Authorization' => 'token ' . $token ],
        'timeout' => 15
    ]);
    if ( is_wp_error( $response ) ) {
        wp_send_json_error( ['message' => 'Connection Failed: ' . $response->get_error_message()] );
    }
    $code = wp_remote_retrieve_response_code( $response );
    if ( $code === 200 ) {
        wp_send_json_success( ['message' => 'Connected successfully! ERP is reachable.'] );
    } else {
        wp_send_json_error( ['message' => "ERP returned error code $code. Check your token and URL."] );
    }
}

/**
 * 3. Outgoing Sync Logic
 */
add_action( 'woocommerce_checkout_order_processed', 'elmonofy_erp_schedule_sync', 20, 1 );
add_action( 'elmonofy_erp_async_sync', 'elmonofy_execute_sync', 10, 1 );

function elmonofy_erp_schedule_sync($order_id) {
    if (function_exists('as_schedule_single_action')) {
        as_schedule_single_action(time(), 'elmonofy_erp_async_sync', ['order_id' => $order_id], 'elmonofy-erp');
    } else {
        wp_schedule_single_event(time(), 'elmonofy_erp_async_sync', ['order_id' => $order_id]);
    }
}

function elmonofy_execute_sync( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order || $order->get_meta( ELMONOFY_SYNC_META ) === 'yes' ) return;

    $erp_url = get_option('elmonofy_erp_url');
    $token = get_option('elmonofy_erp_token');
    $warehouse = get_option('elmonofy_pickup_warehouse', 'Online - EG');
    $logger = wc_get_logger();
    $log_context = [ 'source' => 'elmonofy-erp' ];

    if ( empty($erp_url) || empty($token) ) {
        $msg = "ERP Sync Failed: Missing Credentials for Order #$order_id";
        $logger->error( $msg, $log_context );
        $order->add_order_note( $msg );
        return;
    }

    $endpoint = trailingslashit($erp_url) . 'api/method/woocommerce_integration.api.sales.process_order_webhook';

    $payload = [
        'order' => [
            'order_id'       => (string)$order_id,
            'status'         => $order->get_status(),
            'order_type'     => $order->is_paid() ? 'Paid' : 'Pickup',
            'date_created'   => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : current_time('mysql'),
            'currency'       => $order->get_currency(),
            'total'          => (float)$order->get_total(),
            'subtotal'       => (float)$order->get_subtotal(),
            'total_tax'      => (float)$order->get_total_tax(),
            'shipping_total' => (float)$order->get_shipping_total(),
            'discount_total' => (float)$order->get_discount_total(),
            'payment_method' => $order->get_payment_method_title(),
            'pickup_warehouse' => $warehouse,
            'billing' => [
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'address_1'  => $order->get_billing_address_1(),
                'city'       => $order->get_billing_city(),
                'country'    => $order->get_billing_country(),
                'email'      => $order->get_billing_email(),
                'phone'      => $order->get_billing_phone(),
            ],
            'shipping' => [
                'first_name' => $order->get_shipping_first_name(),
                'last_name'  => $order->get_shipping_last_name(),
                'address_1'  => $order->get_shipping_address_1(),
                'city'       => $order->get_shipping_city(),
                'country'    => $order->get_shipping_country(),
            ],
            'line_items' => []
        ],
        'payment' => [
            'payment_id'       => $order->get_transaction_id() ?: 'woo_' . $order_id,
            'amount'           => (float)$order->get_total(),
            'payment_datetime' => current_time('mysql'),
        ]
    ];

    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        if (!$product) continue;
        $qty = (int)$item->get_quantity();
        $payload['order']['line_items'][] = [
            'sku'      => $product->get_sku(),
            'quantity' => $qty,
            'rate'     => $qty > 0 ? (float)$item->get_total() / $qty : 0,
        ];
    }

    $response = wp_remote_post( $endpoint, [
        'method'    => 'POST',
        'timeout'   => 45,
        'headers'   => [ 'Content-Type' => 'application/json', 'Authorization' => 'token ' . $token ],
        'body'      => json_encode($payload),
    ]);

    if ( is_wp_error( $response ) ) {
        $error_msg = $response->get_error_message();
        $logger->error( "Order #$order_id Sync Error: $error_msg", $log_context );
        $order->add_order_note( "ERP Sync Error: $error_msg" );
        elmonofy_schedule_retry( $order_id );
    } else {
        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            $order->update_meta_data( ELMONOFY_SYNC_META, 'yes' );
            $order->add_order_note( "ERP Sync Success" );
            $order->save();
        } else {
            $logger->error( "Order #$order_id Sync Failed (HTTP $code)", $log_context );
            $order->add_order_note( "ERP Sync Failed (HTTP $code)" );
            elmonofy_schedule_retry( $order_id );
        }
    }
}

/**
 * 4. Retry Logic
 */
function elmonofy_schedule_retry( $order_id ) {
    $retries = (int) get_post_meta( $order_id, ELMONOFY_RETRY_COUNT, true );
    if ( $retries < 3 ) {
        update_post_meta( $order_id, ELMONOFY_RETRY_COUNT, $retries + 1 );
        $wait = ( $retries + 1 ) * 15 * MINUTE_IN_SECONDS;
        wp_schedule_single_event( time() + $wait, 'elmonofy_erp_async_sync', ['order_id' => $order_id] );
    }
}

/**
 * 5. Admin UI: Orders List Column
 */
add_filter( 'manage_edit-shop_order_columns', 'elmonofy_add_sync_column' );
function elmonofy_add_sync_column( $columns ) {
    $new_columns = array();
    foreach ( $columns as $key => $column ) {
        $new_columns[ $key ] = $column;
        if ( 'order_status' === $key ) {
            $new_columns['erp_sync'] = 'ERP Sync';
        }
    }
    return $new_columns;
}

add_action( 'manage_shop_order_posts_custom_column', 'elmonofy_fill_sync_column' );
function elmonofy_fill_sync_column( $column ) {
    if ( 'erp_sync' === $column ) {
        global $post;
        $order = wc_get_order( $post->ID );
        $synced = $order->get_meta( ELMONOFY_SYNC_META );
        if ( 'yes' === $synced ) {
            echo '<span class="tips" data-tip="Synced">Synced</span>';
        } else {
            echo '<span class="tips" data-tip="Pending">Pending</span>';
        }
    }
}

/**
 * 6. Incoming Sync: Update Stock & Price + Product Management
 */
add_action('rest_api_init', function () {
    register_rest_route('elmonofy/v1', '/update-stock', [
        'methods'  => 'POST',
        'callback' => 'elmonofy_handle_stock_update',
        'permission_callback' => 'elmonofy_verify_token',
    ]);

    register_rest_route('elmonofy/v1', '/products', [
        [
            'methods'  => 'POST',
            'callback' => 'elmonofy_handle_create_product',
            'permission_callback' => 'elmonofy_verify_token',
        ],
        [
            'methods'  => 'GET',
            'callback' => 'elmonofy_handle_get_products',
            'permission_callback' => 'elmonofy_verify_token',
        ],
    ]);

    register_rest_route('elmonofy/v1', '/products/(?P<sku>[a-zA-Z0-9_\-\.]+)', [
        [
            'methods'  => 'GET',
            'callback' => 'elmonofy_handle_get_product_by_sku',
            'permission_callback' => 'elmonofy_verify_token',
        ],
        [
            'methods'  => 'DELETE',
            'callback' => 'elmonofy_handle_delete_product',
            'permission_callback' => 'elmonofy_verify_token',
        ],
    ]);
});

function elmonofy_verify_token($request) {
    $token = get_option('elmonofy_erp_token');
    $auth  = $request->get_header('Authorization');
    if ( empty($auth) ) {
        return false;
    }
    return hash_equals( 'token ' . $token, $auth );
}

function elmonofy_handle_stock_update($request) {
    $params = $request->get_json_params();
    if ( empty($params['sku']) ) return new WP_Error('no_sku', 'SKU required', ['status' => 400]);
    $sku = sanitize_text_field($params['sku']);
    $product_id = wc_get_product_id_by_sku($sku);
    if (!$product_id) return new WP_Error('not_found', 'Product not found', ['status' => 404]);
    $product = wc_get_product($product_id);
    if (isset($params['stock_qty'])) {
        $product->set_stock_quantity(intval($params['stock_qty']));
        $product->set_manage_stock(true);
    }
    if (isset($params['price'])) $product->set_regular_price(wc_format_decimal($params['price']));
    $product->save();
    return rest_ensure_response([
        'status' => 'success',
        'id'     => $product_id,
        'sku'    => $sku,
    ]);
}

function elmonofy_handle_create_product($request) {
    $params = $request->get_json_params();
    if ( empty($params) ) {
        $params = $request->get_params();
    }

    if ( empty($params['name']) ) return new WP_Error('no_name', 'Product name required', ['status' => 400]);
    if ( empty($params['sku']) )  return new WP_Error('no_sku', 'SKU required', ['status' => 400]);

    $sku = sanitize_text_field($params['sku']);
    $existing_id = wc_get_product_id_by_sku($sku);

    if ($existing_id) {
        return new WP_Error('sku_exists', 'A product with this SKU already exists.', ['status' => 409]);
    }

    $product = new WC_Product_Simple();
    $product->set_name( sanitize_text_field($params['name']) );
    $product->set_sku( $sku );
    $product->set_status('draft');

    if ( isset($params['price']) ) {
        $product->set_regular_price( wc_format_decimal($params['price']) );
    }

    if ( isset($params['stock_qty']) ) {
        $product->set_manage_stock(true);
        $product->set_stock_quantity( intval($params['stock_qty']) );
    }

    $product_id = $product->save();

    $response = rest_ensure_response([
        'status' => 'created',
        'id'     => $product_id,
        'sku'    => $sku,
    ]);
    $response->set_status(201);
    return $response;
}

function elmonofy_handle_get_products($request) {
    $page     = max(1, intval($request->get_param('page') ?: 1));
    $per_page = max(1, intval($request->get_param('per_page') ?: 50));

    $results = wc_get_products([
        'status'   => 'publish',
        'limit'    => $per_page,
        'page'     => $page,
        'paginate' => true,
        'return'   => 'objects',
    ]);

    $products_data = [];
    foreach ($results->products as $product) {
        $products_data[] = [
            'id'        => $product->get_id(),
            'name'      => $product->get_name(),
            'sku'       => $product->get_sku(),
            'price'     => (float)$product->get_price(),
            'stock_qty' => $product->managing_stock() ? $product->get_stock_quantity() : null,
            'status'    => $product->get_status(),
        ];
    }

    return rest_ensure_response([
        'total'     => $results->total,
        'max_pages' => $results->max_num_pages,
        'page'      => $page,
        'per_page'  => $per_page,
        'products'  => $products_data,
    ]);
}

function elmonofy_handle_get_product_by_sku($request) {
    $sku = sanitize_text_field($request->get_param('sku'));
    $product_id = wc_get_product_id_by_sku($sku);

    if (!$product_id) {
        return new WP_Error('not_found', 'Product not found', ['status' => 404]);
    }

    $product = wc_get_product($product_id);

    return rest_ensure_response([
        'id'        => $product->get_id(),
        'name'      => $product->get_name(),
        'sku'       => $product->get_sku(),
        'price'     => (float)$product->get_price(),
        'stock_qty' => $product->managing_stock() ? $product->get_stock_quantity() : null,
        'status'    => $product->get_status(),
    ]);
}

function elmonofy_handle_delete_product($request) {
    $sku = sanitize_text_field($request->get_param('sku'));
    $product_id = wc_get_product_id_by_sku($sku);

    if (!$product_id) {
        return new WP_Error('not_found', 'Product not found', ['status' => 404]);
    }

    $product = wc_get_product($product_id);
    $product->delete(false);

    return rest_ensure_response([
        'status' => 'trashed',
        'id'     => $product_id,
        'sku'    => $sku,
    ]);
}
