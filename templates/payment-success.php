<?php
/**
 * Payment Success Template
 * Displayed after successful payment redirect from LekkaPay
 * 
 * URL: /payment-success/
 * Triggered by: LekkaPay returnUrl redirect
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

get_header();

// Capture all LekkaPay return parameters
$reference_id = isset($_GET['reference_id']) ? sanitize_text_field($_GET['reference_id']) : '';
$response_code = isset($_GET['response_code']) ? sanitize_text_field($_GET['response_code']) : '';
$response_message = isset($_GET['response']) ? sanitize_text_field($_GET['response']) : '';
$transaction_result = isset($_GET['transaction_result']) ? sanitize_text_field($_GET['transaction_result']) : '';
$transaction_id = isset($_GET['transaction_id']) ? sanitize_text_field($_GET['transaction_id']) : '';

if (empty($reference_id) && WC()->session) {
    $reference_id = WC()->session->get('subzz_reference_id', '');
}

// Log payment return event to Azure API
if (class_exists('Subzz_Azure_API_Client')) {
    $azure_client = new Subzz_Azure_API_Client();

    // Log the event
    $user = wp_get_current_user();
    $azure_client->log_payment_event(array(
        'eventType'         => 'return_success',
        'orderReferenceId'  => $reference_id ?: null,
        'customerEmail'     => $user ? $user->user_email : null,
        'responseCode'      => $response_code ?: null,
        'responseMessage'   => $response_message ?: null,
        'transactionResult' => $transaction_result ?: null,
        'transactionId'     => $transaction_id ?: null,
        'rawParams'         => wp_json_encode($_GET),
        'source'            => 'wordpress'
    ));

    // Load order details
    $order_details = null;
    if (!empty($reference_id)) {
        $order_details = $azure_client->retrieve_order_data($reference_id);
        subzz_log('SUBZZ PAYMENT SUCCESS: Loaded order details for reference: ' . $reference_id);
    }
}
?>

<div class="subzz-checkout-header">
    <a href="<?php echo esc_url(home_url('/')); ?>">
        <img src="<?php echo esc_url(plugin_dir_url(dirname(__FILE__)) . 'assets/img/logo-white.png'); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>">
    </a>
</div>

<style>
.subzz-payment-success {
    max-width: 600px;
    margin: 50px auto;
    padding: 30px;
    text-align: center;
    font-family: var(--subzz-font-family);
}

.success-icon {
    font-size: 64px;
    color: #28a745;
    margin-bottom: 20px;
    animation: successPulse 0.6s ease-in-out;
}

@keyframes successPulse {
    0% { transform: scale(0.8); opacity: 0; }
    50% { transform: scale(1.1); }
    100% { transform: scale(1); opacity: 1; }
}

.success-message h1 {
    color: #28a745;
    margin-bottom: 15px;
    font-size: 32px;
}

.success-content {
    background: #f8f9fa;
    border: 1px solid var(--subzz-border);
    border-radius: var(--subzz-radius-xl);
    box-shadow: var(--subzz-shadow-2xl);
    padding: 30px;
    margin: 20px 0;
    text-align: left;
}

.success-content p {
    font-size: 16px;
    line-height: 1.6;
    color: var(--subzz-gray);
    margin-bottom: 15px;
}

.info-box {
    background: var(--subzz-info-bg);
    border-left: 4px solid var(--subzz-blue);
    padding: 15px;
    margin: 20px 0;
    text-align: left;
    border-radius: var(--subzz-radius-md);
}

.info-box strong {
    color: var(--subzz-blue);
    display: block;
    margin-bottom: 10px;
}

.info-box ul {
    margin: 10px 0 0 20px;
    padding: 0;
}

.info-box li {
    margin-bottom: 8px;
    color: var(--subzz-gray);
}

.action-buttons {
    margin-top: 30px;
}

.button {
    display: inline-block;
    padding: 12px 30px;
    margin: 0 10px;
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

.processing-note {
    background: var(--subzz-warning-bg);
    border: 1px solid var(--subzz-warning-border);
    border-radius: var(--subzz-radius-md);
    padding: 15px;
    margin: 20px 0;
    font-size: 14px;
    color: #856404;
}

.subzz-dash-divider {
    display: flex;
    gap: 8px;
    justify-content: center;
    margin-bottom: 32px;
}

.subzz-dash-divider span {
    width: 48px;
    height: 4px;
    border-radius: 9999px;
}

@media (max-width: 600px) {
    .subzz-payment-success {
        margin: 20px auto;
        padding: 16px;
    }

    .success-message h1 {
        font-size: 24px;
    }

    .success-content {
        padding: 20px 16px;
    }

    .success-icon {
        font-size: 48px;
    }

    .action-buttons .button {
        display: block;
        margin: 8px 0;
    }

    .subzz-dash-divider span {
        width: 36px;
    }
}
</style>

<div class="subzz-payment-success">
    <div class="success-icon">✅</div>
    
    <div class="success-message">
        <h1>Subscription Confirmed!</h1>
        <div class="subzz-dash-divider">
            <span style="background: var(--subzz-orange)"></span>
            <span style="background: var(--subzz-red)"></span>
            <span style="background: var(--subzz-blue)"></span>
            <span style="background: var(--subzz-cyan)"></span>
        </div>
    </div>
    
    <div class="success-content">
        <p><strong>Your subscription has been placed!</strong></p>
        <p>We've received your payment and your subscription is now being activated.</p>
        <p>You will receive a confirmation email shortly with your subscription details.</p>

        <?php if ($order_details): ?>
        <div style="margin-top:20px;padding-top:15px;border-top:1px solid #dee2e6;">
            <p style="font-size:14px;color:#666;margin-bottom:8px;"><strong>Order Summary</strong></p>
            <?php if (isset($order_details['order_items']) && is_array($order_details['order_items'])): ?>
                <?php foreach ($order_details['order_items'] as $item): ?>
                    <?php if (!empty($item['is_subscription'])): ?>
                    <p style="margin:4px 0;"><strong><?php echo esc_html($item['name']); ?></strong></p>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php
            $ip = isset($order_details['initial_payment_amount']) ? floatval($order_details['initial_payment_amount']) : 0;
            $term = isset($order_details['selected_term_months']) ? intval($order_details['selected_term_months']) : 0;
            $reduced = isset($order_details['reduced_monthly_amount']) ? floatval($order_details['reduced_monthly_amount']) : 0;
            $std = isset($order_details['standard_monthly_amount']) ? floatval($order_details['standard_monthly_amount']) : 0;
            $cur = $order_details['order_totals']['currency'] ?? 'ZAR';
            ?>

            <?php if ($ip > 0): ?>
            <p style="margin:4px 0;">Initial payment: <strong><?php echo esc_html($cur); ?> <?php echo number_format(ceil($ip), 0); ?></strong></p>
            <?php if ($term && $reduced): ?>
            <p style="margin:4px 0;"><?php echo ($term - 1); ?> monthly payments of: <strong><?php echo esc_html($cur); ?> <?php echo number_format(ceil($reduced), 0); ?></strong></p>
            <?php endif; ?>
            <?php elseif ($std > 0): ?>
            <p style="margin:4px 0;">Monthly payment: <strong><?php echo esc_html($cur); ?> <?php echo number_format(ceil($std), 0); ?></strong></p>
            <?php endif; ?>

            <?php if (!empty($reference_id)): ?>
            <p style="margin:8px 0 0;font-size:12px;color:#999;">Reference: <?php echo esc_html($reference_id); ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="info-box">
        <strong>📧 What happens next?</strong>
        <ul>
            <li>Payment confirmation email (within 5 minutes)</li>
            <li>Subscription activation (immediate after confirmation)</li>
            <li>Access to your services (immediate after activation)</li>
            <li>First billing will occur on your selected billing date</li>
        </ul>
    </div>
    
    <div class="processing-note">
        <strong>⏱️ Processing Time:</strong> Payment confirmations typically arrive within 2-5 minutes. If you don't receive confirmation within 10 minutes, please check your spam folder or contact support.
    </div>
    
    <div class="action-buttons">
        <a href="<?php echo esc_url(home_url('/')); ?>" class="button">
            Return to Home
        </a>
        <a href="<?php echo esc_url(home_url('/my-account/')); ?>" class="button secondary">
            View My Account
        </a>
    </div>
</div>

<?php
get_footer();
?>