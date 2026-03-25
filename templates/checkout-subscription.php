<?php
/**
 * Checkout Subscription Template — Card-Based Layout (Figma Redesign)
 *
 * URL: /checkout-subscription/
 * Flow: Cart -> THIS PAGE -> /contract-signature/ -> LekkaPay -> /payment-success/
 *
 * Requires: logged-in user with subscription product in cart.
 * Affordability checked via AJAX from Azure API.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get logged-in customer email
$current_user = wp_get_current_user();
$customer_email = $current_user->user_email;

subzz_log('SUBZZ CHECKOUT SUBSCRIPTION: Customer email: ' . $customer_email);

// Extract cart data — find first subscription product
$product_name = '';
$product_price_incl_vat = 0;
$product_image_url = '';
$product_id = 0;
$variation_id = 0;
$selected_term = 0;

// Extract variation attributes for display
$variation_attributes = array();

// All Subzz products are subscription products — no per-product meta check needed.
foreach (WC()->cart->get_cart() as $cart_item) {
    $pid = $cart_item['product_id'];
    $product = $cart_item['data'];
    $product_id = $pid;
    $variation_id = $cart_item['variation_id'] ?? 0;
    $product_name = $product->get_name();
    $product_price_incl_vat = (float) $product->get_price();
    $image_id = $product->get_image_id();
    $product_image_url = $image_id ? wp_get_attachment_image_url($image_id, 'medium') : wc_placeholder_img_src('medium');

    subzz_log('SUBZZ CHECKOUT CART DEBUG: pid=' . $pid . ' variation_id=' . $variation_id . ' name=' . $product_name . ' price=' . $product->get_price() . ' type=' . $product->get_type());

    // Extract term from variation name (e.g. "... - 18 Month Subscription")
    if ($variation_id && preg_match('/(\d+)\s*Month/i', $product_name, $m)) {
        $selected_term = (int) $m[1];
    }

    // Extract variation attributes for display (Hand, Shaft Flex, Loft, etc.)
    if (isset($cart_item['variation']) && !empty($cart_item['variation'])) {
        foreach ($cart_item['variation'] as $attr_key => $attr_value) {
            $attr_name = wc_attribute_label(str_replace('attribute_', '', $attr_key));
            // Skip Duration attribute (shown separately as term)
            if (strtolower($attr_name) === 'duration') continue;
            if (!empty($attr_value)) {
                $variation_attributes[$attr_name] = $attr_value;
            }
        }
    }
    break;
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

subzz_log('SUBZZ CHECKOUT: Variation plans: ' . json_encode($variation_plans));

if (!$product_price_incl_vat) {
    subzz_log('SUBZZ CHECKOUT SUBSCRIPTION: No subscription product found in cart — redirecting');
    wp_redirect(wc_get_checkout_url());
    exit;
}

subzz_log('SUBZZ CHECKOUT SUBSCRIPTION: Product: ' . $product_name . ' | Price incl VAT: R' . $product_price_incl_vat);

get_header();
?>

<div class="subzz-checkout-header">
    <a href="<?php echo esc_url(home_url('/')); ?>">
        <img src="<?php echo esc_url(plugin_dir_url(dirname(__FILE__)) . 'assets/img/logo-white.png'); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>">
    </a>
</div>

<div class="subzz-checkout-page">
    <div class="checkout-container">

        <!-- Back to cart link (top) -->
        <a href="<?php echo esc_url(wc_get_cart_url()); ?>" class="back-to-cart-link">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M10 12L6 8L10 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Back to cart
        </a>

        <!-- Step indicator card -->
        <div class="checkout-card">
            <div class="checkout-progress">
                <div class="progress-step active">
                    <span class="step-dot">1</span>
                    <span class="step-label">Plan</span>
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
        </div>

        <!-- Not-verified message (shown when customer hasn't completed KYC) -->
        <div id="not-verified-message" class="not-verified" style="display:none;">
            <h3>Verification Required</h3>
            <p>You need to complete identity verification before subscribing.</p>
            <a href="<?php echo esc_url(home_url('/signup/')); ?>" class="btn-primary">Complete Verification</a>
        </div>

        <!-- Affordability error (shown when AJAX fails) -->
        <div id="plan-error" class="plan-error" style="display:none;">
            <p>Unable to load subscription information. Please try again.</p>
            <button id="retry-plans" class="btn-secondary">Retry</button>
        </div>

        <!-- Product Details card -->
        <section id="product-details-card" class="checkout-card" style="display:none;">
            <h2 class="card-heading">Product Details</h2>
            <div class="product-details-row">
                <div class="product-thumb">
                    <img src="<?php echo esc_url($product_image_url); ?>" alt="<?php echo esc_attr($product_name); ?>">
                </div>
                <div class="product-meta">
                    <div class="product-meta-name"><?php echo esc_html($product_name); ?></div>
                    <div class="product-meta-attrs" id="product-attributes"></div>
                </div>
            </div>
        </section>

        <!-- Customise Your Subscription card -->
        <section id="customise-card" class="checkout-card" style="display:none;">
            <h2 class="card-heading">Customise Your Subscription</h2>

            <!-- Term buttons -->
            <div class="term-toggle">
                <label class="field-label">Subscription Term:</label>
                <div class="term-buttons" id="term-buttons">
                    <button type="button" class="term-btn" data-term="12">12 months</button>
                    <button type="button" class="term-btn" data-term="18">18 months</button>
                    <button type="button" class="term-btn" data-term="24">24 months</button>
                </div>
            </div>

            <!-- Deposit slider -->
            <div class="deposit-slider">
                <label class="field-label">Reduce Your Monthly Payment</label>
                <p class="field-hint">Pay an optional amount upfront and watch your monthly payment drop.</p>
                <input type="range" id="initial-payment-range" min="0" max="0" step="100" value="0">
                <div class="slider-range">
                    <span>R 0</span>
                    <span id="slider-max-label">R 0</span>
                </div>
            </div>

            <!-- Payment display box -->
            <div class="payment-display-box">
                <div class="payment-display-row">
                    <span class="payment-display-label">Upfront Amount:</span>
                    <span class="payment-display-value" id="display-upfront">R 0</span>
                </div>
                <div class="payment-display-row payment-display-primary">
                    <span class="payment-display-label">Monthly Payment:</span>
                    <span class="payment-amount-large" id="display-monthly">R 0</span>
                </div>
            </div>

            <!-- Billing date buttons (moved here from separate section) -->
            <div class="billing-date-toggle">
                <label class="field-label">Preferred Billing Date <span class="required-asterisk">*</span></label>
                <p class="field-hint">Choose the day of the month for your recurring subscription payment.</p>
                <div class="billing-buttons" id="billing-buttons">
                    <button type="button" class="billing-btn" data-day="1">1st</button>
                    <button type="button" class="billing-btn" data-day="8">8th</button>
                    <button type="button" class="billing-btn" data-day="15">15th</button>
                    <button type="button" class="billing-btn" data-day="22">22nd</button>
                </div>
                <span class="field-error-message" id="error-billing-day">Please select a billing date</span>
            </div>
        </section>

        <!-- Delivery Address card -->
        <section id="address-card" class="checkout-card" style="display:none;">
            <h2 class="card-heading">Delivery Address</h2>
            <div class="address-grid">
                <div class="form-field full-width" id="field-street">
                    <label for="address-street">Street Address <span class="required-asterisk">*</span></label>
                    <input type="text" id="address-street" name="address_street" required placeholder="e.g. 123 Main Road">
                    <span class="field-error-message" id="error-street">Please enter your street address</span>
                </div>
                <div class="form-field" id="field-city">
                    <label for="address-city">City <span class="required-asterisk">*</span></label>
                    <input type="text" id="address-city" name="address_city" required placeholder="e.g. Cape Town">
                    <span class="field-error-message" id="error-city">Please enter your city</span>
                </div>
                <div class="form-field" id="field-province">
                    <label for="address-province">Province <span class="required-asterisk">*</span></label>
                    <select id="address-province" name="address_province" required>
                        <option value="">Select a province</option>
                        <option value="EC">Eastern Cape</option>
                        <option value="FS">Free State</option>
                        <option value="GP">Gauteng</option>
                        <option value="KZN">KwaZulu-Natal</option>
                        <option value="LP">Limpopo</option>
                        <option value="MP">Mpumalanga</option>
                        <option value="NC">Northern Cape</option>
                        <option value="NW">North West</option>
                        <option value="WC">Western Cape</option>
                    </select>
                    <span class="field-error-message" id="error-province">Please select a province</span>
                </div>
                <div class="form-field" id="field-postal">
                    <label for="address-postal">Postal Code <span class="required-asterisk">*</span></label>
                    <input type="text" id="address-postal" name="address_postal" required placeholder="e.g. 8001" maxlength="4" pattern="[0-9]{4}">
                    <span class="field-error-message" id="error-postal">Enter a 4-digit postal code</span>
                </div>
            </div>
        </section>

        <!-- Order Summary card -->
        <section id="summary-card" class="checkout-card" style="display:none;">
            <h2 class="card-heading">Order Summary</h2>

            <div class="summary-product-row">
                <div class="summary-product-thumb">
                    <img src="<?php echo esc_url($product_image_url); ?>" alt="<?php echo esc_attr($product_name); ?>">
                </div>
                <div class="summary-product-meta">
                    <div class="summary-product-name"><?php echo esc_html($product_name); ?></div>
                    <div class="summary-product-attrs" id="summary-attributes"></div>
                    <div class="summary-product-term" id="summary-term"></div>
                </div>
            </div>

            <div class="summary-totals">
                <div class="summary-line">
                    <span>Due Today:</span>
                    <strong id="summary-due-today">R 0</strong>
                </div>
                <div class="summary-line summary-line-primary">
                    <span id="summary-monthly-label">Monthly Payment:</span>
                    <strong id="summary-monthly" class="summary-monthly-value">R 0</strong>
                </div>
            </div>
        </section>

        <!-- Continue button section -->
        <section id="continue-section" class="continue-section" style="display:none;">
            <button type="button" id="btn-continue" class="btn-primary btn-continue" disabled>
                Continue to Contract
            </button>

            <div class="form-status" id="form-status"></div>
            <div class="subzz-error-bar" id="checkout-error-bar"></div>

            <p class="continue-hint">You'll review and sign your subscription agreement next.</p>
        </section>

        <!-- Screen reader announcements -->
        <div class="sr-only" aria-live="polite" id="form-announcer"></div>

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
        variationAttributes: <?php echo wp_json_encode($variation_attributes); ?>,
        cartUrl: '<?php echo esc_js(wc_get_cart_url()); ?>',
        currency: 'ZAR'
    };
</script>

<?php
get_footer();
?>
