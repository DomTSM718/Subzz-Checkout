<?php
/**
 * Payment Handler Class - Server-Side Redirect Control Implementation
 * 
 * LONG-TERM SOLUTION IMPLEMENTATION:
 * 1. Removed all JavaScript polling and timing-dependent code
 * 2. Implemented server-side redirect control using WooCommerce hooks
 * 3. Preserved all existing Azure integration and data structures
 * 4. Added comprehensive logging for troubleshooting
 * 5. Maintains backward compatibility with existing contract signature system
 * 
 * PHASE 5A ADDITIONS:
 * 6. Added mock payment gateway page handling
 * 7. Added order complete page handling
 * 8. Extended rewrite rules for new pages
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Subzz_Payment_Handler {
    
    /**
     * Constructor - Initialize with server-side redirect control
     */
    public function __construct() {
        error_log('=== SUBZZ PAYMENT HANDLER: Initializing Server-Side Redirect Control ===');
        
        // Check if WooCommerce is active before proceeding
        if (!$this->is_woocommerce_active()) {
            error_log('SUBZZ ERROR: WooCommerce not active - cannot initialize payment handler');
            return;
        }
        
        // Initialize hooks with server-side control
        $this->init_hooks();
        
        // PHASE 5A: Initialize URL routing for new pages
        $this->init_page_routing();
        
        error_log('SUBZZ PAYMENT HANDLER: Server-side redirect control initialized successfully');
    }
    
    /**
     * Check if WooCommerce is active and available
     */
    private function is_woocommerce_active() {
        return class_exists('WooCommerce') && function_exists('WC');
    }
    
    /**
     * PHASE 5A: Initialize page routing for mock gateway and order complete
     */
    private function init_page_routing() {
        add_action('init', array($this, 'add_rewrite_rules'));
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_action('template_redirect', array($this, 'handle_custom_pages'));
        
        error_log('SUBZZ PAGE ROUTING: Mock gateway and order complete routing initialized');
    }
    
    /**
     * PHASE 5A: Add rewrite rules for mock gateway and order complete pages
     */
    public function add_rewrite_rules() {
        // Contract signature (existing)
        add_rewrite_rule(
            '^contract-signature/?$',
            'index.php?subzz_contract_page=1',
            'top'
        );

        // PHASE 5A: Mock payment gateway page
        add_rewrite_rule(
            '^mock-gateway/?$',
            'index.php?subzz_mock_gateway=1',
            'top'
        );

        // PHASE 5A: Order complete page
        add_rewrite_rule(
            '^order-complete/?$',
            'index.php?subzz_order_complete=1',
            'top'
        );

        error_log('SUBZZ DEBUG: Rewrite rules added for contract-signature, mock-gateway and order-complete URLs');
    }

    /**
     * PHASE 5A: Add query variables for new pages
     */
    public function add_query_vars($vars) {
        // Contract signature (existing)
        $vars[] = 'subzz_contract_page';
        
        // PHASE 5A: New pages
        $vars[] = 'subzz_mock_gateway';
        $vars[] = 'subzz_order_complete';
        
        return $vars;
    }

    /**
     * PHASE 5A: Handle custom page routing
     */
    public function handle_custom_pages() {
        global $wp_query;

        // Contract signature handling (existing - preserved)
        if (get_query_var('subzz_contract_page')) {
            // Note: Your existing contract signature handling should be here
            // This is just a placeholder - replace with your actual contract signature code
            error_log('SUBZZ DEBUG: Contract signature page detected - existing handler should process this');
            return;
        }

        // PHASE 5A: Mock payment gateway handling
        if (get_query_var('subzz_mock_gateway')) {
            error_log('SUBZZ DEBUG: Mock gateway page detected via query variable');
            $this->handle_mock_gateway_page();
            return;
        }

        // PHASE 5A: Order complete handling  
        if (get_query_var('subzz_order_complete')) {
            error_log('SUBZZ DEBUG: Order complete page detected via query variable');
            $this->handle_order_complete_page();
            return;
        }
    }

    /**
     * PHASE 5A: Handle mock payment gateway page
     */
    private function handle_mock_gateway_page() {
        error_log('=== SUBZZ MOCK GATEWAY: Enhanced request received ===');
        
        // Get session parameters from URL
        $session_id = isset($_GET['session']) ? sanitize_text_field($_GET['session']) : '';
        $amount = isset($_GET['amount']) ? sanitize_text_field($_GET['amount']) : '';
        $customer = isset($_GET['customer']) ? sanitize_text_field($_GET['customer']) : '';
        $reference_id = isset($_GET['reference_id']) ? sanitize_text_field($_GET['reference_id']) : '';
        $currency = isset($_GET['currency']) ? sanitize_text_field($_GET['currency']) : 'ZAR';

        error_log("SUBZZ MOCK GATEWAY: Session: $session_id, Amount: $amount, Customer: $customer, Reference: $reference_id, Currency: $currency");

        // Validate required parameters
        if (empty($session_id) || empty($reference_id)) {
            error_log('SUBZZ MOCK GATEWAY ERROR: Missing required parameters (session_id or reference_id)');
            wp_die('Invalid payment session. Missing required parameters.', 'Payment Error', array('response' => 400));
            return;
        }

        // Display mock gateway page
        $this->display_mock_gateway_page($session_id, $amount, $customer, $reference_id, $currency);
    }

    /**
     * PHASE 5A: Display mock payment gateway page
     */
    private function display_mock_gateway_page($session_id, $amount, $customer, $reference_id, $currency) {
        // Set proper headers
        header('Content-Type: text/html; charset=utf-8');
        
        // Get site URL for API calls
        $site_url = home_url();
        
        // Format amount for display
        $formatted_amount = number_format(floatval($amount), 2);
        
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Mock Payment Gateway - Subzz Testing</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    line-height: 1.6;
                    color: #333;
                    max-width: 600px;
                    margin: 50px auto;
                    padding: 20px;
                    background-color: #f5f5f5;
                }
                .gateway-container {
                    background: white;
                    padding: 30px;
                    border-radius: 8px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                .header {
                    text-align: center;
                    border-bottom: 2px solid #007cba;
                    padding-bottom: 20px;
                    margin-bottom: 30px;
                }
                .session-info {
                    background: #f8f9fa;
                    padding: 20px;
                    border-radius: 5px;
                    margin-bottom: 30px;
                    border-left: 4px solid #007cba;
                }
                .session-info h3 {
                    margin-top: 0;
                    color: #007cba;
                }
                .session-detail {
                    margin: 10px 0;
                    padding: 5px 0;
                    border-bottom: 1px solid #eee;
                }
                .session-detail:last-child {
                    border-bottom: none;
                }
                .session-detail strong {
                    display: inline-block;
                    width: 120px;
                    color: #666;
                }
                .buttons {
                    display: flex;
                    gap: 20px;
                    justify-content: center;
                    margin-top: 30px;
                }
                .btn {
                    padding: 15px 30px;
                    border: none;
                    border-radius: 5px;
                    font-size: 16px;
                    font-weight: bold;
                    cursor: pointer;
                    text-decoration: none;
                    display: inline-block;
                    text-align: center;
                    transition: all 0.3s ease;
                }
                .btn-success {
                    background-color: #28a745;
                    color: white;
                }
                .btn-success:hover {
                    background-color: #218838;
                }
                .btn-danger {
                    background-color: #dc3545;
                    color: white;
                }
                .btn-danger:hover {
                    background-color: #c82333;
                }
                .btn:disabled {
                    opacity: 0.6;
                    cursor: not-allowed;
                }
                .loading {
                    display: none;
                    text-align: center;
                    margin-top: 20px;
                    color: #666;
                }
                .error {
                    background: #f8d7da;
                    color: #721c24;
                    padding: 15px;
                    border-radius: 5px;
                    margin-top: 20px;
                    display: none;
                }
            </style>
        </head>
        <body>
            <div class="gateway-container">
                <div class="header">
                    <h1>Mock Payment Gateway</h1>
                    <p>Testing Environment - Subzz Subscription Payment</p>
                </div>

                <div class="session-info">
                    <h3>Payment Session Details</h3>
                    <div class="session-detail">
                        <strong>Session ID:</strong> <?php echo esc_html($session_id); ?>
                    </div>
                    <div class="session-detail">
                        <strong>Customer:</strong> <?php echo esc_html($customer); ?>
                    </div>
                    <div class="session-detail">
                        <strong>Amount:</strong> <?php echo esc_html($currency . ' ' . $formatted_amount); ?>
                    </div>
                    <div class="session-detail">
                        <strong>Reference:</strong> <?php echo esc_html($reference_id); ?>
                    </div>
                </div>

                <div class="buttons">
                    <button class="btn btn-success" onclick="processPayment('success')" id="successBtn">
                        Simulate Success
                    </button>
                    <button class="btn btn-danger" onclick="processPayment('failed')" id="failBtn">
                        Simulate Failure
                    </button>
                </div>

                <div class="loading" id="loading">
                    Processing payment... Please wait.
                </div>

                <div class="error" id="error"></div>
            </div>

            <script>
                function processPayment(status) {
                    // Disable buttons and show loading
                    document.getElementById('successBtn').disabled = true;
                    document.getElementById('failBtn').disabled = true;
                    document.getElementById('loading').style.display = 'block';
                    document.getElementById('error').style.display = 'none';

                    console.log('MOCK GATEWAY: Processing payment with status:', status);

                    // Call Azure API to update payment status
                    const updateData = {
                        newStatus: status === 'success' ? 'payment_completed' : 'payment_failed',
                        reason: status === 'success' ? 'Mock payment success' : 'Mock payment declined'
                    };

                    // Update order status via Azure API
                    fetch('<?php echo esc_url($site_url); ?>/wp-admin/admin-ajax.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            action: 'subzz_mock_payment_update',
                            reference_id: '<?php echo esc_js($reference_id); ?>',
                            status: status,
                            session_id: '<?php echo esc_js($session_id); ?>',
                            nonce: '<?php echo wp_create_nonce('subzz_mock_payment'); ?>'
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        console.log('MOCK GATEWAY: Azure update response:', data);
                        
                        if (data.success) {
                            // Redirect to order complete page
                            const returnUrl = '<?php echo esc_url($site_url); ?>/order-complete/?reference_id=<?php echo esc_js($reference_id); ?>&status=' + status;
                            console.log('MOCK GATEWAY: Redirecting to:', returnUrl);
                            window.location.href = returnUrl;
                        } else {
                            showError('Payment processing failed: ' + (data.data || 'Unknown error'));
                        }
                    })
                    .catch(error => {
                        console.error('MOCK GATEWAY: Error:', error);
                        showError('Network error occurred. Please try again.');
                    });
                }

                function showError(message) {
                    document.getElementById('loading').style.display = 'none';
                    document.getElementById('successBtn').disabled = false;
                    document.getElementById('failBtn').disabled = false;
                    document.getElementById('error').textContent = message;
                    document.getElementById('error').style.display = 'block';
                }
            </script>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * PHASE 5A: Handle order complete page
     */
    private function handle_order_complete_page() {
        error_log('=== SUBZZ ORDER COMPLETE: Enhanced request received ===');
        
        // Get parameters from URL
        $reference_id = isset($_GET['reference_id']) ? sanitize_text_field($_GET['reference_id']) : '';
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $reason = isset($_GET['reason']) ? sanitize_text_field($_GET['reason']) : '';

        error_log("SUBZZ ORDER COMPLETE: Reference: $reference_id, Status: $status, Reason: $reason");

        // Validate required parameters
        if (empty($reference_id)) {
            error_log('SUBZZ ORDER COMPLETE ERROR: Missing reference_id parameter');
            wp_die('Invalid order reference. Unable to display order completion.', 'Order Error', array('response' => 400));
            return;
        }

        // Display order complete page
        $this->display_order_complete_page($reference_id, $status, $reason);
    }

    /**
     * PHASE 5A: Display order complete page  
     */
    private function display_order_complete_page($reference_id, $status, $reason) {
        // TODO: In next step, we'll add Azure API integration to fetch order details
        // For now, display basic completion page
        
        // Set proper headers
        header('Content-Type: text/html; charset=utf-8');
        
        $is_success = ($status === 'success');
        $page_title = $is_success ? 'Order Complete - Payment Successful' : 'Payment Failed';
        
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo esc_html($page_title); ?></title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    line-height: 1.6;
                    color: #333;
                    max-width: 800px;
                    margin: 50px auto;
                    padding: 20px;
                    background-color: #f5f5f5;
                }
                .completion-container {
                    background: white;
                    padding: 40px;
                    border-radius: 8px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                    text-align: center;
                }
                .status-icon {
                    font-size: 60px;
                    margin-bottom: 20px;
                }
                .success {
                    color: #28a745;
                }
                .failed {
                    color: #dc3545;
                }
                .order-info {
                    background: #f8f9fa;
                    padding: 20px;
                    border-radius: 5px;
                    margin: 30px 0;
                    text-align: left;
                }
                .order-detail {
                    margin: 10px 0;
                    padding: 5px 0;
                    border-bottom: 1px solid #eee;
                }
                .order-detail:last-child {
                    border-bottom: none;
                }
                .order-detail strong {
                    display: inline-block;
                    width: 150px;
                    color: #666;
                }
                .btn {
                    display: inline-block;
                    padding: 12px 24px;
                    background-color: #007cba;
                    color: white;
                    text-decoration: none;
                    border-radius: 5px;
                    margin-top: 20px;
                    transition: background-color 0.3s ease;
                }
                .btn:hover {
                    background-color: #005a87;
                }
            </style>
        </head>
        <body>
            <div class="completion-container">
                <?php if ($is_success): ?>
                    <div class="status-icon success">✓</div>
                    <h1>Payment Successful!</h1>
                    <p>Thank you for your subscription. Your payment has been processed successfully.</p>
                <?php else: ?>
                    <div class="status-icon failed">✗</div>
                    <h1>Payment Failed</h1>
                    <p>Unfortunately, your payment could not be processed.</p>
                    <?php if (!empty($reason)): ?>
                        <p><strong>Reason:</strong> <?php echo esc_html($reason); ?></p>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="order-info">
                    <h3>Order Information</h3>
                    <div class="order-detail">
                        <strong>Reference ID:</strong> <?php echo esc_html($reference_id); ?>
                    </div>
                    <div class="order-detail">
                        <strong>Status:</strong> <?php echo esc_html($status); ?>
                    </div>
                    <div class="order-detail">
                        <strong>Date:</strong> <?php echo esc_html(date('Y-m-d H:i:s')); ?>
                    </div>
                    
                    <!-- TODO: Add order details from Azure API lookup -->
                    <div class="order-detail">
                        <strong>Next Steps:</strong> 
                        <?php if ($is_success): ?>
                            You will receive a confirmation email shortly with your subscription details.
                        <?php else: ?>
                            Please try again or contact support for assistance.
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($is_success): ?>
                    <a href="<?php echo esc_url(home_url('/shop/')); ?>" class="btn">Continue Shopping</a>
                <?php else: ?>
                    <a href="<?php echo esc_url(home_url('/subscription-payment/?reference_id=' . $reference_id . '&retry=yes')); ?>" class="btn">Try Again</a>
                <?php endif; ?>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
    
    /**
     * Initialize WooCommerce hooks - SERVER-SIDE REDIRECT CONTROL
     */
    private function init_hooks() {
        // PHASE 5 CHECKOUT: Redirect WooCommerce checkout to custom checkout for subscriptions
        add_action('template_redirect', [$this, 'redirect_subscription_to_custom_checkout'], 1);

        // PRIMARY METHOD: Block Checkout Store API (PRESERVED - WORKING)
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'block_checkout_server_interception'], 1, 2);

        // SERVER-SIDE REDIRECT CONTROL: Override WooCommerce's natural redirect behavior
        add_action('woocommerce_checkout_order_processed', [$this, 'handle_subscription_order_redirect'], 5, 3);
        add_filter('woocommerce_get_checkout_order_received_url', [$this, 'override_thank_you_page_redirect'], 10, 2);
        add_action('template_redirect', [$this, 'catch_and_redirect_order_received'], 5);

        // PRESERVED: All AJAX handlers for backwards compatibility and debugging
        add_action('wp_ajax_subzz_check_subscription_cart', [$this, 'ajax_check_subscription_cart']);
        add_action('wp_ajax_nopriv_subzz_check_subscription_cart', [$this, 'ajax_check_subscription_cart']);
        add_action('wp_ajax_subzz_check_redirect_requirement', [$this, 'ajax_check_redirect_requirement']);
        add_action('wp_ajax_nopriv_subzz_check_redirect_requirement', [$this, 'ajax_check_redirect_requirement']);
        add_action('wp_ajax_subzz_check_order_redirect_requirement', [$this, 'ajax_check_order_redirect_requirement']);
        add_action('wp_ajax_nopriv_subzz_check_order_redirect_requirement', [$this, 'ajax_check_order_redirect_requirement']);

        // PHASE 5A: Mock payment update handler (logged-in only — no nopriv to prevent unauthenticated order status changes)
        add_action('wp_ajax_subzz_mock_payment_update', [$this, 'ajax_mock_payment_update']);

        // PHASE 5: Checkout subscription AJAX handlers (logged-in only — checkout page requires login)
        add_action('wp_ajax_subzz_get_plan_cards', [$this, 'ajax_get_plan_cards']);
        add_action('wp_ajax_subzz_store_checkout_order', [$this, 'ajax_store_checkout_order']);

        // PRESERVED: Traditional checkout process fallback
        add_action('woocommerce_checkout_process', [$this, 'traditional_checkout_fallback'], 1);

        // OPTIONAL: Debugging scripts (can be enabled for testing)
        add_action('wp_enqueue_scripts', [$this, 'enqueue_debugging_scripts']);

        error_log('SUBZZ HOOKS: Server-side redirect control hooks registered with backwards compatibility');
    }

    /**
     * PHASE 5A: AJAX handler for mock payment status updates
     */
    public function ajax_mock_payment_update() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'subzz_mock_payment')) {
            error_log('SUBZZ MOCK PAYMENT: Nonce verification failed');
            wp_send_json_error('Security verification failed');
            return;
        }

        $reference_id = sanitize_text_field($_POST['reference_id'] ?? '');
        $status = sanitize_text_field($_POST['status'] ?? '');
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');

        error_log("SUBZZ MOCK PAYMENT: Processing payment update - Reference: $reference_id, Status: $status, Session: $session_id");

        if (empty($reference_id) || empty($status)) {
            error_log('SUBZZ MOCK PAYMENT ERROR: Missing required parameters');
            wp_send_json_error('Missing required parameters');
            return;
        }

        try {
            // Call Azure API to update order status
            $azure_client = new Subzz_Azure_API_Client();
            
            $new_status = ($status === 'success') ? 'payment_completed' : 'payment_failed';
            $reason = ($status === 'success') ? 'Mock payment success' : 'Mock payment declined';
            
            error_log("SUBZZ MOCK PAYMENT: Calling Azure API - Status: $new_status, Reason: $reason");
            
            // Use existing order status update endpoint
            $result = $azure_client->update_order_status($reference_id, $new_status, $reason);
            
            if ($result) {
                error_log("SUBZZ MOCK PAYMENT: Azure update successful for reference: $reference_id");
                wp_send_json_success([
                    'message' => 'Payment status updated successfully',
                    'reference_id' => $reference_id,
                    'status' => $new_status
                ]);
            } else {
                error_log("SUBZZ MOCK PAYMENT ERROR: Azure update failed for reference: $reference_id");
                wp_send_json_error('Failed to update payment status in Azure');
            }

        } catch (Exception $e) {
            error_log('SUBZZ MOCK PAYMENT EXCEPTION: ' . $e->getMessage());
            wp_send_json_error('Payment processing error: ' . $e->getMessage());
        }
    }
    
    /**
     * PHASE 5: Redirect WooCommerce checkout to custom subscription checkout
     * if cart contains a subscription product.
     */
    public function redirect_subscription_to_custom_checkout() {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }
        // Don't redirect on order-received endpoint
        if (is_wc_endpoint_url('order-received')) {
            return;
        }
        if (!WC()->cart || WC()->cart->is_empty()) {
            return;
        }

        foreach (WC()->cart->get_cart() as $cart_item) {
            $subscription_enabled = get_post_meta($cart_item['product_id'], '_subzz_subscription_enabled', true);
            if ($subscription_enabled === 'yes') {
                error_log('SUBZZ CHECKOUT REDIRECT: Subscription product in cart — redirecting to /checkout-subscription/');
                wp_redirect(home_url('/checkout-subscription/'));
                exit;
            }
        }
    }

    /**
     * PHASE 5 AJAX: Proxy plan cards request to Azure API
     */
    public function ajax_get_plan_cards() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'subzz_checkout_subscription')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }

        $email = sanitize_email($_POST['email'] ?? '');
        $price = floatval($_POST['product_price_incl_vat'] ?? 0);

        if (empty($email) || $price <= 0) {
            wp_send_json_error(array('message' => 'Missing email or product price'));
            return;
        }

        error_log('SUBZZ CHECKOUT AJAX: Fetching plan cards for ' . $email . ' price=' . $price);

        $azure_client = new Subzz_Azure_API_Client();
        $result = $azure_client->get_plan_cards($email, $price);

        if ($result) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error(array('message' => 'Unable to load plan cards from backend'));
        }
    }

    /**
     * PHASE 5 AJAX: Store checkout order in Azure, create WooCommerce order, return JWT
     */
    public function ajax_store_checkout_order() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'subzz_checkout_subscription')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }

        $customer_email = sanitize_email($_POST['customer_email'] ?? '');
        $product_id = intval($_POST['product_id'] ?? 0);
        $variation_id = intval($_POST['variation_id'] ?? 0);
        $product_name = sanitize_text_field($_POST['product_name'] ?? '');
        $product_price = floatval($_POST['product_price_incl_vat'] ?? 0);
        $selected_term = intval($_POST['selected_term_months'] ?? 18);
        $standard_monthly = floatval($_POST['standard_monthly_amount'] ?? 0);
        $initial_payment = floatval($_POST['initial_payment_amount'] ?? 0);
        $reduced_monthly = floatval($_POST['reduced_monthly_amount'] ?? 0);
        $total_sub_value = floatval($_POST['total_subscription_value'] ?? 0);
        $billing_day = intval($_POST['billing_day'] ?? 1);
        $address_street = sanitize_text_field($_POST['address_street'] ?? '');
        $address_city = sanitize_text_field($_POST['address_city'] ?? '');
        $address_province = sanitize_text_field($_POST['address_province'] ?? '');
        $address_postal = sanitize_text_field($_POST['address_postal'] ?? '');

        if (empty($customer_email) || $product_id <= 0) {
            wp_send_json_error(array('message' => 'Missing required order data'));
            return;
        }

        error_log('SUBZZ CHECKOUT AJAX: Storing order — email=' . $customer_email . ' term=' . $selected_term . ' initial=' . $initial_payment);

        // Get current user for name
        $user = get_user_by('email', $customer_email);
        $first_name = $user ? $user->first_name : '';
        $last_name = $user ? $user->last_name : '';

        // Create WooCommerce order programmatically
        $wc_order = wc_create_order(array('status' => 'pending'));
        $product = wc_get_product($variation_id ?: $product_id);

        if ($product) {
            $wc_order->add_product($product, 1);
        }

        $wc_order->set_billing_email($customer_email);
        $wc_order->set_billing_first_name($first_name);
        $wc_order->set_billing_last_name($last_name);
        $wc_order->set_billing_address_1($address_street);
        $wc_order->set_billing_city($address_city);
        $wc_order->set_billing_state($address_province);
        $wc_order->set_billing_postcode($address_postal);
        $wc_order->set_billing_country('ZA');

        // Set the actual payment amount (initial or monthly)
        $payment_amount = $initial_payment > 0 ? $initial_payment : $standard_monthly;
        $wc_order->set_total($payment_amount);
        $wc_order->calculate_totals();

        // Store subscription meta
        $wc_order->add_meta_data('_subzz_subscription_enabled', 'yes');
        $wc_order->add_meta_data('_subzz_selected_term_months', $selected_term);
        $wc_order->add_meta_data('_subzz_standard_monthly_amount', $standard_monthly);
        $wc_order->add_meta_data('_subzz_initial_payment_amount', $initial_payment);
        $wc_order->add_meta_data('_subzz_reduced_monthly_amount', $reduced_monthly);
        $wc_order->add_meta_data('_subzz_total_subscription_value', $total_sub_value);
        $wc_order->add_meta_data('_subzz_billing_day', $billing_day);
        $wc_order->add_meta_data('_subzz_contract_required', 'yes');
        $wc_order->save();

        $wc_order_id = $wc_order->get_id();
        error_log('SUBZZ CHECKOUT AJAX: WooCommerce order created — ID=' . $wc_order_id);

        // Prepare Azure order data
        $order_data = array(
            'woocommerce_order_id' => $wc_order_id,
            'customer_email' => $customer_email,
            'customer_data' => array(
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $customer_email
            ),
            'billing_address' => array(
                'address_1' => $address_street,
                'city' => $address_city,
                'state' => $address_province,
                'postcode' => $address_postal,
                'country' => 'ZA'
            ),
            'order_items' => array(
                array(
                    'product_id' => $product_id,
                    'name' => $product_name,
                    'quantity' => 1,
                    'price' => $product_price,
                    'is_subscription' => true
                )
            ),
            'order_totals' => array(
                'subtotal' => $product_price,
                'total' => strval($payment_amount),
                'currency' => 'ZAR'
            ),
            'initial_payment_amount' => $initial_payment,
            'standard_monthly_amount' => $standard_monthly,
            'reduced_monthly_amount' => $reduced_monthly,
            'selected_term_months' => $selected_term,
            'total_subscription_value' => $total_sub_value,
            'billing_day_of_month' => $billing_day,
            'created_at' => current_time('mysql'),
            'wordpress_site' => home_url(),
            'order_status' => 'pending'
        );

        // Store in Azure
        $azure_client = new Subzz_Azure_API_Client();
        $reference_id = $azure_client->store_order_data($order_data);

        if (!$reference_id) {
            error_log('SUBZZ CHECKOUT AJAX ERROR: Azure store failed');
            $wc_order->add_order_note('SUBZZ: Azure order storage failed');
            wp_send_json_error(array('message' => 'Unable to process order. Please try again.'));
            return;
        }

        error_log('SUBZZ CHECKOUT AJAX: Azure reference=' . $reference_id);

        // Update WC order with reference
        $wc_order->add_meta_data('_subzz_reference_id', $reference_id);
        $wc_order->add_meta_data('_subzz_azure_stored', 'yes');
        $wc_order->add_order_note('SUBZZ: Order stored in Azure, Reference ID: ' . $reference_id);
        $wc_order->save();

        // Generate JWT token for contract signature page
        $jwt_token = $this->generate_jwt_token($reference_id, $customer_email);
        $signature_url = home_url('/contract-signature/?token=' . $jwt_token);

        // Store session data in WC session (not PHP session — A14)
        if (WC()->session) {
            WC()->session->set('subzz_reference_id', $reference_id);
            WC()->session->set('subzz_jwt_token', $jwt_token);
        }

        // Clear cart after order is successfully created — prevents stale products
        // appearing when customer starts a new checkout later
        if (WC()->cart) {
            WC()->cart->empty_cart();
            error_log('SUBZZ CHECKOUT AJAX: Cart cleared after successful order creation');
        }

        wp_send_json_success(array(
            'reference_id' => $reference_id,
            'wc_order_id' => $wc_order_id,
            'signature_url' => $signature_url
        ));
    }

    /**
     * PRIMARY METHOD: Block Checkout Store API interception - PRESERVED WITH ENHANCED REDIRECT SETUP
     */
    public function block_checkout_server_interception($order, $request) {
        error_log('=== SUBZZ BLOCK CHECKOUT: Server API Interception Fired ===');
        error_log('SUBZZ DATA FLOW: Order ID: ' . $order->get_id());
        error_log('SUBZZ DATA FLOW: Customer Email: ' . $order->get_billing_email());
        
        // Check order items for subscription products with detailed logging
        $items = $order->get_items();
        $has_subscription = false;
        $subscription_products = [];
        
        error_log('SUBZZ DATA FLOW: Checking ' . count($items) . ' order items for subscriptions');
        
        foreach ($items as $item_id => $item) {
            $product_id = $item->get_product_id();
            $product_name = $item->get_name();
            $is_subscription = $this->is_subscription_product($product_id);
            
            error_log("SUBZZ DATA FLOW: Item {$item_id} - Product ID: {$product_id}, Name: {$product_name}, Is Subscription: " . ($is_subscription ? 'YES' : 'NO'));
            
            if ($is_subscription) {
                $has_subscription = true;
                $subscription_products[] = [
                    'product_id' => $product_id,
                    'name' => $product_name,
                    'quantity' => $item->get_quantity(),
                    'price' => $item->get_total()
                ];
            }
        }
        
        if (!$has_subscription) {
            error_log('SUBZZ DATA FLOW: No subscription products found - allowing normal checkout');
            return;
        }
        
        error_log('SUBZZ DATA FLOW: *** SUBSCRIPTION ORDER DETECTED - INITIATING AZURE WORKFLOW ***');
        error_log('SUBZZ DATA FLOW: Subscription products found: ' . wp_json_encode($subscription_products));
        
        try {
            // Prepare comprehensive order data for Azure (PRESERVED - WORKING)
            $order_data = $this->prepare_order_data_for_azure($order);
            error_log('SUBZZ DATA FLOW -> AZURE: Prepared order data structure: ' . wp_json_encode(array_keys($order_data)));
            error_log('SUBZZ DATA FLOW -> AZURE: Customer data: ' . wp_json_encode($order_data['customer_data']));
            error_log('SUBZZ DATA FLOW -> AZURE: Order totals: ' . wp_json_encode($order_data['order_totals']));
            error_log('SUBZZ DATA FLOW -> AZURE: Subscription items count: ' . count(array_filter($order_data['order_items'], function($item) { return $item['is_subscription']; })));
            
            // Store order in Azure with detailed response logging (PRESERVED - WORKING)
            $azure_client = new Subzz_Azure_API_Client();
            error_log('SUBZZ API CALL: Sending order data to Azure /api/order/store');
            
            $reference_id = $azure_client->store_order_data($order_data);
            
            if ($reference_id) {
                error_log('SUBZZ API RESPONSE: Azure order storage SUCCESS - Reference ID: ' . $reference_id);
                
                // Generate JWT token with reference ID (PRESERVED - WORKING)
                $jwt_token = $this->generate_jwt_token($reference_id, $order->get_billing_email());
                error_log('SUBZZ TOKEN GENERATION: JWT token created (length: ' . strlen($jwt_token) . ')');
                error_log('SUBZZ TOKEN GENERATION: Token payload - Ref ID: ' . $reference_id . ', Email: ' . $order->get_billing_email());
                
                // Store order tracking information (PRESERVED - ALL EXISTING DATA STRUCTURES)
                $order->add_order_note('SUBZZ: Order stored in Azure, Reference ID: ' . $reference_id);
                $order->add_meta_data('_subzz_reference_id', $reference_id);
                $order->add_meta_data('_subzz_azure_stored', 'yes');
                $order->add_meta_data('_subzz_contract_required', 'yes');
                
                // Create signature page URL (PRESERVED)
                $signature_url = home_url('/contract-signature/?token=' . $jwt_token);
                error_log('SUBZZ REDIRECT SETUP: Signature URL created: ' . $signature_url);
                
                // ENHANCED: Store redirect control data for server-side handling
                $order->add_meta_data('_subzz_signature_url', $signature_url);
                $order->add_meta_data('_subzz_needs_signature_redirect', 'yes');
                $order->add_meta_data('_subzz_redirect_processed', 'no');
                $order->add_meta_data('_subzz_redirect_timestamp', time());
                $order->add_meta_data('_subzz_jwt_token', $jwt_token);
                
                error_log('SUBZZ SERVER CONTROL: Redirect control metadata stored');
                error_log('SUBZZ SERVER CONTROL: Order marked for server-side signature redirect');
                
                $order->save();
                
                // Set order status to pending (awaiting signature) (PRESERVED)
                $order->set_status('pending', 'Awaiting contract signature - will redirect to signature page');
                
                error_log('SUBZZ WORKFLOW: Block Checkout interception complete - server will handle redirect');
                
                return; // Allow checkout to complete, server-side hooks will handle redirect
                
            } else {
                error_log('SUBZZ API ERROR: Azure order storage FAILED - No reference ID returned');
                throw new Exception('Unable to process subscription order. Please try again.');
            }
            
        } catch (Exception $e) {
            error_log('SUBZZ ERROR: Azure integration exception: ' . $e->getMessage());
            error_log('SUBZZ ERROR: Exception trace: ' . $e->getTraceAsString());
            throw new Exception('Subscription processing temporarily unavailable. Please try again.');
        }
    }
    
    /**
     * NEW: Handle subscription order redirect during checkout completion
     */
    public function handle_subscription_order_redirect($order_id, $posted_data, $order) {
        error_log("=== SUBZZ CHECKOUT COMPLETION: Processing Order {$order_id} ===");
        
        // Check if this order needs signature redirect
        $needs_signature = $order->get_meta('_subzz_needs_signature_redirect');
        $signature_url = $order->get_meta('_subzz_signature_url');
        
        error_log("SUBZZ REDIRECT CHECK: Order {$order_id} needs signature: {$needs_signature}");
        
        if ($needs_signature === 'yes' && !empty($signature_url)) {
            error_log("SUBZZ REDIRECT PREP: Order {$order_id} requires signature redirect");
            error_log("SUBZZ REDIRECT PREP: Signature URL: {$signature_url}");
            
            // Mark that we're going to handle the redirect
            $order->update_meta_data('_subzz_redirect_prepared', 'yes');
            $order->update_meta_data('_subzz_redirect_prepared_at', time());
            $order->save();
            
            error_log("SUBZZ REDIRECT PREP: Order {$order_id} marked for redirect preparation");
            
            // Store in WC session for redirect filter access (not PHP session — A14)
            if (WC()->session) {
                WC()->session->set('subzz_pending_redirect', array(
                    'order_id' => $order_id,
                    'signature_url' => $signature_url,
                    'timestamp' => time()
                ));
            }

            error_log("SUBZZ REDIRECT PREP: Redirect data stored in WC session for filter access");
        }
    }
    
    /**
     * NEW: Override WooCommerce's thank-you page redirect URL
     */
    public function override_thank_you_page_redirect($url, $order) {
        $order_id = $order->get_id();
        error_log("=== SUBZZ REDIRECT FILTER: Checking Order {$order_id} for redirect override ===");
        error_log("SUBZZ REDIRECT FILTER: Original URL: {$url}");
        
        // Check if this order needs signature redirect
        $needs_signature = $order->get_meta('_subzz_needs_signature_redirect');
        $redirect_processed = $order->get_meta('_subzz_redirect_processed');
        $signature_url = $order->get_meta('_subzz_signature_url');
        
        error_log("SUBZZ REDIRECT FILTER: Needs signature: {$needs_signature}, Processed: {$redirect_processed}");
        error_log("SUBZZ REDIRECT FILTER: Has signature URL: " . (empty($signature_url) ? 'NO' : 'YES'));
        
        if ($needs_signature === 'yes' && $redirect_processed !== 'yes' && !empty($signature_url)) {
            error_log("SUBZZ REDIRECT OVERRIDE: *** REDIRECTING ORDER {$order_id} TO SIGNATURE PAGE ***");
            error_log("SUBZZ REDIRECT OVERRIDE: Signature URL: {$signature_url}");
            
            // Mark as processed to prevent duplicate redirects
            $order->update_meta_data('_subzz_redirect_processed', 'yes');
            $order->update_meta_data('_subzz_redirect_processed_at', time());
            $order->update_meta_data('_subzz_redirect_method', 'server_side_filter');
            $order->save();
            
            error_log("SUBZZ REDIRECT OVERRIDE: Order {$order_id} marked as redirect processed");
            
            return $signature_url;
        }
        
        error_log("SUBZZ REDIRECT FILTER: No override needed for Order {$order_id}, using original URL");
        return $url;
    }
    
    /**
     * NEW: Catch and redirect on order-received page (backup method)
     */
    public function catch_and_redirect_order_received() {
        if (!is_order_received_page()) {
            return;
        }
        
        error_log('=== SUBZZ TEMPLATE REDIRECT: Order received page detected ===');
        
        // Try to get order from URL parameters
        $order = null;
        
        // Method 1: Check for order parameter
        if (isset($_GET['order-received']) && !empty($_GET['order-received'])) {
            $order_id = intval($_GET['order-received']);
            $order = wc_get_order($order_id);
            error_log("SUBZZ TEMPLATE REDIRECT: Found order via order-received parameter: {$order_id}");
        }
        
        // Method 2: Check for key parameter and find order
        if (!$order && isset($_GET['key']) && !empty($_GET['key'])) {
            $order_key = sanitize_text_field($_GET['key']);
            $order_id = wc_get_order_id_by_order_key($order_key);
            if ($order_id) {
                $order = wc_get_order($order_id);
                error_log("SUBZZ TEMPLATE REDIRECT: Found order via order key: {$order_id}");
            }
        }
        
        // Method 3: Check WC session for pending redirect
        if (!$order && WC()->session) {
            $redirect_data = WC()->session->get('subzz_pending_redirect');
            if ($redirect_data) {
                $order_id = $redirect_data['order_id'];
                $order = wc_get_order($order_id);
                error_log("SUBZZ TEMPLATE REDIRECT: Found order via WC session data: {$order_id}");
            }
        }
        
        if (!$order) {
            error_log('SUBZZ TEMPLATE REDIRECT: No valid order found on order-received page');
            return;
        }
        
        $order_id = $order->get_id();
        error_log("SUBZZ TEMPLATE REDIRECT: Processing Order {$order_id} on order-received page");
        
        // Check if this order needs signature redirect
        $needs_signature = $order->get_meta('_subzz_needs_signature_redirect');
        $redirect_processed = $order->get_meta('_subzz_redirect_processed');
        $signature_url = $order->get_meta('_subzz_signature_url');
        
        error_log("SUBZZ TEMPLATE REDIRECT: Order {$order_id} - Needs: {$needs_signature}, Processed: {$redirect_processed}");
        
        if ($needs_signature === 'yes' && $redirect_processed !== 'yes' && !empty($signature_url)) {
            error_log("SUBZZ TEMPLATE REDIRECT: *** EXECUTING BACKUP REDIRECT FOR ORDER {$order_id} ***");
            error_log("SUBZZ TEMPLATE REDIRECT: Redirecting to: {$signature_url}");
            
            // Mark as processed
            $order->update_meta_data('_subzz_redirect_processed', 'yes');
            $order->update_meta_data('_subzz_redirect_processed_at', time());
            $order->update_meta_data('_subzz_redirect_method', 'template_redirect_backup');
            $order->save();
            
            // Clear WC session data
            if (WC()->session) {
                WC()->session->set('subzz_pending_redirect', null);
                error_log("SUBZZ TEMPLATE REDIRECT: Cleared WC session redirect data");
            }
            
            // Execute redirect
            wp_redirect($signature_url);
            exit;
        }
        
        error_log("SUBZZ TEMPLATE REDIRECT: No redirect needed for Order {$order_id}");
    }
    
    /**
     * Prepare comprehensive order data for Azure storage - PRESERVED UNCHANGED
     */
    private function prepare_order_data_for_azure($order) {
        error_log('SUBZZ DATA PREPARATION: Starting order data preparation for Azure');
        
        // Extract customer data
        $customer_data = array(
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
            'company' => $order->get_billing_company(),
        );
        error_log('SUBZZ DATA PREPARATION: Customer data extracted: ' . wp_json_encode($customer_data));
        
        // Extract billing address
        $billing_address = array(
            'address_1' => $order->get_billing_address_1(),
            'address_2' => $order->get_billing_address_2(),
            'city' => $order->get_billing_city(),
            'state' => $order->get_billing_state(),
            'postcode' => $order->get_billing_postcode(),
            'country' => $order->get_billing_country(),
        );
        error_log('SUBZZ DATA PREPARATION: Billing address extracted: ' . wp_json_encode($billing_address));
        
        // Extract and categorize order items
        $order_items = array();
        $subscription_count = 0;
        $regular_count = 0;
        
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $is_subscription = $this->is_subscription_product($item->get_product_id());
            
            $item_data = array(
                'product_id' => $item->get_product_id(),
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'price' => $item->get_total(),
                'is_subscription' => $is_subscription
            );
            
            $order_items[] = $item_data;
            
            if ($is_subscription) {
                $subscription_count++;
                error_log("SUBZZ DATA PREPARATION: Subscription item - ID: {$item->get_product_id()}, Name: {$item->get_name()}, Price: {$item->get_total()}");
            } else {
                $regular_count++;
            }
        }
        
        error_log("SUBZZ DATA PREPARATION: Items categorized - Subscriptions: {$subscription_count}, Regular: {$regular_count}");
        
        // Extract order totals
        $order_totals = array(
            'subtotal' => $order->get_subtotal(),
            'total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'tax_total' => $order->get_total_tax(),
            'shipping_total' => $order->get_shipping_total()
        );
        error_log('SUBZZ DATA PREPARATION: Order totals extracted: ' . wp_json_encode($order_totals));
        
        // Compile complete order data structure (PRESERVED UNCHANGED)
        $order_data = array(
            'woocommerce_order_id' => $order->get_id(),
            'customer_email' => $order->get_billing_email(),
            'customer_data' => $customer_data,
            'billing_address' => $billing_address,
            'order_items' => $order_items,
            'order_totals' => $order_totals,
            'created_at' => current_time('mysql'),
            'wordpress_site' => home_url(),
            'order_status' => $order->get_status(),
            'payment_method_title' => $order->get_payment_method_title()
        );
        
        error_log('SUBZZ DATA PREPARATION: Complete order data structure prepared');
        error_log('SUBZZ DATA PREPARATION: Final data size: ' . strlen(wp_json_encode($order_data)) . ' bytes');
        error_log('SUBZZ DATA PREPARATION: Main sections: ' . implode(', ', array_keys($order_data)));
        
        return $order_data;
    }
    
    /**
     * Generate HMAC-SHA256 signed JWT token (CHK-001 fix).
     * Uses firebase/php-jwt library vendored in includes/vendor/.
     * Secret key from SUBZZ_CHECKOUT_JWT_SECRET in wp-config.php,
     * falls back to WordPress auth salt if not defined.
     */
    public function generate_jwt_token($reference_id, $customer_email) {
        error_log('SUBZZ JWT GENERATION: Creating signed token for Reference ID: ' . $reference_id);

        // Load vendored JWT library
        require_once dirname(__FILE__) . '/vendor/firebase/php-jwt/src/JWT.php';

        $secret_key = defined('SUBZZ_CHECKOUT_JWT_SECRET') ? SUBZZ_CHECKOUT_JWT_SECRET : wp_salt('auth');

        if (!defined('SUBZZ_CHECKOUT_JWT_SECRET')) {
            error_log('SUBZZ JWT WARNING: SUBZZ_CHECKOUT_JWT_SECRET not defined, falling back to wp_salt');
        }

        $issued_at = time();
        $expires_at = $issued_at + (30 * 60); // 30 minutes

        $payload = array(
            'iss' => home_url(),
            'iat' => $issued_at,
            'exp' => $expires_at,
            'reference_id' => $reference_id,
            'customer_email' => $customer_email,
            'purpose' => 'contract_signature'
        );

        error_log('SUBZZ JWT GENERATION: Payload created - Ref ID: ' . $reference_id . ', Email: ' . $customer_email . ', Expires: ' . date('Y-m-d H:i:s', $expires_at));

        $token = \Firebase\JWT\JWT::encode($payload, $secret_key, 'HS256');

        error_log('SUBZZ JWT GENERATION: Signed token created - Length: ' . strlen($token) . ' characters');

        return $token;
    }

    /**
     * Handle abandoned cart resume link from recovery email.
     * Decodes JWT resume token, validates purpose, checks order status,
     * and routes customer to the appropriate checkout step.
     *
     * @param string $resume_token JWT token from ?resume= query parameter
     */
    public function handle_checkout_resume($resume_token) {
        error_log('=== SUBZZ CART RESUME: Processing resume token ===');

        // Load vendored JWT library
        require_once dirname(__FILE__) . '/vendor/firebase/php-jwt/src/JWT.php';
        require_once dirname(__FILE__) . '/vendor/firebase/php-jwt/src/Key.php';

        $secret_key = defined('SUBZZ_CHECKOUT_JWT_SECRET') ? SUBZZ_CHECKOUT_JWT_SECRET : wp_salt('auth');

        if (!defined('SUBZZ_CHECKOUT_JWT_SECRET')) {
            error_log('SUBZZ CART RESUME WARNING: SUBZZ_CHECKOUT_JWT_SECRET not defined, falling back to wp_salt');
        }

        try {
            // Decode and validate the resume token
            $decoded = \Firebase\JWT\JWT::decode(
                $resume_token,
                new \Firebase\JWT\Key($secret_key, 'HS256')
            );

            // Validate purpose claim
            if (!isset($decoded->purpose) || $decoded->purpose !== 'cart_recovery') {
                error_log('SUBZZ CART RESUME ERROR: Invalid token purpose: ' . ($decoded->purpose ?? 'none'));
                wp_die(
                    '<h2>Invalid Resume Link</h2><p>This link is not a valid cart recovery link. Please check your email for the correct link.</p>',
                    'Invalid Link',
                    array('response' => 400)
                );
                return;
            }

            $reference_id = $decoded->reference_id ?? null;
            $customer_email = $decoded->customer_email ?? null;

            if (empty($reference_id) || empty($customer_email)) {
                error_log('SUBZZ CART RESUME ERROR: Missing reference_id or customer_email in token');
                wp_die(
                    '<h2>Invalid Resume Link</h2><p>This resume link is missing required information. Please contact support.</p>',
                    'Invalid Link',
                    array('response' => 400)
                );
                return;
            }

            error_log('SUBZZ CART RESUME: Token valid - Ref: ' . $reference_id . ', Email: ' . $customer_email);

            // Retrieve order data from Azure API
            require_once dirname(__FILE__) . '/class-azure-api-client.php';
            $azure_client = new Subzz_Azure_API_Client();
            $order_data = $azure_client->retrieve_order_data($reference_id);

            if (!$order_data || isset($order_data['error'])) {
                error_log('SUBZZ CART RESUME ERROR: Order not found or expired for reference: ' . $reference_id);
                wp_die(
                    '<h2>Order Not Found</h2><p>Your checkout session may have expired. Please visit our <a href="' . esc_url(home_url('/shop/')) . '">shop</a> to start a new subscription.</p>',
                    'Order Expired',
                    array('response' => 404)
                );
                return;
            }

            $order_status = $order_data['order_status'] ?? $order_data['OrderStatus'] ?? 'unknown';
            error_log('SUBZZ CART RESUME: Order status: ' . $order_status . ' for reference: ' . $reference_id);

            // Route based on order status
            switch ($order_status) {
                case 'created':
                case 'signature_pending':
                    // Customer needs to sign contract — generate contract JWT and redirect
                    $contract_token = $this->generate_jwt_token($reference_id, $customer_email);
                    $redirect_url = home_url('/contract-signature/?token=' . $contract_token);
                    error_log('SUBZZ CART RESUME: Redirecting to contract signature: ' . $redirect_url);
                    wp_redirect($redirect_url);
                    exit;

                case 'signature_completed':
                case 'payment_pending':
                case 'payment_failed':
                    // Customer needs to complete payment
                    $redirect_url = home_url('/subscription-payment/?reference_id=' . urlencode($reference_id));
                    error_log('SUBZZ CART RESUME: Redirecting to payment: ' . $redirect_url);
                    wp_redirect($redirect_url);
                    exit;

                case 'payment_completed':
                    // Order already completed
                    $redirect_url = home_url('/payment-success/');
                    error_log('SUBZZ CART RESUME: Order already completed, redirecting to success');
                    wp_redirect($redirect_url);
                    exit;

                default:
                    error_log('SUBZZ CART RESUME ERROR: Unknown order status: ' . $order_status);
                    wp_die(
                        '<h2>Unable to Resume</h2><p>We couldn\'t determine the status of your order. Please contact <a href="mailto:support@subzz.co.za">support@subzz.co.za</a> for assistance.</p>',
                        'Resume Error',
                        array('response' => 400)
                    );
                    return;
            }

        } catch (\Firebase\JWT\ExpiredException $e) {
            error_log('SUBZZ CART RESUME ERROR: Token expired - ' . $e->getMessage());
            wp_die(
                '<h2>Link Expired</h2><p>This resume link has expired. Please visit our <a href="' . esc_url(home_url('/shop/')) . '">shop</a> to start a new subscription.</p>',
                'Link Expired',
                array('response' => 410)
            );
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            error_log('SUBZZ CART RESUME ERROR: Invalid signature - ' . $e->getMessage());
            wp_die(
                '<h2>Invalid Link</h2><p>This resume link is invalid. Please check your email for the correct link.</p>',
                'Invalid Link',
                array('response' => 400)
            );
        } catch (\Exception $e) {
            error_log('SUBZZ CART RESUME ERROR: Token decode failed - ' . $e->getMessage());
            wp_die(
                '<h2>Error</h2><p>Something went wrong processing your resume link. Please contact <a href="mailto:support@subzz.co.za">support@subzz.co.za</a>.</p>',
                'Error',
                array('response' => 500)
            );
        }
    }

    /**
     * AJAX handler for subscription cart checking - PRESERVED FOR TESTING
     */
    public function ajax_check_subscription_cart() {
        if (!WC()->cart) {
            wp_send_json_error('Cart not available');
            return;
        }
        
        $has_subscription = false;
        $cart_contents = WC()->cart->get_cart();
        $subscription_products = [];
        
        foreach ($cart_contents as $cart_item) {
            $product_id = $cart_item['product_id'];
            if ($this->is_subscription_product($product_id)) {
                $has_subscription = true;
                $subscription_products[] = $product_id;
            }
        }
        
        error_log('SUBZZ AJAX: Subscription cart check - Has subscription: ' . ($has_subscription ? 'YES' : 'NO'));
        if ($has_subscription) {
            error_log('SUBZZ AJAX: Subscription products: ' . implode(', ', $subscription_products));
        }
        
        wp_send_json_success([
            'has_subscription' => $has_subscription,
            'subscription_products' => $subscription_products,
            'cart_count' => count($cart_contents)
        ]);
    }
    
    /**
     * PRESERVED: Legacy AJAX handler for redirect requirement checking (for backwards compatibility)
     */
    public function ajax_check_redirect_requirement() {
        if (!session_id()) {
            session_start();
        }
        
        error_log('SUBZZ LEGACY CHECK: Checking for session-based redirect data (compatibility mode)');
        
        if (isset($_SESSION['subzz_redirect_url']) && isset($_SESSION['subzz_order_id'])) {
            $redirect_url = $_SESSION['subzz_redirect_url'];
            $order_id = $_SESSION['subzz_order_id'];
            $reference_id = isset($_SESSION['subzz_reference_id']) ? $_SESSION['subzz_reference_id'] : 'unknown';
            
            error_log("SUBZZ LEGACY SUCCESS: Redirect found - Order: {$order_id}, Ref ID: {$reference_id}");
            
            // Clear session data to prevent repeated redirects
            unset($_SESSION['subzz_redirect_url']);
            unset($_SESSION['subzz_order_id']);
            unset($_SESSION['subzz_reference_id']);
            
            wp_send_json_success([
                'redirect_required' => true,
                'redirect_url' => $redirect_url,
                'order_id' => $order_id,
                'reference_id' => $reference_id,
                'method' => 'legacy_session'
            ]);
            
        } else {
            error_log('SUBZZ LEGACY CHECK: No legacy redirect data found');
            wp_send_json_success(['redirect_required' => false]);
        }
    }
    
    /**
     * PRESERVED: Order meta-based redirect checking (for backwards compatibility and debugging)
     */
    public function ajax_check_order_redirect_requirement() {
        $order_id = intval($_POST['order_id'] ?? 0);
        
        error_log("SUBZZ ORDER CHECK: Received request for Order ID: {$order_id}");
        
        if (!$order_id) {
            error_log('SUBZZ ORDER ERROR: No order ID provided');
            wp_send_json_error('No order ID provided');
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log("SUBZZ ORDER ERROR: Order {$order_id} not found");
            wp_send_json_error('Order not found');
            return;
        }
        
        error_log("SUBZZ ORDER CHECK: Order {$order_id} found, checking meta data");
        
        $needs_signature = $order->get_meta('_subzz_needs_signature_redirect');
        $redirect_processed = $order->get_meta('_subzz_redirect_processed');
        $signature_url = $order->get_meta('_subzz_signature_url');
        $reference_id = $order->get_meta('_subzz_reference_id');
        
        error_log("SUBZZ ORDER META: Needs: {$needs_signature}, Processed: {$redirect_processed}");
        error_log("SUBZZ ORDER META: URL exists: " . (empty($signature_url) ? 'NO' : 'YES'));
        
        if ($needs_signature === 'yes' && $redirect_processed !== 'yes' && !empty($signature_url)) {
            error_log("SUBZZ ORDER SUCCESS: Redirect requirement found for Order {$order_id}");
            
            wp_send_json_success([
                'redirect_required' => true,
                'redirect_url' => $signature_url,
                'order_id' => $order_id,
                'reference_id' => $reference_id,
                'method' => 'ajax_order_meta'
            ]);
            
        } else {
            error_log("SUBZZ ORDER CHECK: No redirect required for Order {$order_id}");
            wp_send_json_success(['redirect_required' => false]);
        }
    }
    
    /**
     * PRESERVED: Traditional checkout fallback (safety net)
     */
    public function traditional_checkout_fallback() {
        if (!WC()->cart) {
            return;
        }
        
        $cart_contents = WC()->cart->get_cart();
        foreach ($cart_contents as $cart_item) {
            if ($this->is_subscription_product($cart_item['product_id'])) {
                error_log('SUBZZ FALLBACK: Subscription detected in traditional checkout - may indicate Block Checkout bypass');
                wc_add_notice('Subscription product detected. Please complete the contract signature process.', 'error');
                break;
            }
        }
    }
    
    /**
     * PRESERVED: Optional JavaScript for debugging and edge case handling
     */
    public function enqueue_debugging_scripts() {
        if (!is_checkout() && !is_order_received_page()) {
            return;
        }
        
        error_log('SUBZZ DEBUG JS: Loading debugging JavaScript for checkout/order pages');
        
        $ajax_url = admin_url('admin-ajax.php');
        
        $script = "
        jQuery(document).ready(function($) {
            console.log('SUBZZ DEBUG: Debugging scripts loaded for testing/validation');
            
            // Manual testing functions
            window.subzzTestRedirectCheck = function() {
                console.log('SUBZZ TEST: Manual redirect requirement check');
                
                $.ajax({
                    url: '" . $ajax_url . "',
                    method: 'POST',
                    data: { action: 'subzz_check_redirect_requirement' },
                    success: function(response) {
                        console.log('SUBZZ TEST: Manual check result:', response);
                    }
                });
            };
            
            window.subzzTestOrderCheck = function(orderId) {
                console.log('SUBZZ TEST: Manual order redirect check for:', orderId);
                
                $.ajax({
                    url: '" . $ajax_url . "',
                    method: 'POST',
                    data: { 
                        action: 'subzz_check_order_redirect_requirement',
                        order_id: orderId 
                    },
                    success: function(response) {
                        console.log('SUBZZ TEST: Manual order check result:', response);
                    }
                });
            };
            
            // Diagnostic information
            console.log('SUBZZ DEBUG: Available test functions:');
            console.log('  - subzzTestRedirectCheck() - Test session redirect check');
            console.log('  - subzzTestOrderCheck(orderId) - Test order meta redirect check');
            console.log('SUBZZ DEBUG: Current page type:', {
                is_checkout: " . (is_checkout() ? 'true' : 'false') . ",
                is_order_received: " . (is_order_received_page() ? 'true' : 'false') . ",
                url: window.location.href
            });
        });
        ";
        
        wp_add_inline_script('jquery', $script);
        error_log('SUBZZ DEBUG JS: Debugging scripts loaded successfully');
    }
    
    /**
     * Check if a product is marked as a subscription product - PRESERVED UNCHANGED
     */
    private function is_subscription_product($product_id) {
        $subscription_enabled = get_post_meta($product_id, '_subzz_subscription_enabled', true);
        $is_subscription = ($subscription_enabled === 'yes');
        
        if ($is_subscription) {
            error_log("SUBZZ SUBSCRIPTION CHECK: Product {$product_id} IS a subscription");
        }
        
        return $is_subscription;
    }
    
    /**
     * Helper function to check if current page is order received page
     */
    private function is_order_received_page() {
        return is_wc_endpoint_url('order-received');
    }
}
?>