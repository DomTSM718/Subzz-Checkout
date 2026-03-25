<?php
/**
 * Payment Cancelled Template
 * Displayed after cancelled payment from LekkaPay
 * 
 * URL: /payment-cancelled/
 * Triggered by: LekkaPay cancelUrl redirect
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

get_header();

// Capture all LekkaPay return parameters
$reference_id = isset($_GET['reference_id']) ? sanitize_text_field($_GET['reference_id']) : '';
$session_id = isset($_GET['session_id']) ? sanitize_text_field($_GET['session_id']) : '';
$response_code = isset($_GET['response_code']) ? sanitize_text_field($_GET['response_code']) : '';
$response_message = isset($_GET['response']) ? sanitize_text_field($_GET['response']) : '';
$transaction_result = isset($_GET['transaction_result']) ? sanitize_text_field($_GET['transaction_result']) : '';
$transaction_id = isset($_GET['transaction_id']) ? sanitize_text_field($_GET['transaction_id']) : '';

// Log the cancellation
subzz_log('SUBZZ PAYMENT CANCELLED: User cancelled payment at LekkaPay');
if (!empty($reference_id)) {
    subzz_log('SUBZZ PAYMENT CANCELLED: Reference ID: ' . $reference_id);
}
if (!empty($session_id)) {
    subzz_log('SUBZZ PAYMENT CANCELLED: Session ID: ' . $session_id);
}

// Log payment cancel/error event to Azure API
if (class_exists('Subzz_Azure_API_Client')) {
    $azure_client = new Subzz_Azure_API_Client();
    $user = wp_get_current_user();
    $azure_client->log_payment_event(array(
        'eventType'         => !empty($response_code) && $response_code !== '00' ? 'return_error' : 'return_cancelled',
        'gatewaySessionId'  => $session_id ?: null,
        'orderReferenceId'  => $reference_id ?: null,
        'customerEmail'     => $user ? $user->user_email : null,
        'responseCode'      => $response_code ?: null,
        'responseMessage'   => $response_message ?: null,
        'transactionResult' => $transaction_result ?: null,
        'transactionId'     => $transaction_id ?: null,
        'rawParams'         => wp_json_encode($_GET),
        'source'            => 'wordpress'
    ));
}

// CHK-004: Validate that logged-in user owns this order before showing retry link
$show_retry = false;
if (!empty($reference_id)) {
    if (is_user_logged_in()) {
        $azure_client = new Subzz_Azure_API_Client();
        $order_data = $azure_client->retrieve_order_data($reference_id);

        if ($order_data) {
            $order_email = $order_data['customer_email'] ?? '';
            $current_email = wp_get_current_user()->user_email;

            if (strtolower($order_email) === strtolower($current_email)) {
                $show_retry = true;
            } else {
                subzz_log('SUBZZ SECURITY: User ' . $current_email . ' tried to access order for ' . $order_email);
            }
        } else {
            subzz_log('SUBZZ SECURITY: Order not found for reference_id: ' . $reference_id);
        }
    } else {
        subzz_log('SUBZZ SECURITY: Unauthenticated user tried to access cancel page with reference_id');
    }
}
?>

<div class="subzz-checkout-header">
    <a href="<?php echo esc_url(home_url('/')); ?>">
        <img src="<?php echo esc_url(plugin_dir_url(dirname(__FILE__)) . 'assets/img/logo-white.png'); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>">
    </a>
</div>

<style>
.subzz-payment-cancelled {
    max-width: 600px;
    margin: 50px auto;
    padding: 30px;
    text-align: center;
    font-family: var(--subzz-font-family);
}

.cancel-icon {
    font-size: 64px;
    color: var(--subzz-warning-border);
    margin-bottom: 20px;
    animation: cancelShake 0.5s ease-in-out;
}

@keyframes cancelShake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-10px); }
    75% { transform: translateX(10px); }
}

.cancel-message h1 {
    color: #856404;
    margin-bottom: 15px;
    font-size: 32px;
}

.cancel-content {
    background: var(--subzz-warning-bg);
    border: 1px solid var(--subzz-warning-border);
    border-radius: var(--subzz-radius-xl);
    padding: 30px;
    margin: 20px 0;
}

.cancel-content p {
    font-size: 16px;
    line-height: 1.6;
    color: #856404;
    margin-bottom: 15px;
}

.info-box {
    background: #f8f9fa;
    border: 1px solid var(--subzz-border);
    padding: 15px;
    margin: 20px 0;
    text-align: left;
    border-radius: var(--subzz-radius-md);
}

.info-box strong {
    display: block;
    margin-bottom: 10px;
    color: var(--subzz-gray);
}

.info-box p {
    margin: 10px 0 0 0;
    color: rgba(84, 84, 84, 0.7);
    font-size: 14px;
}

.action-buttons {
    margin-top: 30px;
}

.button {
    display: inline-block;
    padding: 12px 30px;
    margin: 0 10px 10px 10px;
    background: var(--subzz-blue);
    color: white !important;
    text-decoration: none;
    border-radius: var(--subzz-radius-md);
    transition: all 150ms;
    font-weight: 700;
}

.button:hover {
    opacity: 0.9;
    color: white !important;
}

.button.secondary {
    background: transparent;
    color: var(--subzz-gray) !important;
    border: 2px solid var(--subzz-border);
}

.button.secondary:hover {
    opacity: 0.7;
    color: var(--subzz-gray) !important;
}

.button.warning {
    background: var(--subzz-orange);
    color: white !important;
}

.button.warning:hover {
    opacity: 0.9;
    color: white !important;
}

.reassurance {
    font-size: 14px;
    color: rgba(84, 84, 84, 0.7);
    margin-top: 20px;
    padding: 15px;
    background: var(--subzz-info-bg);
    border-radius: var(--subzz-radius-md);
}
</style>

<div class="subzz-payment-cancelled">
    <div class="cancel-icon">⚠️</div>
    
    <div class="cancel-message">
        <h1>Payment Cancelled</h1>
    </div>
    
    <div class="cancel-content">
        <p><strong>Your payment was cancelled.</strong></p>
        <p>No charges have been made to your account.</p>
        <p>If this was a mistake, you can try again below.</p>
        <?php if (!empty($reference_id)): ?>
        <p style="font-size:13px;color:#856404;margin-top:12px;">Order reference: <strong><?php echo esc_html($reference_id); ?></strong></p>
        <?php endif; ?>
    </div>
    
    <div class="info-box">
        <strong>💡 Need help?</strong>
        <p>If you're experiencing issues with payment or have questions about our subscription service, please contact our support team and we'll be happy to assist you.</p>
    </div>
    
    <div class="reassurance">
        <strong>🔒 Your Information is Safe</strong><br>
        All payment information is processed securely through our payment provider. We never store your card details.
    </div>
    
    <div class="action-buttons">
        <?php if ($show_retry): ?>
            <!-- CHK-004: Only show retry if logged-in user owns this order -->
            <a href="<?php echo esc_url(add_query_arg(array('reference_id' => $reference_id, 'signature_confirmed' => 'yes', 'retry' => 'yes'), home_url('/subscription-payment/'))); ?>" class="button warning">
                Try Payment Again
            </a>
        <?php else: ?>
            <!-- No valid reference or ownership check failed -->
            <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="button warning">
                Return to Checkout
            </a>
        <?php endif; ?>
        
        <a href="<?php echo esc_url(home_url('/')); ?>" class="button secondary">
            Return to Home
        </a>
        
        <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="button">
            Contact Support
        </a>
    </div>
</div>

<?php
get_footer();
?>