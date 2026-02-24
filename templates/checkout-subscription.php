<?php
/**
 * Checkout Subscription Template — Single-Page Plan Selection
 *
 * URL: /checkout-subscription/
 * Flow: Cart → THIS PAGE → /contract-signature/ → LekkaPay → /payment-success/
 *
 * Requires: logged-in user with subscription product in cart.
 * Plan cards loaded via AJAX from Azure API.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get logged-in customer email
$current_user = wp_get_current_user();
$customer_email = $current_user->user_email;

error_log('SUBZZ CHECKOUT SUBSCRIPTION: Customer email: ' . $customer_email);

// Extract cart data — find first subscription product
$product_name = '';
$product_price_incl_vat = 0;
$product_image_url = '';
$product_id = 0;
$variation_id = 0;
$selected_term = 0;

foreach (WC()->cart->get_cart() as $cart_item) {
    $pid = $cart_item['product_id'];
    $subscription_enabled = get_post_meta($pid, '_subzz_subscription_enabled', true);

    if ($subscription_enabled === 'yes') {
        $product = $cart_item['data'];
        $product_id = $pid;
        $variation_id = $cart_item['variation_id'] ?? 0;
        $product_name = $product->get_name();
        $product_price_incl_vat = (float) $product->get_price();
        $image_id = $product->get_image_id();
        $product_image_url = $image_id ? wp_get_attachment_image_url($image_id, 'medium') : wc_placeholder_img_src('medium');

        // Extract term from variation name (e.g. "... - 18 Month Subscription")
        if ($variation_id && preg_match('/(\d+)\s*Month/i', $product_name, $m)) {
            $selected_term = (int) $m[1];
        }
        break;
    }
}

// Get all subscription variation prices for this product (12m, 18m, 24m)
$variation_plans = array();
if ($product_id) {
    $parent_product = wc_get_product($product_id);
    if ($parent_product && $parent_product->is_type('variable')) {
        foreach ($parent_product->get_available_variations() as $var) {
            $var_name = $var['variation_description'] ?? '';
            $var_obj = wc_get_product($var['variation_id']);
            if ($var_obj) {
                $var_title = $var_obj->get_name();
                if (preg_match('/(\d+)\s*Month/i', $var_title, $m)) {
                    $term = (int) $m[1];
                    $variation_plans[] = array(
                        'termMonths'    => $term,
                        'monthlyAmount' => (float) $var_obj->get_price(),
                        'variationId'   => $var['variation_id'],
                    );
                }
            }
        }
        // Sort by term ascending (12, 18, 24)
        usort($variation_plans, function ($a, $b) { return $a['termMonths'] - $b['termMonths']; });

        // Use parent product name (without variation suffix) for display
        $product_name = $parent_product->get_name();
    }
}

error_log('SUBZZ CHECKOUT: Variation plans: ' . json_encode($variation_plans));

if (!$product_price_incl_vat) {
    error_log('SUBZZ CHECKOUT SUBSCRIPTION: No subscription product found in cart — redirecting');
    wp_redirect(wc_get_checkout_url());
    exit;
}

error_log('SUBZZ CHECKOUT SUBSCRIPTION: Product: ' . $product_name . ' | Price incl VAT: R' . $product_price_incl_vat);

get_header();
?>

<div class="subzz-checkout-page">
    <div class="checkout-container">

        <!-- Progress indicator -->
        <div class="checkout-progress">
            <div class="progress-step active">
                <span class="step-dot">1</span>
                <span class="step-label">Choose Plan</span>
            </div>
            <div class="progress-line"></div>
            <div class="progress-step">
                <span class="step-dot">2</span>
                <span class="step-label">Contract</span>
            </div>
            <div class="progress-line"></div>
            <div class="progress-step">
                <span class="step-dot">3</span>
                <span class="step-label">Payment</span>
            </div>
            <div class="progress-line"></div>
            <div class="progress-step">
                <span class="step-dot">4</span>
                <span class="step-label">Complete</span>
            </div>
        </div>

        <!-- Product summary -->
        <section class="product-summary">
            <div class="product-image">
                <img src="<?php echo esc_url($product_image_url); ?>" alt="<?php echo esc_attr($product_name); ?>">
            </div>
            <div class="product-info">
                <h2><?php echo esc_html($product_name); ?></h2>
            </div>
        </section>

        <!-- Plan cards container (populated via AJAX) -->
        <section class="plan-cards-section">
            <h2>Choose Your Subscription Plan</h2>

            <div id="plan-cards-container" class="plan-cards-grid">
                <!-- Loading skeleton -->
                <div class="plan-card skeleton">
                    <div class="skeleton-badge"></div>
                    <div class="skeleton-title"></div>
                    <div class="skeleton-price"></div>
                    <div class="skeleton-detail"></div>
                    <div class="skeleton-detail"></div>
                </div>
                <div class="plan-card skeleton">
                    <div class="skeleton-badge"></div>
                    <div class="skeleton-title"></div>
                    <div class="skeleton-price"></div>
                    <div class="skeleton-detail"></div>
                    <div class="skeleton-detail"></div>
                </div>
                <div class="plan-card skeleton">
                    <div class="skeleton-badge"></div>
                    <div class="skeleton-title"></div>
                    <div class="skeleton-price"></div>
                    <div class="skeleton-detail"></div>
                    <div class="skeleton-detail"></div>
                </div>
            </div>

            <div id="plan-error" class="plan-error" style="display:none;">
                <p>Unable to load subscription plans. Please try again.</p>
                <button id="retry-plans" class="btn-secondary">Retry</button>
            </div>

            <div id="not-verified-message" class="not-verified" style="display:none;">
                <h3>Verification Required</h3>
                <p>You need to complete identity verification before subscribing.</p>
                <a href="<?php echo esc_url(home_url('/signup/')); ?>" class="btn-primary">Complete Verification</a>
            </div>
        </section>

        <!-- Customise panel (hidden by default) -->
        <section id="customise-section" class="customise-section" style="display:none;">
            <button type="button" id="toggle-customise" class="customise-toggle">
                <span class="toggle-icon">+</span> Customise your plan
            </button>

            <div id="customise-panel" class="customise-panel" style="display:none;">
                <div class="term-toggle">
                    <label>Subscription Term:</label>
                    <div class="term-buttons" id="term-buttons">
                        <button type="button" class="term-btn" data-term="12">12 months</button>
                        <button type="button" class="term-btn" data-term="18">18 months</button>
                        <button type="button" class="term-btn" data-term="24">24 months</button>
                    </div>
                </div>

                <div class="initial-payment-slider">
                    <label for="initial-payment-range">Initial Payment: <strong id="slider-value">R 0</strong></label>
                    <input type="range" id="initial-payment-range" min="0" max="0" step="100" value="0">
                    <div class="slider-range">
                        <span>R 0</span>
                        <span id="slider-max-label">R 0</span>
                    </div>
                </div>

                <div class="monthly-preview">
                    <p>Monthly payment: <strong id="monthly-preview-amount">R 0.00</strong></p>
                </div>
            </div>
        </section>

        <!-- Delivery address -->
        <section id="address-section" class="address-section" style="display:none;">
            <h2>Delivery Address</h2>
            <div class="address-grid">
                <div class="form-field full-width">
                    <label for="address-street">Street Address *</label>
                    <input type="text" id="address-street" name="address_street" required placeholder="e.g. 123 Main Road">
                </div>
                <div class="form-field">
                    <label for="address-city">City *</label>
                    <input type="text" id="address-city" name="address_city" required placeholder="e.g. Cape Town">
                </div>
                <div class="form-field">
                    <label>Province *</label>
                    <div class="province-options" id="province-options">
                        <label class="province-option"><input type="radio" name="address_province" value="EC"><span>Eastern Cape</span></label>
                        <label class="province-option"><input type="radio" name="address_province" value="FS"><span>Free State</span></label>
                        <label class="province-option"><input type="radio" name="address_province" value="GP"><span>Gauteng</span></label>
                        <label class="province-option"><input type="radio" name="address_province" value="KZN"><span>KwaZulu-Natal</span></label>
                        <label class="province-option"><input type="radio" name="address_province" value="LP"><span>Limpopo</span></label>
                        <label class="province-option"><input type="radio" name="address_province" value="MP"><span>Mpumalanga</span></label>
                        <label class="province-option"><input type="radio" name="address_province" value="NC"><span>Northern Cape</span></label>
                        <label class="province-option"><input type="radio" name="address_province" value="NW"><span>North West</span></label>
                        <label class="province-option"><input type="radio" name="address_province" value="WC"><span>Western Cape</span></label>
                    </div>
                </div>
                <div class="form-field">
                    <label for="address-postal">Postal Code *</label>
                    <input type="text" id="address-postal" name="address_postal" required placeholder="e.g. 8001" maxlength="4" pattern="[0-9]{4}">
                </div>
            </div>
        </section>

        <!-- Billing date -->
        <section id="billing-date-section" class="billing-date-section" style="display:none;">
            <h2>Preferred Billing Date</h2>
            <p class="section-hint">Choose the day of the month for your recurring subscription payment.</p>
            <div class="billing-date-options">
                <label class="billing-date-option">
                    <input type="radio" name="billing_day" value="1">
                    <span class="date-label">1st</span>
                </label>
                <label class="billing-date-option">
                    <input type="radio" name="billing_day" value="8">
                    <span class="date-label">8th</span>
                </label>
                <label class="billing-date-option">
                    <input type="radio" name="billing_day" value="15">
                    <span class="date-label">15th</span>
                </label>
                <label class="billing-date-option">
                    <input type="radio" name="billing_day" value="22">
                    <span class="date-label">22nd</span>
                </label>
            </div>
        </section>

        <!-- Continue button -->
        <section id="continue-section" class="continue-section" style="display:none;">
            <div class="order-summary-bar">
                <div class="summary-left">
                    <span id="summary-product"><?php echo esc_html($product_name); ?></span>
                    <span id="summary-plan" class="summary-plan"></span>
                </div>
                <div class="summary-right">
                    <span id="summary-monthly" class="summary-monthly"></span>
                </div>
            </div>

            <button type="button" id="btn-continue" class="btn-primary btn-continue" disabled>
                Continue to Contract
            </button>

            <p class="continue-hint">You'll review and sign your subscription agreement next.</p>
        </section>

        <!-- Back to cart -->
        <div class="back-link">
            <a href="<?php echo esc_url(wc_get_cart_url()); ?>">&larr; Back to cart</a>
        </div>

    </div>
</div>

<script>
    // Pass data to checkout-plans.js
    window.subzzCheckout = {
        ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>',
        nonce: '<?php echo wp_create_nonce('subzz_checkout_subscription'); ?>',
        customerEmail: '<?php echo esc_js($customer_email); ?>',
        productPriceInclVat: <?php echo (float) $product_price_incl_vat; ?>,
        productName: '<?php echo esc_js($product_name); ?>',
        productImageUrl: '<?php echo esc_js($product_image_url); ?>',
        productId: <?php echo (int) $product_id; ?>,
        variationId: <?php echo (int) $variation_id; ?>,
        selectedTerm: <?php echo (int) $selected_term; ?>,
        variationPlans: <?php echo wp_json_encode($variation_plans); ?>,
        cartUrl: '<?php echo esc_js(wc_get_cart_url()); ?>',
        currency: 'ZAR'
    };
</script>

<?php
get_footer();
?>
