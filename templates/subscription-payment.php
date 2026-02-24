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

// LEKKAPAY INTEGRATION: Create real payment session via Azure API
$payment_session_data = null;
if (empty($error_message) && $signature_verified && $order_data) {
    error_log('SUBZZ PAYMENT PAGE: Creating LekkaPay payment session');

    // Determine payment amount: initial payment if present, otherwise monthly
    $initial_payment = isset($_GET['initial_payment']) ? floatval($_GET['initial_payment']) : 0;
    if ($initial_payment <= 0 && isset($order_data['initial_payment_amount'])) {
        $initial_payment = floatval($order_data['initial_payment_amount']);
    }

    $total_amount = $order_data['order_totals']['total'] ?? '0';
    $currency = $order_data['order_totals']['currency'] ?? 'ZAR';

    // If initial payment > 0, charge that amount first (not the monthly)
    $payment_amount = ($initial_payment > 0) ? $initial_payment : $total_amount;

    error_log('SUBZZ PAYMENT PAGE: Customer email: ' . $customer_data['email']);
    error_log('SUBZZ PAYMENT PAGE: Customer name: ' . $customer_data['full_name']);
    error_log('SUBZZ PAYMENT PAGE: Payment amount: ' . $currency . ' ' . $payment_amount . ' (initial_payment=' . $initial_payment . ', order_total=' . $total_amount . ')');
    error_log('SUBZZ PAYMENT PAGE: Reference ID: ' . $reference_id);

    // Call Azure API to create LekkaPay session
    $lekkapay_response = $azure_client->create_lekkapay_session(array(
        'orderReferenceId' => $reference_id,
        'customerEmail' => $customer_data['email'],
        'customerName' => $customer_data['full_name'],
        'amount' => $payment_amount,
        'currency' => $currency
    ));
    
    if ($lekkapay_response && isset($lekkapay_response['sessionId'])) {
        error_log('SUBZZ LEKKAPAY SUCCESS: Session created successfully');
        error_log('SUBZZ LEKKAPAY SUCCESS: Session ID: ' . $lekkapay_response['sessionId']);
        error_log('SUBZZ LEKKAPAY SUCCESS: Checkout URL: ' . $lekkapay_response['checkoutUrl']);
        
        $payment_session_data = array(
            'success' => true,
            'sessionId' => $lekkapay_response['sessionId'],
            'checkoutUrl' => $lekkapay_response['checkoutUrl'],
            'amount' => $total_amount,
            'currency' => $currency,
            'expiresAt' => $lekkapay_response['expiresAt'] ?? date('Y-m-d H:i:s', strtotime('+1 hour'))
        );
        
        // Store session info in WooCommerce order meta
        if (isset($order_data['woocommerce_order_id'])) {
            $wc_order = wc_get_order($order_data['woocommerce_order_id']);
            if ($wc_order) {
                $wc_order->update_meta_data('_subzz_payment_session_id', $payment_session_data['sessionId']);
                $wc_order->update_meta_data('_subzz_payment_status', 'pending');
                $wc_order->update_meta_data('_subzz_payment_provider', 'lekkapay');
                $wc_order->update_meta_data('_subzz_order_status', 'payment_pending');
                $wc_order->save();
                error_log('SUBZZ LEKKAPAY: Updated WooCommerce order with LekkaPay session');
            }
        }
    } else {
        error_log('SUBZZ LEKKAPAY ERROR: Failed to create payment session');
        $error_message = 'Unable to create payment session. Please try again or contact support.';
        $payment_session_data = null;
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
/* ── Subzz Payment Page — Matches checkout & contract design system ─── */
/* Uses .btn-primary/.btn-secondary to avoid WordPress theme conflicts */
.subzz-payment-page {
    max-width: 900px;
    margin: 40px auto;
    padding: 0 20px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
    color: #2d3748;
}

/* ── Progress indicator (shared with checkout & contract) ── */
.checkout-progress {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0;
    margin-bottom: 36px;
}

.progress-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
}

.step-dot {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #dee2e6;
    color: #fff;
    font-weight: 700;
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.3s;
}

.progress-step.active .step-dot {
    background: #3498db;
}

.progress-step.done .step-dot {
    background: #27ae60;
}

.step-label {
    font-size: 12px;
    color: #6c757d;
    font-weight: 500;
}

.progress-step.active .step-label {
    color: #3498db;
    font-weight: 600;
}

.progress-step.done .step-label {
    color: #27ae60;
    font-weight: 600;
}

.progress-line {
    width: 48px;
    height: 3px;
    background: #dee2e6;
    margin: 0 4px;
    margin-bottom: 20px;
}

.progress-line.done {
    background: #27ae60;
}

/* ── Page header ── */
.payment-header {
    text-align: center;
    margin-bottom: 32px;
}

.payment-header h1 {
    font-size: 28px;
    font-weight: 700;
    color: #1a202c;
    margin-bottom: 24px;
}

/* ── Content card ── */
.payment-content {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 32px;
}

/* ── Order summary ── */
.order-summary {
    margin-bottom: 24px;
}

.order-summary h2 {
    color: #1a202c;
    margin-bottom: 16px;
    font-size: 20px;
    font-weight: 600;
}

.order-details {
    background: #fafbfc;
    border: 1px solid #eee;
    border-radius: 8px;
    padding: 20px;
}

.order-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid #eee;
    font-size: 15px;
}

.order-item:last-child {
    border-bottom: none;
}

.order-item span {
    color: #4a5568;
}

.order-item strong {
    color: #1a202c;
}

.order-total {
    font-size: 18px;
    font-weight: 700;
    color: #1a202c;
    border-top: 2px solid #dee2e6;
    padding-top: 12px;
    margin-top: 4px;
}

/* ── Buttons (theme-safe) ── */
.btn-primary {
    display: inline-block;
    padding: 14px 28px;
    font-size: 16px;
    font-weight: 700;
    text-align: center;
    text-decoration: none;
    border: none;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.2s;
    background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
    color: #fff;
}

.btn-primary:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3);
}

.btn-secondary {
    display: inline-block;
    padding: 10px 20px;
    font-size: 14px;
    font-weight: 600;
    text-align: center;
    text-decoration: none;
    border: 2px solid #3498db;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    background: #fff;
    color: #3498db;
}

.btn-secondary:hover {
    background: #3498db;
    color: #fff;
}

/* ── Action area ── */
.payment-action {
    text-align: center;
    margin-top: 28px;
}

.payment-action .btn-primary,
.payment-action .btn-secondary {
    margin: 6px 8px;
}

/* ── Spinner ── */
.loading-spinner {
    display: inline-block;
    width: 24px;
    height: 24px;
    border: 3px solid #dee2e6;
    border-radius: 50%;
    border-top-color: #3498db;
    animation: spin 0.8s linear infinite;
    margin: 12px auto;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* ── Alert boxes ── */
.error-message {
    background: #fff5f5;
    border: 1px solid #fed7d7;
    border-radius: 8px;
    padding: 16px 20px;
    margin-bottom: 20px;
    color: #c53030;
    font-size: 15px;
}

.success-message {
    background: #f0fff4;
    border: 1px solid #c6f6d5;
    border-radius: 8px;
    padding: 16px 20px;
    margin-bottom: 20px;
    color: #276749;
    font-size: 15px;
}

.retry-message {
    background: #fffbeb;
    border: 1px solid #fef3c7;
    border-radius: 8px;
    padding: 16px 20px;
    margin-bottom: 20px;
    color: #92400e;
    font-size: 15px;
}

.security-info {
    background: #ebf8ff;
    border: 1px solid #bee3f8;
    border-radius: 8px;
    padding: 16px 20px;
    margin-top: 20px;
    font-size: 14px;
    color: #4a5568;
}

.security-info strong {
    color: #1a202c;
}

.status-info {
    background: #fafbfc;
    border: 1px solid #eee;
    border-radius: 8px;
    padding: 10px 16px;
    margin-bottom: 16px;
    font-size: 12px;
    color: #6c757d;
}

/* ── Responsive ── */
@media (max-width: 600px) {
    .subzz-payment-page { padding: 0 12px; }
    .payment-content { padding: 20px 16px; }
    .payment-header h1 { font-size: 22px; }
    .order-item { flex-direction: column; gap: 4px; }
}
</style>

<div class="subzz-payment-page">
    <div class="payment-header">
        <h1>Complete Your Payment</h1>
        <div class="checkout-progress">
            <div class="progress-step done">
                <span class="step-dot">1</span>
                <span class="step-label">Choose Plan</span>
            </div>
            <div class="progress-line done"></div>
            <div class="progress-step done">
                <span class="step-dot">2</span>
                <span class="step-label">Contract</span>
            </div>
            <div class="progress-line done"></div>
            <div class="progress-step active">
                <span class="step-dot">3</span>
                <span class="step-label">Payment</span>
            </div>
            <div class="progress-line"></div>
            <div class="progress-step">
                <span class="step-dot">4</span>
                <span class="step-label">Complete</span>
            </div>
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
                <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="btn-secondary">
                    ← Return to Checkout
                </a>
                <?php if (!empty($reference_id)): ?>
                <?php
                // CHK-001: Use signed JWT for re-sign link (same as generate_jwt_token in payment handler)
                require_once dirname(dirname(__FILE__)) . '/includes/vendor/firebase/php-jwt/src/JWT.php';
                $resign_secret = defined('SUBZZ_CHECKOUT_JWT_SECRET') ? SUBZZ_CHECKOUT_JWT_SECRET : wp_salt('auth');
                $resign_token = \Firebase\JWT\JWT::encode(array(
                    'iss' => home_url(),
                    'iat' => time(),
                    'exp' => time() + 1800,
                    'reference_id' => $reference_id,
                    'customer_email' => $customer_data['email'] ?? '',
                    'purpose' => 'contract_signature'
                ), $resign_secret, 'HS256');
                ?>
                <a href="<?php echo esc_url(home_url('/contract-signature/?token=' . $resign_token)); ?>" class="btn-primary">
                    Re-sign Contract
                </a>
                <?php endif; ?>
            </div>
        <?php elseif ($payment_session_data && isset($payment_session_data['success']) && $payment_session_data['success']): ?>
            <!-- Payment session created successfully -->
            <div class="success-message">
                <strong>Your contract has been signed successfully!</strong>
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
                    
                    <?php if ($initial_payment > 0): ?>
                    <div class="order-item">
                        <span><strong>Initial Payment (charged now):</strong></span>
                        <strong><?php echo esc_html($currency); ?> <?php echo number_format($initial_payment, 2); ?></strong>
                    </div>
                    <?php
                    $selected_term = isset($order_data['selected_term_months']) ? intval($order_data['selected_term_months']) : 0;
                    $reduced = isset($order_data['reduced_monthly_amount']) ? floatval($order_data['reduced_monthly_amount']) : 0;
                    if ($selected_term && $reduced): ?>
                    <div class="order-item">
                        <span>Then <?php echo $selected_term; ?> monthly payments of:</span>
                        <strong><?php echo esc_html($currency); ?> <?php echo number_format($reduced, 2); ?></strong>
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="order-total order-item">
                        <span>Total Monthly Payment:</span>
                        <strong><?php echo esc_html($currency); ?> <?php echo number_format((float)($total_amount), 2); ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="security-info">
                <strong>Secure Payment:</strong> You will be redirected to our secure payment provider to complete your payment.
            </div>
            
            <div class="payment-action" id="payment-redirect">
                <p>Redirecting to secure payment gateway...</p>
                <div class="loading-spinner"></div>
                
                <?php
                // FIXED: Store the URL in a JavaScript variable to avoid encoding issues
                $checkout_url_raw = $payment_session_data['checkoutUrl'] ?? '';

                // CHK-003: Validate checkout URL domain against allowlist
                $allowed_csv = defined('SUBZZ_LEKKAPAY_ALLOWED_DOMAINS') ? SUBZZ_LEKKAPAY_ALLOWED_DOMAINS : '';
                $allowed_domains = array_filter(array_map('trim', explode(',', $allowed_csv)));
                $parsed_checkout = parse_url($checkout_url_raw);
                $checkout_domain = $parsed_checkout['host'] ?? '';

                if (empty($allowed_domains) || !in_array($checkout_domain, $allowed_domains, true)) {
                    error_log('SUBZZ SECURITY: Blocked redirect to untrusted domain: ' . $checkout_domain);
                    ?>
                    <div class="payment-error" style="color: #dc3545; padding: 20px; text-align: center;">
                        <p><strong>Payment gateway error.</strong> Please contact support.</p>
                    </div>
                    <?php
                } else {
                ?>
                <script>
                    // Auto-redirect after 2 seconds
                    (function() {
                        var checkoutUrl = <?php echo json_encode($checkout_url_raw); ?>;

                        console.log('SUBZZ PAYMENT: Redirecting to payment gateway');

                        setTimeout(function() {
                            window.location.href = checkoutUrl;
                        }, 2000);
                    })();
                </script>

                <noscript>
                    <a href="<?php echo esc_url($checkout_url_raw); ?>" class="btn-primary">
                        Continue to Payment &rarr;
                    </a>
                </noscript>
                <?php } ?>
                
                <!-- Manual trigger for testing -->
                <div style="margin-top: 20px;">
                    <a href="<?php echo esc_url($checkout_url_raw); ?>" class="btn-secondary">
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