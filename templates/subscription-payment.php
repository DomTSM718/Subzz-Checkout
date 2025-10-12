<?php
/**
 * Subscription Payment Landing Page Template
 * 
 * This page is displayed after successful contract signature
 * It creates a payment session and redirects to the payment gateway
 * 
 * PHASE 5A INTEGRATION: Updated to redirect to mock gateway for testing
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

get_header();

// Initialize variables
$error_message = '';
$payment_session = null;
$order_data = null;
$signature_verified = false;

// Check for required parameters
if (!isset($_GET['reference_id']) || !isset($_GET['signature_confirmed'])) {
    $error_message = 'Missing required parameters. Please complete the contract signature first.';
    error_log('SUBZZ PAYMENT PAGE ERROR: Missing reference_id or signature_confirmed parameter');
}

// Verify signature was completed
$reference_id = sanitize_text_field($_GET['reference_id'] ?? '');
$signature_confirmed = sanitize_text_field($_GET['signature_confirmed'] ?? '');

if ($signature_confirmed !== 'yes') {
    $error_message = 'Contract signature not confirmed. Please complete the signature process first.';
    error_log('SUBZZ PAYMENT PAGE ERROR: Signature not confirmed for reference: ' . $reference_id);
}

// Initialize Azure client
$azure_client = null;
if (empty($error_message)) {
    if (class_exists('Subzz_Azure_API_Client')) {
        $azure_client = new Subzz_Azure_API_Client();
        error_log('SUBZZ PAYMENT PAGE: Azure client initialized for reference: ' . $reference_id);
    } else {
        $error_message = 'Payment system temporarily unavailable. Please try again later.';
        error_log('SUBZZ PAYMENT PAGE ERROR: Azure API Client class not found');
    }
}

// Retrieve order data from Azure
if (empty($error_message) && $azure_client) {
    error_log('SUBZZ PAYMENT PAGE: Retrieving order data for reference: ' . $reference_id);
    $order_data = $azure_client->retrieve_order_data($reference_id);
    
    if (!$order_data) {
        $error_message = 'Unable to retrieve order information. Your session may have expired.';
        error_log('SUBZZ PAYMENT PAGE ERROR: Failed to retrieve order data from Azure');
    } else {
        error_log('SUBZZ PAYMENT PAGE: Order data retrieved successfully');
        
        // ENHANCED: Extract comprehensive customer data from nested structure
        $customer_data = extract_customer_data_from_order($order_data);
        
        error_log('SUBZZ PAYMENT PAGE: Customer email: ' . $customer_data['email']);
        error_log('SUBZZ PAYMENT PAGE: Customer name: ' . $customer_data['full_name']);
        error_log('SUBZZ PAYMENT PAGE: Order ID: ' . ($order_data['woocommerce_order_id'] ?? 'unknown'));
        error_log('SUBZZ PAYMENT PAGE: Order Status: ' . ($order_data['order_status'] ?? 'unknown'));
        
        // Check if order status allows payment processing
        if (isset($order_data['order_status'])) {
            $allowed_statuses = ['signature_completed', 'payment_pending', 'payment_failed'];
            if (!in_array($order_data['order_status'], $allowed_statuses)) {
                $error_message = 'Invalid order status for payment. Current status: ' . $order_data['order_status'];
                error_log('SUBZZ PAYMENT PAGE ERROR: Invalid order status: ' . $order_data['order_status']);
            }
        }
    }
}

// UPDATE ORDER STATUS TO PAYMENT_PENDING
if (empty($error_message) && $azure_client && $order_data) {
    // Only update if status is signature_completed (not if already payment_pending or payment_failed)
    if (isset($order_data['order_status']) && $order_data['order_status'] === 'signature_completed') {
        error_log('SUBZZ PAYMENT PAGE: Updating order status from signature_completed to payment_pending');
        
        $status_updated = $azure_client->update_order_status($reference_id, 'payment_pending');
        
        if ($status_updated) {
            error_log('SUBZZ PAYMENT PAGE SUCCESS: Order status updated to payment_pending');
            // Update local order_data to reflect new status
            $order_data['order_status'] = 'payment_pending';
        } else {
            error_log('SUBZZ PAYMENT PAGE WARNING: Failed to update order status to payment_pending');
            // Continue anyway - don't block payment process
        }
    } else {
        error_log('SUBZZ PAYMENT PAGE: Order status is ' . ($order_data['order_status'] ?? 'unknown') . ', no status update needed');
    }
}

// Verify signature exists in Azure
if (empty($error_message) && $azure_client && $order_data) {
    error_log('SUBZZ PAYMENT PAGE: Verifying signature exists for customer: ' . $customer_data['email']);
    
    // Since we have order_status = signature_completed or payment_pending, signature must exist
    // This verification is redundant but kept for future enhancement
    $signature_verified = true;
    
    if (!$signature_verified) {
        $error_message = 'Contract signature not found. Please complete the signature process first.';
        error_log('SUBZZ PAYMENT PAGE ERROR: Signature verification failed');
    } else {
        error_log('SUBZZ PAYMENT PAGE: Signature verified successfully');
    }
}

// PHASE 5A INTEGRATION: Create mock payment session that redirects to our mock gateway
$payment_session_data = null;
if (empty($error_message) && $signature_verified && $order_data) {
    error_log('SUBZZ PAYMENT PAGE: Creating mock payment session for testing');
    
    // Extract order total
    $total_amount = $order_data['order_totals']['total'] ?? '0';
    $currency = $order_data['order_totals']['currency'] ?? 'ZAR';
    
    // ENHANCED: Use comprehensive customer data
    error_log('SUBZZ PAYMENT PAGE: Using customer email for payment session: ' . $customer_data['email']);
    
    // PHASE 5A INTEGRATION: Create mock payment session that redirects to our mock gateway
    // Instead of calling Azure API, create mock session data locally
    $mock_session_id = 'mock_session_' . substr(md5($reference_id . time()), 0, 16);
    
    error_log('SUBZZ MOCK INTEGRATION: Creating mock payment session locally');
    error_log('SUBZZ MOCK INTEGRATION: Session ID: ' . $mock_session_id);
    error_log('SUBZZ MOCK INTEGRATION: Reference ID: ' . $reference_id);
    error_log('SUBZZ MOCK INTEGRATION: Amount: ' . $currency . ' ' . $total_amount);
    
    // FIXED: Build URL properly using http_build_query to avoid encoding issues
    $mock_checkout_url = home_url('/mock-gateway/') . '?' . http_build_query(array(
        'session' => $mock_session_id,              // FIXED: 'session' (not 'session_id')
        'amount' => $total_amount,                  // CORRECT: 'amount'
        'customer' => $customer_data['full_name'],  // FIXED: 'customer' (not 'customer_name')
        'reference_id' => $reference_id,            // CORRECT: 'reference_id'
        'currency' => $currency                     // CORRECT: 'currency'
    ));
    
    $payment_session_data = array(
        'success' => true,
        'sessionId' => $mock_session_id,
        'checkoutUrl' => $mock_checkout_url,  // Store the raw URL
        'amount' => $total_amount,
        'currency' => $currency,
        'expiresAt' => date('Y-m-d H:i:s', strtotime('+1 hour'))
    );
    
    error_log('SUBZZ MOCK INTEGRATION: Mock session created successfully');
    error_log('SUBZZ MOCK INTEGRATION: Updated Checkout URL: ' . $payment_session_data['checkoutUrl']);
    
    // Store session info in WooCommerce order meta (same as before)
    if (isset($order_data['woocommerce_order_id'])) {
        $wc_order = wc_get_order($order_data['woocommerce_order_id']);
        if ($wc_order) {
            $wc_order->update_meta_data('_subzz_payment_session_id', $payment_session_data['sessionId']);
            $wc_order->update_meta_data('_subzz_payment_status', 'pending');
            $wc_order->update_meta_data('_subzz_order_status', 'payment_pending');
            $wc_order->save();
            error_log('SUBZZ MOCK INTEGRATION: Updated WooCommerce order with mock payment session');
        }
    }
}

// ENHANCED: Helper function to extract customer data consistently
function extract_customer_data_from_order($order_data) {
    $customer_data = array(
        'email' => '',
        'first_name' => '',
        'last_name' => '',
        'full_name' => '',
        'phone_number' => '',
        'id_number' => '',
        'billing_address' => '',
        'city' => '',
        'province' => '',
        'postal_code' => ''
    );
    
    // Extract email (multiple fallback methods)
    if (isset($order_data['customer_data']['email']) && !empty($order_data['customer_data']['email'])) {
        $customer_data['email'] = $order_data['customer_data']['email'];
    } elseif (isset($order_data['customer_email']) && !empty($order_data['customer_email'])) {
        $customer_data['email'] = $order_data['customer_email'];
    }
    
    // Extract name information
    if (isset($order_data['customer_data']['first_name'])) {
        $customer_data['first_name'] = $order_data['customer_data']['first_name'];
    }
    if (isset($order_data['customer_data']['last_name'])) {
        $customer_data['last_name'] = $order_data['customer_data']['last_name'];
    }
    $customer_data['full_name'] = trim($customer_data['first_name'] . ' ' . $customer_data['last_name']);
    
    // Extract contact information
    if (isset($order_data['customer_data']['phone_number'])) {
        $customer_data['phone_number'] = $order_data['customer_data']['phone_number'];
    }
    if (isset($order_data['customer_data']['id_number'])) {
        $customer_data['id_number'] = $order_data['customer_data']['id_number'];
    }
    
    // Extract address information
    if (isset($order_data['customer_data']['billing_address'])) {
        $customer_data['billing_address'] = $order_data['customer_data']['billing_address'];
    }
    if (isset($order_data['customer_data']['city'])) {
        $customer_data['city'] = $order_data['customer_data']['city'];
    }
    if (isset($order_data['customer_data']['province'])) {
        $customer_data['province'] = $order_data['customer_data']['province'];
    }
    if (isset($order_data['customer_data']['postal_code'])) {
        $customer_data['postal_code'] = $order_data['customer_data']['postal_code'];
    }
    
    return $customer_data;
}

?>

<style>
/* Reuse styles from contract signature page */
.subzz-payment-page {
    max-width: 800px;
    margin: 40px auto;
    padding: 0 20px;
}

.payment-header {
    text-align: center;
    margin-bottom: 40px;
}

.progress-indicator {
    margin-top: 20px;
    color: #666;
    font-size: 14px;
}

.payment-content {
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    padding: 30px;
}

.order-summary {
    margin-bottom: 30px;
}

.order-summary h2 {
    color: #333;
    margin-bottom: 20px;
    font-size: 24px;
}

.order-details {
    background: #f9f9f9;
    border-radius: 6px;
    padding: 20px;
    margin-bottom: 20px;
}

.order-item {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid #e0e0e0;
}

.order-item:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.order-total {
    font-size: 20px;
    font-weight: bold;
    color: #333;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 2px solid #333;
}

.payment-action {
    text-align: center;
    margin-top: 30px;
}

.button {
    display: inline-block;
    padding: 12px 30px;
    text-decoration: none;
    border-radius: 5px;
    font-weight: 500;
    transition: all 0.3s;
    border: none;
    cursor: pointer;
    font-size: 16px;
}

.button.primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.button.primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
}

.button.secondary {
    background: #f0f0f0;
    color: #333;
    margin-right: 15px;
}

.loading-spinner {
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 3px solid rgba(255,255,255,.3);
    border-radius: 50%;
    border-top-color: white;
    animation: spin 1s ease-in-out infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.error-message {
    background: #fee;
    border: 1px solid #fcc;
    border-radius: 6px;
    padding: 20px;
    margin-bottom: 20px;
    color: #c00;
}

.success-message {
    background: #efe;
    border: 1px solid #cfc;
    border-radius: 6px;
    padding: 20px;
    margin-bottom: 20px;
    color: #060;
}

.security-info {
    background: #f0f8ff;
    border: 1px solid #b0d4ff;
    border-radius: 6px;
    padding: 15px;
    margin-top: 20px;
    font-size: 14px;
    color: #666;
}

.security-info strong {
    color: #333;
}

.status-info {
    background: #f5f5f5;
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 10px;
    margin-bottom: 20px;
    font-size: 12px;
    color: #666;
}

.retry-message {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 6px;
    padding: 15px;
    margin-bottom: 20px;
    color: #856404;
}
</style>

<div class="subzz-payment-page">
    <div class="payment-header">
        <h1>Complete Your Payment</h1>
        <div class="progress-indicator">
            ✅ Order Details → ✅ Agreement Signed → ✅ Payment → ⭕ Complete
        </div>
    </div>

    <div class="payment-content">
        <?php if (isset($_GET['retry']) && $_GET['retry'] === 'yes'): ?>
            <div class="retry-message">
                <strong>Payment was cancelled or failed.</strong> You can try again below, or contact support if you continue to experience issues.
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
            <div class="error-message">
                <strong>Error:</strong> <?php echo esc_html($error_message); ?>
            </div>
            <div class="payment-action">
                <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="button secondary">
                    ← Return to Checkout
                </a>
                <?php if (!empty($reference_id)): ?>
                <a href="<?php echo esc_url(home_url('/contract-signature/?token=' . base64_encode(json_encode(['reference_id' => $reference_id, 'customer_email' => $customer_data['email'] ?? '', 'issued_at' => time(), 'expires_at' => time() + 1800])))); ?>" class="button primary">
                    Re-sign Contract
                </a>
                <?php endif; ?>
            </div>
        <?php elseif ($payment_session_data && isset($payment_session_data['success']) && $payment_session_data['success']): ?>
            <!-- Payment session created successfully -->
            <div class="success-message">
                <strong>✅ Your contract has been signed successfully!</strong>
            </div>
            
            <?php if (isset($order_data['order_status'])): ?>
            <div class="status-info">
                Order Status: <?php echo esc_html($order_data['order_status']); ?> | 
                Reference: <?php echo esc_html($reference_id); ?>
                <?php if (isset($payment_session_data['sessionId'])): ?>
                | Session: <?php echo esc_html($payment_session_data['sessionId']); ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <div class="order-summary">
                <h2>Order Summary</h2>
                
                <div class="order-details">
                    <div class="customer-info">
                        <div class="order-item">
                            <span>Customer:</span>
                            <strong><?php echo esc_html($customer_data['full_name']); ?></strong>
                        </div>
                        <div class="order-item">
                            <span>Email:</span>
                            <strong><?php echo esc_html($customer_data['email']); ?></strong>
                        </div>
                        <?php if (!empty($customer_data['phone_number'])): ?>
                        <div class="order-item">
                            <span>Phone:</span>
                            <strong><?php echo esc_html($customer_data['phone_number']); ?></strong>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php 
                    // Display subscription items
                    $subscription_items = array();
                    if (isset($order_data['order_items']) && is_array($order_data['order_items'])) {
                        $subscription_items = array_filter($order_data['order_items'], function($item) { 
                            return isset($item['is_subscription']) && $item['is_subscription']; 
                        });
                    }
                    
                    foreach ($subscription_items as $item): 
                    ?>
                        <div class="order-item">
                            <span><?php echo esc_html($item['name'] ?? 'Subscription Product'); ?></span>
                            <strong><?php echo esc_html($order_data['order_totals']['currency'] ?? 'ZAR'); ?> <?php echo number_format((float)($item['price'] ?? 0), 2); ?></strong>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="order-total order-item">
                        <span>Total Monthly Payment:</span>
                        <strong><?php echo esc_html($order_data['order_totals']['currency'] ?? 'ZAR'); ?> <?php echo number_format((float)($order_data['order_totals']['total'] ?? 0), 2); ?></strong>
                    </div>
                </div>
            </div>
            
            <div class="security-info">
                🔒 <strong>Testing Payment Gateway:</strong> You will be redirected to our testing payment gateway where you can simulate payment success or failure for development purposes.
            </div>
            
            <div class="payment-action" id="payment-redirect">
                <p>Redirecting to testing payment gateway...</p>
                <div class="loading-spinner"></div>
                
                <?php 
                // FIXED: Store the URL in a JavaScript variable to avoid encoding issues
                $checkout_url_raw = $payment_session_data['checkoutUrl'] ?? '';
                ?>
                <script>
                    // Auto-redirect after 2 seconds
                    (function() {
                        // Store the raw URL in a JavaScript variable
                        var checkoutUrl = <?php echo json_encode($checkout_url_raw); ?>;
                        
                        console.log('SUBZZ PAYMENT: Redirecting to mock payment gateway');
                        console.log('SUBZZ PAYMENT: Checkout URL:', checkoutUrl);
                        
                        setTimeout(function() {
                            window.location.href = checkoutUrl;
                        }, 2000);
                    })();
                </script>
                
                <noscript>
                    <a href="<?php echo esc_url($checkout_url_raw); ?>" class="button primary">
                        Continue to Payment →
                    </a>
                </noscript>
                
                <!-- Manual trigger for testing -->
                <div style="margin-top: 20px;">
                    <a href="<?php echo esc_url($checkout_url_raw); ?>" class="button secondary">
                        Continue Manually →
                    </a>
                </div>
            </div>
            
        <?php else: ?>
            <!-- Still processing -->
            <div class="payment-action">
                <p>Processing your order...</p>
                <div class="loading-spinner"></div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
get_footer();
?>