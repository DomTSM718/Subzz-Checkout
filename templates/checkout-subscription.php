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
            // Skip duration-related attributes (shown separately as term selector)
            $attr_lower = strtolower($attr_name);
            if ($attr_lower === 'duration' || strpos($attr_lower, 'duration') !== false || strpos($attr_lower, 'subscription') !== false) continue;
            if (!empty($attr_value)) {
                $variation_attributes[$attr_name] = $attr_value;
            }
        }
    }

    // Merge non-variation attribute tile selections (Hand, Flex, Loft, Bounce, etc.)
    // These are captured by Subzz_Product_Attribute_Tiles via woocommerce_add_cart_item_data
    if (isset($cart_item['subzz_attributes']) && !empty($cart_item['subzz_attributes'])) {
        foreach ($cart_item['subzz_attributes'] as $attr_slug => $attr_value) {
            $attr_name = wc_attribute_label($attr_slug);
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

        <!-- Initial-load throbber (Dom 2026-06-02): visible on page load while affordability is fetched
             (the cart→plan-detail gap). checkout-plans.js hideSection('initial-loading') clears it as soon
             as the response resolves (cards / gate / not-verified / error). Reuses .loading-state +
             .loading-spinner from contract-styles.css (already enqueued for #loading-checkout). -->
        <div id="initial-loading" class="loading-state">
            <div class="loading-spinner"></div>
            <p>Loading your plans…</p>
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

        <!-- Product Details card (matches Zane's Figma) -->
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

        <!-- NOTE: the standalone Phase-2d "Increase Your Spending Limit" card was absorbed into the
             P4 over-limit status panel inside the Customise card below (Zane-approved 2026-06-03). The
             #btn-banklink-upsell button + its mint handler are reused verbatim from there. -->

        <!-- Customise Your Subscription card -->
        <section id="customise-card" class="checkout-card" style="display:none;">
            <h2 class="card-heading">Customise Your Subscription</h2>

            <!-- P4 over-limit redesign (Zane-approved 2026-06-03): always-on spending-limit status panel
                 (awareness hub). Live "This plan vs Your limit" + bar + gap message, recomputing on every
                 term/deposit change (checkout-plans.js). Red gap when over, green "within your limit" when
                 in budget. The "Increase my limit" CTA lives inside (#banklink-upsell-cta) and is shown ONLY
                 when bankLinkUpliftAvailable===true (band cap, no bank linked) — it absorbs the old standalone
                 #banklink-upsell-card. ADDITIVE: the only behaviour change in this card is that all terms are
                 now selectable even when over-limit (see selectTerm); Continue still gates on within-limit. -->
            <div id="limit-status-panel" class="limit-status-panel" style="display:none;">
                <div class="limit-status-row">
                    <span class="limit-status-plan">This plan: <strong id="limit-status-plan-amount">R 0</strong>/mo</span>
                    <span class="limit-status-cap">Your limit: <strong id="limit-status-cap-amount">R 0</strong>/mo</span>
                </div>
                <div class="limit-bar" id="limit-status-bar">
                    <div class="limit-bar-fill" id="limit-bar-fill"></div>
                    <div class="limit-bar-over" id="limit-bar-over"></div>
                    <div class="limit-bar-mark" id="limit-bar-mark"></div>
                </div>
                <div class="limit-status-msg" id="limit-status-msg">
                    <span class="limit-status-msg-main" id="limit-status-msg-main"></span>
                    <span class="limit-status-msg-sub" id="limit-status-msg-sub"></span>
                </div>
                <div class="limit-cta" id="banklink-upsell-cta" style="display:none;">
                    <button type="button" id="btn-banklink-upsell">Increase my limit</button>
                    <div class="limit-cta-trust">🔒 2-min, read-only bank check · we never store your login</div>
                    <div class="subzz-error-bar" id="banklink-upsell-error"></div>
                </div>
            </div>

            <!-- Term buttons (+ P4 per-term monthly under each label) -->
            <div class="term-toggle">
                <label class="field-label">Subscription Term:</label>
                <div class="term-buttons" id="term-buttons">
                    <button type="button" class="term-btn" data-term="12"><span class="term-btn-label">12 months</span><span class="term-btn-monthly" id="term-monthly-12"></span></button>
                    <button type="button" class="term-btn" data-term="18"><span class="term-btn-label">18 months</span><span class="term-btn-monthly" id="term-monthly-18"></span></button>
                    <button type="button" class="term-btn" data-term="24"><span class="term-btn-label">24 months</span><span class="term-btn-monthly" id="term-monthly-24"></span></button>
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

        <!-- Coupon Code card -->
        <section id="coupon-card" class="checkout-card" style="display:none;">
            <h2 class="card-heading">Have a Coupon Code?</h2>
            <div class="coupon-input-row">
                <input type="text" id="coupon-code" name="coupon_code" placeholder="Enter code (e.g. FNF10)" maxlength="50" autocomplete="off">
                <button type="button" id="btn-apply-coupon" class="btn-secondary">Apply</button>
            </div>
            <div id="coupon-message" class="coupon-message"></div>
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

        <!-- Loading State (order storage / redirect to contract) — modal variant for AJAX-then-navigate race -->
        <div id="loading-checkout" class="loading-state loading-state-modal" style="display: none;">
            <div class="loading-spinner"></div>
            <p>Processing your order...</p>
        </div>

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
