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

// Get reference ID if available (for retry functionality)
$reference_id = isset($_GET['reference_id']) ? sanitize_text_field($_GET['reference_id']) : '';
$session_id = isset($_GET['session_id']) ? sanitize_text_field($_GET['session_id']) : '';

// Log the cancellation
error_log('SUBZZ PAYMENT CANCELLED: User cancelled payment at LekkaPay');
if (!empty($reference_id)) {
    error_log('SUBZZ PAYMENT CANCELLED: Reference ID: ' . $reference_id);
}
if (!empty($session_id)) {
    error_log('SUBZZ PAYMENT CANCELLED: Session ID: ' . $session_id);
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
                error_log('SUBZZ SECURITY: User ' . $current_email . ' tried to access order for ' . $order_email);
            }
        } else {
            error_log('SUBZZ SECURITY: Order not found for reference_id: ' . $reference_id);
        }
    } else {
        error_log('SUBZZ SECURITY: Unauthenticated user tried to access cancel page with reference_id');
    }
}
?>

<style>
/* ── Subzz Payment Cancelled — Matches checkout design system ──────── */
.subzz-payment-cancelled {
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
.progress-step.error .step-dot { background: #e74c3c; }
.step-label { font-size: 12px; color: #6c757d; font-weight: 500; }
.progress-step.done .step-label { color: #27ae60; font-weight: 600; }
.progress-step.error .step-label { color: #e74c3c; font-weight: 600; }
.progress-line { width: 48px; height: 3px; background: #dee2e6; margin: 0 4px; margin-bottom: 20px; }
.progress-line.done { background: #27ae60; }

/* ── Header ── */
.cancel-header { text-align: center; margin-bottom: 32px; }
.cancel-header h1 { font-size: 28px; font-weight: 700; color: #e74c3c; margin-bottom: 8px; }

/* ── Content card ── */
.cancel-content {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 32px;
    margin-bottom: 20px;
}

.cancel-content p {
    font-size: 15px;
    line-height: 1.6;
    color: #4a5568;
    margin-bottom: 12px;
}

.cancel-content .ref-id {
    font-size: 12px;
    color: #a0aec0;
    margin-top: 8px;
}

/* ── Info & reassurance boxes ── */
.info-box {
    background: #fafbfc;
    border: 1px solid #eee;
    border-radius: 8px;
    padding: 20px;
    margin: 20px 0;
    text-align: left;
}

.info-box strong {
    display: block;
    margin-bottom: 8px;
    color: #1a202c;
    font-size: 15px;
}

.info-box p {
    margin: 8px 0 0 0;
    color: #4a5568;
    font-size: 14px;
}

.reassurance {
    background: #ebf8ff;
    border: 1px solid #bee3f8;
    border-radius: 8px;
    padding: 16px 20px;
    margin: 20px 0;
    font-size: 14px;
    color: #4a5568;
}

.reassurance strong {
    color: #1a202c;
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
    .subzz-payment-cancelled { padding: 0 12px; }
    .cancel-content { padding: 20px 16px; }
    .cancel-header h1 { font-size: 22px; }
}
</style>

<div class="subzz-payment-cancelled">
    <div class="cancel-header">
        <h1>Payment Cancelled</h1>
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
            <div class="progress-step error">
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

    <div class="cancel-content">
        <p><strong>Your payment was cancelled.</strong></p>
        <p>No charges have been made to your account.</p>
        <p>If this was a mistake, you can try again below.</p>
        <?php if (!empty($reference_id)): ?>
        <div class="ref-id">Order reference: <?php echo esc_html($reference_id); ?></div>
        <?php endif; ?>
    </div>

    <div class="info-box">
        <strong>Need help?</strong>
        <p>If you're experiencing issues with payment or have questions about our subscription service, please contact our support team and we'll be happy to assist you.</p>
    </div>

    <div class="reassurance">
        <strong>Your Information is Safe</strong><br>
        All payment information is processed securely through our payment provider. We never store your card details.
    </div>

    <div class="action-buttons">
        <?php if ($show_retry): ?>
            <!-- CHK-004: Only show retry if logged-in user owns this order -->
            <a href="<?php echo esc_url(add_query_arg(array('reference_id' => $reference_id, 'signature_confirmed' => 'yes', 'retry' => 'yes'), home_url('/subscription-payment/'))); ?>" class="btn-primary">
                Try Payment Again
            </a>
        <?php else: ?>
            <!-- No valid reference or ownership check failed -->
            <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="btn-primary">
                Return to Checkout
            </a>
        <?php endif; ?>

        <a href="<?php echo esc_url(home_url('/')); ?>" class="btn-secondary">
            Return to Home
        </a>

        <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="btn-secondary">
            Contact Support
        </a>
    </div>
</div>

<?php
get_footer();
?>