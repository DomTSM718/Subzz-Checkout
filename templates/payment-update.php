<?php
/**
 * Payment Update Page — Standalone page at /payment-update/
 *
 * No WooCommerce login required — the JWT token provides authentication.
 * Server-side token validation via Azure API before rendering the form.
 *
 * Flow:
 * 1. Extract ?token= from URL
 * 2. No token → error page
 * 3. POST to /api/payment-update/validate-token server-side
 * 4. Invalid/expired → error page
 * 5. Valid → render subscription summary + card form
 *
 * @since 2.0.0
 */

if (!defined('ABSPATH')) exit;

// Get token from URL
$token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
$subscription_data = null;
$error_message = '';
$error_code = '';

if (empty($token)) {
    $error_message = 'Invalid link. No payment update token was provided.';
    $error_code = 'NO_TOKEN';
} else {
    // Validate token server-side
    $api_url = defined('SUBZZ_AZURE_API_URL')
        ? SUBZZ_AZURE_API_URL
        : 'http://localhost:5000/api';

    $response = wp_remote_post($api_url . '/payment-update/validate-token', array(
        'timeout' => 15,
        'headers' => array('Content-Type' => 'application/json'),
        'body' => wp_json_encode(array('token' => $token))
    ));

    if (is_wp_error($response)) {
        $error_message = 'Unable to verify your link. Please try again later.';
        $error_code = 'API_ERROR';
        error_log('SUBZZ PAYMENT UPDATE: Token validation failed - ' . $response->get_error_message());
    } else {
        $response_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($response_code === 200 && !empty($body['success'])) {
            $subscription_data = $body['subscription'];
        } else {
            $error_code = $body['errorCode'] ?? 'UNKNOWN';
            if ($error_code === 'TOKEN_EXPIRED') {
                $error_message = 'This link has expired. Please request a new payment update link.';
            } elseif ($error_code === 'TOKEN_INVALID' || $error_code === 'TOKEN_MISSING') {
                $error_message = 'This link is invalid. Please check the link in your email and try again.';
            } else {
                $error_message = $body['error'] ?? 'An unexpected error occurred.';
            }
        }
    }
}

get_header();
?>

<div class="subzz-payment-update">

    <?php if (!empty($error_message)) : ?>
        <!-- Error State -->
        <div class="payment-update-container">
            <div class="payment-update-error">
                <h2>Payment Update Unavailable</h2>
                <p><?php echo esc_html($error_message); ?></p>

                <?php if ($error_code === 'TOKEN_EXPIRED') : ?>
                    <p style="margin-top: 16px;">
                        <?php if (is_user_logged_in()) : ?>
                            <a href="<?php echo esc_url(wc_get_account_endpoint_url('my-subscription') . '?tab=payment'); ?>" class="portal-btn portal-btn-primary">
                                Go to My Subscription
                            </a>
                        <?php else : ?>
                            Contact us if you continue to experience issues.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>

    <?php else : ?>
        <!-- Valid Token — Show Payment Update Form -->
        <div class="payment-update-container">
            <h2>Update Payment Method</h2>

            <?php if (!empty($subscription_data['isSuspended'])) : ?>
                <div class="portal-alert portal-alert-warning">
                    <strong>Subscription Suspended</strong>
                    <p>Updating your card will reactivate your subscription and resume billing.</p>
                </div>
            <?php endif; ?>

            <!-- Subscription Summary -->
            <div class="payment-update-summary">
                <div class="summary-row">
                    <span class="summary-label">Status</span>
                    <span class="summary-value">
                        <span class="portal-status-badge portal-status-<?php echo esc_attr($subscription_data['status']); ?>">
                            <?php echo esc_html(ucfirst($subscription_data['status'])); ?>
                        </span>
                    </span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Monthly Amount</span>
                    <span class="summary-value"><?php echo esc_html($subscription_data['currency']); ?> <?php echo number_format(ceil($subscription_data['monthlyAmount']), 0); ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Current Card</span>
                    <span class="summary-value"><?php echo esc_html($subscription_data['cardBrand'] ?? 'Card'); ?> ending <?php echo esc_html($subscription_data['cardLast4'] ?? '----'); ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Next Billing Date</span>
                    <span class="summary-value"><?php echo date('j M Y', strtotime($subscription_data['nextBillingDate'])); ?></span>
                </div>
            </div>

            <!-- Card Form -->
            <div class="payment-update-form">
                <div id="payment-card-form">
                    <p style="text-align: center; color: #6c757d; padding: 20px 0;">
                        Loading secure card form...
                    </p>
                </div>

                <button id="payment-update-submit" class="portal-btn portal-btn-primary" style="width: 100%; padding: 14px;" disabled>
                    Update Card
                </button>
            </div>

            <!-- Result -->
            <div id="payment-update-result" class="payment-update-result"></div>

            <!-- Back link (only for logged-in users) -->
            <?php if (is_user_logged_in()) : ?>
                <a href="<?php echo esc_url(wc_get_account_endpoint_url('my-subscription') . '?tab=payment'); ?>" class="payment-update-back">
                    Back to My Subscription
                </a>
            <?php endif; ?>
        </div>

        <!-- Pass data to JS -->
        <script>
            var subzzPaymentData = {
                token: <?php echo wp_json_encode($token); ?>,
                subscriptionId: <?php echo wp_json_encode($subscription_data['subscriptionId']); ?>,
                apiUrl: <?php echo wp_json_encode(defined('SUBZZ_AZURE_API_URL') ? SUBZZ_AZURE_API_URL : 'http://localhost:5000/api'); ?>
            };
        </script>

    <?php endif; ?>

</div>

<?php
get_footer();
?>
