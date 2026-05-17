<?php
/**
 * Payment Redirect Page — Phase 5 Multi-Gateway Picker UI.
 *
 * Renders the vendor picker between contract-signed and payment-redirect-to-HPP.
 * Customer arrives here with order context in URL params (orderRef, email, amount,
 * signatureId). JS calls the Azure /payment/vendors endpoint, renders the radio
 * group, then on submit POSTs /payment/create-session with the chosen vendor and
 * redirects to the returned checkoutUrl.
 *
 * Locked decisions (Docs/Planning/Stitch-Parallel-Rail-Plan-2026-05-17.md):
 *   UX-1: branded vendor names + logos, secondary visual weight
 *   UX-2: no persistence — customer picks each checkout
 *   UX-4: header "Pay by card", radio (not side-by-side), shared trust copy below
 *   UX-3: failure handling (bounded retry + re-pick CTA) — Step 7 wires JS-side
 *
 * Assets enqueued by subzz_enqueue_payment_redirect_assets in the main plugin file.
 * Astra note: use .btn-primary (NOT .button primary) per frontend-patterns.md #1232.
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

// Order context from URL params — populated by the contract-success redirect path
// (Phase 5 follow-up wires the redirect; pre-wire, the page works as a standalone
// smoke target with params manually appended).
$order_ref = isset($_GET['orderRef']) ? sanitize_text_field($_GET['orderRef']) : '';
$customer_email = isset($_GET['email']) ? sanitize_email($_GET['email']) : '';
$signature_id = isset($_GET['signatureId']) ? sanitize_text_field($_GET['signatureId']) : '';
$amount = isset($_GET['amount']) ? floatval($_GET['amount']) : 0;
$cohort_id = isset($_GET['cohortId']) ? sanitize_text_field($_GET['cohortId']) : '';
?>

<main id="primary" class="site-main subzz-payment-redirect-page">
    <div class="subzz-picker-container">
        <h1 class="subzz-picker-heading">Pay by card</h1>

        <form id="subzz-picker-form" class="subzz-picker-form" novalidate>
            <div id="subzz-picker-vendors" class="subzz-picker-vendors" role="radiogroup" aria-label="Choose payment provider">
                <div class="subzz-picker-loading" role="status" aria-live="polite">
                    Loading payment methods&hellip;
                </div>
            </div>

            <p class="subzz-picker-trust">
                <span class="subzz-picker-trust-icon" aria-hidden="true">&#x1F512;</span>
                PCI-compliant South African payment providers.
                Your card details are processed securely &mdash; Subzz never sees them.
            </p>

            <button type="submit" id="subzz-picker-submit" class="btn-primary subzz-picker-submit" disabled>
                Continue to payment
            </button>
        </form>

        <div id="subzz-picker-error" class="subzz-picker-error" hidden role="alert" aria-live="assertive">
            <!-- Populated by picker.js on session-create failure (Step 7 adds full retry/re-pick UX). -->
        </div>
    </div>
</main>

<script type="application/json" id="subzz-picker-order-context">
<?php echo wp_json_encode(array(
    'orderReferenceId' => $order_ref,
    'customerEmail' => $customer_email,
    'signatureId' => $signature_id,
    'amount' => $amount,
    'cohortId' => $cohort_id,
)); ?>
</script>

<?php
get_footer();
