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

// Attempt to load order details from Azure via reference_id or session
$order_details = null;
$reference_id = isset($_GET['reference_id']) ? sanitize_text_field($_GET['reference_id']) : '';

if (empty($reference_id) && WC()->session) {
    $reference_id = WC()->session->get('subzz_reference_id', '');
}

if (!empty($reference_id) && class_exists('Subzz_Azure_API_Client')) {
    $azure_client = new Subzz_Azure_API_Client();
    $order_details = $azure_client->retrieve_order_data($reference_id);
    error_log('SUBZZ PAYMENT SUCCESS: Loaded order details for reference: ' . $reference_id);
}
?>

<style>
/* ── Subzz Payment Success — Matches checkout design system ──────── */
.subzz-payment-success {
    max-width: 900px;
    margin: 40px auto;
    padding: 0 20px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
    color: #2d3748;
}

/* ── Progress indicator ── */
.checkout-progress { display: flex; align-items: center; justify-content: center; gap: 0; margin-bottom: 36px; }
.progress-step { display: flex; flex-direction: column; align-items: center; gap: 6px; }
.step-dot { width: 32px; height: 32px; border-radius: 50%; background: #dee2e6; color: #fff; font-weight: 700; font-size: 14px; display: flex; align-items: center; justify-content: center; }
.progress-step.done .step-dot { background: #27ae60; }
.progress-step.active .step-dot { background: #3498db; }
.step-label { font-size: 12px; color: #6c757d; font-weight: 500; }
.progress-step.done .step-label { color: #27ae60; font-weight: 600; }
.progress-step.active .step-label { color: #3498db; font-weight: 600; }
.progress-line { width: 48px; height: 3px; background: #dee2e6; margin: 0 4px; margin-bottom: 20px; }
.progress-line.done { background: #27ae60; }

/* ── Header ── */
.success-header { text-align: center; margin-bottom: 32px; }
.success-header h1 { font-size: 28px; font-weight: 700; color: #27ae60; margin-bottom: 8px; }
.success-header p { color: #4a5568; font-size: 16px; }

/* ── Content card ── */
.success-content {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 32px;
    margin-bottom: 20px;
}

.success-content p {
    font-size: 15px;
    line-height: 1.6;
    color: #4a5568;
    margin-bottom: 12px;
}

/* ── Order summary within success ── */
.order-summary-box {
    background: #fafbfc;
    border: 1px solid #eee;
    border-radius: 8px;
    padding: 20px;
    margin-top: 20px;
}

.order-summary-box .summary-label {
    font-size: 13px;
    color: #6c757d;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 12px;
}

.order-summary-box .summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    font-size: 15px;
}

.order-summary-box .summary-row span { color: #4a5568; }
.order-summary-box .summary-row strong { color: #1a202c; }
.order-summary-box .ref-id { font-size: 12px; color: #a0aec0; margin-top: 8px; }

/* ── Info box ── */
.info-box {
    background: #ebf8ff;
    border: 1px solid #bee3f8;
    border-radius: 8px;
    padding: 20px;
    margin: 20px 0;
    text-align: left;
}

.info-box strong {
    color: #1a202c;
    display: block;
    margin-bottom: 10px;
    font-size: 15px;
}

.info-box ul {
    margin: 8px 0 0 20px;
    padding: 0;
}

.info-box li {
    margin-bottom: 6px;
    color: #4a5568;
    font-size: 14px;
}

/* ── Processing note ── */
.processing-note {
    background: #fffbeb;
    border: 1px solid #fef3c7;
    border-radius: 8px;
    padding: 16px 20px;
    margin: 20px 0;
    font-size: 14px;
    color: #92400e;
}

/* ── Buttons (theme-safe) ── */
.action-buttons { text-align: center; margin-top: 28px; }

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
    margin: 6px 8px;
}

.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3); color: #fff; }

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
    margin: 6px 8px;
}

.btn-secondary:hover { background: #3498db; color: #fff; }

@media (max-width: 600px) {
    .subzz-payment-success { padding: 0 12px; }
    .success-content { padding: 20px 16px; }
    .success-header h1 { font-size: 22px; }
}
</style>

<div class="subzz-payment-success">
    <div class="success-header">
        <h1>Payment Received!</h1>
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
            <div class="progress-step done">
                <span class="step-dot">3</span>
                <span class="step-label">Payment</span>
            </div>
            <div class="progress-line done"></div>
            <div class="progress-step active">
                <span class="step-dot">4</span>
                <span class="step-label">Complete</span>
            </div>
        </div>
    </div>

    <div class="success-content">
        <p><strong>Thank you for your payment!</strong></p>
        <p>Your payment is being processed and you will receive a confirmation email shortly.</p>
        <p>Your subscription will be activated once the payment is confirmed (usually within a few minutes).</p>

        <?php if ($order_details): ?>
        <div class="order-summary-box">
            <div class="summary-label">Order Summary</div>
            <?php if (isset($order_details['order_items']) && is_array($order_details['order_items'])): ?>
                <?php foreach ($order_details['order_items'] as $item): ?>
                    <?php if (!empty($item['is_subscription'])): ?>
                    <div class="summary-row">
                        <span>Product</span>
                        <strong><?php echo esc_html($item['name']); ?></strong>
                    </div>
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
            <div class="summary-row">
                <span>Initial payment</span>
                <strong><?php echo esc_html($cur); ?> <?php echo number_format($ip, 2); ?></strong>
            </div>
            <?php if ($term && $reduced): ?>
            <div class="summary-row">
                <span><?php echo $term; ?> monthly payments of</span>
                <strong><?php echo esc_html($cur); ?> <?php echo number_format($reduced, 2); ?></strong>
            </div>
            <?php endif; ?>
            <?php elseif ($std > 0): ?>
            <div class="summary-row">
                <span>Monthly payment</span>
                <strong><?php echo esc_html($cur); ?> <?php echo number_format($std, 2); ?></strong>
            </div>
            <?php endif; ?>

            <?php if (!empty($reference_id)): ?>
            <div class="ref-id">Reference: <?php echo esc_html($reference_id); ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="info-box">
        <strong>What happens next?</strong>
        <ul>
            <li>Payment confirmation email (within 5 minutes)</li>
            <li>Subscription activation (immediate after confirmation)</li>
            <li>Access to your services (immediate after activation)</li>
            <li>First billing will occur on your selected billing date</li>
        </ul>
    </div>

    <div class="processing-note">
        <strong>Processing Time:</strong> Payment confirmations typically arrive within 2-5 minutes. If you don't receive confirmation within 10 minutes, please check your spam folder or contact support.
    </div>

    <div class="action-buttons">
        <a href="<?php echo esc_url(home_url('/')); ?>" class="btn-primary">
            Return to Home
        </a>
        <a href="<?php echo esc_url(home_url('/my-account/')); ?>" class="btn-secondary">
            View My Account
        </a>
    </div>
</div>

<?php
get_footer();
?>