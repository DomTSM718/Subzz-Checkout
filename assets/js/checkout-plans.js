// H5 (2026-08-26): verbose tracing only when SUBZZ_DEBUG is on (window.subzzDebug is printed by the plugin in wp_head). console.warn/error stay live.
var subzzLog = (typeof window !== "undefined" && window.subzzDebug) ? console.log.bind(console) : function () {};

/**
 * Subzz Checkout Plans — Card-Based Layout (Figma Redesign)
 *
 * IIFE pattern. Uses WooCommerce variation prices (already calculated by product sync)
 * and validates against customer affordability from Azure API.
 *
 * Expects window.subzzCheckout to be set by the template.
 */
(function ($) {
    'use strict';

    // Guard against multiple initializations
    if (window._subzzCheckoutPlansInitialized) {
        console.warn('SUBZZ CHECKOUT: Already initialized, skipping');
        return;
    }
    window._subzzCheckoutPlansInitialized = true;

    // -- State ----------------------------------------------------------------
    var state = {
        planCards: [],           // built from variation data + affordability
        selectedPlan: null,      // currently selected plan card object
        selectedTerm: null,      // 12 | 18 | 24
        initialPayment: 0,
        billingDay: null,
        submitting: false,
        overlimitLogged: false,  // P4: gate the over-limit-shown analytics beacon to once per page
        upliftAvailable: false,  // P4: bankLinkUpliftAvailable — drives both the CTA and the "raise your limit" copy
        maxAffordable: 0,        // from affordability check
        availableBudget: 0,
        // Discount state
        discountCode: '',
        discountPercentage: 0,
        discountValid: false,
        discountDescription: ''
    };

    var cfg = window.subzzCheckout;
    if (!cfg || !cfg.ajaxUrl || !cfg.customerEmail) {
        console.error('SUBZZ CHECKOUT: Missing subzzCheckout config');
        return;
    }

    subzzLog('SUBZZ CHECKOUT: Initialising — email:', cfg.customerEmail, 'variations:', cfg.variationPlans);

    // -- Helpers --------------------------------------------------------------
    function formatZAR(amount) {
        return 'R ' + Math.ceil(parseFloat(amount)).toLocaleString('en-ZA');
    }

    function showSection(id) {
        var el = document.getElementById(id);
        if (el) el.style.display = '';
    }

    function hideSection(id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    }

    // -- 1. Fetch affordability then show cards --------------------------------
    function fetchAffordabilityAndInit() {
        subzzLog('SUBZZ CHECKOUT: Fetching affordability');

        $.ajax({
            url: cfg.ajaxUrl,
            method: 'POST',
            data: {
                action: 'subzz_get_plan_cards',
                nonce: cfg.nonce,
                email: cfg.customerEmail,
                product_price_incl_vat: cfg.productPriceInclVat
            },
            success: function (resp) {
                // Clear the initial-load throbber the moment a response arrives — each branch below
                // renders its own state (cards / F&F gate / not-verified / error).
                hideSection('initial-loading');
                if (resp.success && resp.data) {
                    subzzLog('SUBZZ CHECKOUT: Affordability received', resp.data);

                    // Plan 4 (2026-05-02): F&F gate check — server returns gateBlocked:true
                    // with a reason-specific message when the customer is not eligible to
                    // proceed through checkout. Reuses the #not-verified-message surface
                    // with swapped copy so customers see a clear blocking state.
                    if (resp.data.gateBlocked === true) {
                        subzzLog('SUBZZ CHECKOUT: F&F gate blocked', resp.data.gateBlockedReason);
                        $('#not-verified-message h3').text('Account Not Eligible');
                        $('#not-verified-message p').text(
                            resp.data.gateBlockedMessage ||
                            'Your account is not currently eligible for checkout.'
                        );
                        $('#not-verified-message a.btn-primary')
                            .attr('href', '/contact/')
                            .text('Contact Us');
                        showSection('not-verified-message');
                        return;
                    }

                    if (resp.data.isVerified === false) {
                        showSection('not-verified-message');
                        return;
                    }

                    var pc = resp.data.planCards || {};
                    state.maxAffordable = pc.maxAffordable || 0;
                    state.availableBudget = pc.availableBudget || 0;

                    buildPlanCards();
                    initAfterVerification();

                    // P4 over-limit redesign: reveal the always-on spending-limit status panel for
                    // every verified customer (green within-limit / red gap, recomputes live).
                    showSection('limit-status-panel');

                    // Band→Checkout: the in-panel "Increase my limit" CTA AND the "raise your limit" message
                    // clause show ONLY when the customer's limit is their band cap with no bank linked yet
                    // (backend-gated flag). A bank-linked customer can't raise their limit further, so both
                    // the CTA and the clause are suppressed for them.
                    state.upliftAvailable = resp.data.bankLinkUpliftAvailable === true;
                    if (state.upliftAvailable) {
                        showSection('banklink-upsell-cta');
                    }
                } else {
                    console.error('SUBZZ CHECKOUT: Affordability error', resp);
                    showSection('plan-error');
                }
            },
            error: function (xhr, status, err) {
                hideSection('initial-loading');
                console.error('SUBZZ CHECKOUT: AJAX error', status, err);
                showSection('plan-error');
            },
            complete: function () {
                // PS-3 (2026-06-09): hide the initial-load throbber once the affordability fetch
                // settles — fires on success OR error, so it covers every outcome (plan cards,
                // not-verified, gate-blocked, plan-error). Slow-connection CX guard.
                // 2026-06-20 merge: repointed 'loading-plans' → 'initial-loading' (the surviving
                // band→checkout throbber div) so this safety-net no-op is gone and complete still guards.
                hideSection('initial-loading');
            }
        });
    }

    // -- 2. Build plan cards from variation prices + affordability -------------
    function buildPlanCards() {
        var plans = cfg.variationPlans || [];
        var budget = state.availableBudget;
        var cards = [];

        plans.forEach(function (v) {
            var monthly = v.monthlyAmount;
            var card = {
                termMonths: v.termMonths,
                monthlyAmount: monthly,
                standardMonthlyAmount: monthly,
                variationId: v.variationId,
                isViable: monthly <= budget,
                isRecommended: false,
                requiresInitialPayment: false,
                initialPaymentAmount: 0
            };

            // If over budget, check if initial payment can bring it within range
            // Initial payment must be >= 1 month (first month paid upfront)
            if (!card.isViable) {
                var totalValue = monthly * v.termMonths;
                var remainingMonths = v.termMonths - 1;
                // Required initial = amount needed so remaining monthly fits budget
                var requiredInitial = totalValue - (budget * remainingMonths);
                requiredInitial = Math.max(Math.ceil(requiredInitial), Math.ceil(monthly)); // At least 1 month
                var maxAllowedInitial = totalValue * 0.5;

                if (requiredInitial <= maxAllowedInitial && remainingMonths > 0) {
                    var reducedMonthly = Math.ceil((totalValue - requiredInitial) / remainingMonths);
                    if (reducedMonthly <= budget) {
                        card.isViable = true;
                        card.requiresInitialPayment = true;
                        card.initialPaymentAmount = requiredInitial;
                        card.monthlyAmount = reducedMonthly;
                    }
                }
            }

            cards.push(card);
        });

        // Recommend: first viable without initial payment, else first viable with
        var recIdx = -1;
        var noInitial = cards.findIndex(function (c) { return c.isViable && !c.requiresInitialPayment; });
        if (noInitial >= 0) {
            recIdx = noInitial;
        } else {
            var withInitial = cards.findIndex(function (c) { return c.isViable; });
            if (withInitial >= 0) recIdx = withInitial;
        }
        if (recIdx >= 0) cards[recIdx].isRecommended = true;

        state.planCards = cards;
        subzzLog('SUBZZ CHECKOUT: Built plan cards', cards);
    }

    // -- 3. Show all cards after verification ---------------------------------
    function initAfterVerification() {
        // Show all card sections
        showSection('product-details-card');
        showSection('customise-card');
        showSection('address-card');
        showSection('coupon-card');
        showSection('summary-card');
        showSection('continue-section');

        // Render product attributes
        renderProductAttributes();

        // P4: per-term monthly under each term button + initial paint of the status panel
        renderPerTermMonthly();
        updateLimitPanel();

        // P4: ALWAYS select a term so the panel + levers are live — the cart-chosen term first
        // (cfg.selectedTerm, picked on the product page), else 18mo, else the first available. No longer
        // requires the term to be viable; over-limit just shows a red gap and Continue stays disabled
        // until a lever (longer term / bigger upfront / raise limit) brings the monthly within budget.
        // The old "no subscription plans within your budget" dead-end is removed (that was the empty
        // over-limit state P4 fixes).
        var targetTerm = cfg.selectedTerm || 18;
        var match = state.planCards.find(function (c) { return c.termMonths === targetTerm; });
        if (!match) {
            match = state.planCards[0];
        }

        if (match) {
            selectTerm(match.termMonths);
        }

        validateForm();
    }

    // -- 4. Render product attributes -----------------------------------------
    function renderProductAttributes() {
        var attrs = cfg.variationAttributes || {};
        var keys = Object.keys(attrs);
        if (keys.length === 0) return;

        var html = '';
        keys.forEach(function (key, i) {
            if (i > 0) html += '<span class="attr-sep">|</span>';
            html += '<span class="attr-bold">' + key + ':</span> ' + attrs[key];
        });

        $('#product-attributes').html(html);
        $('#summary-attributes').html(html);
    }

    // -- 5. Term selection ----------------------------------------------------
    function selectTerm(term) {
        // P4: ANY term is selectable now, even when over-limit — this is the one real behaviour change.
        // The status panel shows the live gap, and Continue stays gated on within-limit (validateForm),
        // so a selectable-but-over plan can never check out. Removing the isViable filter is what
        // un-freezes the term lever + lets the gap recompute; everything else is additive.
        var match = state.planCards.find(function (c) { return c.termMonths === term; });
        if (!match) return;

        state.selectedPlan = match;
        state.selectedTerm = term;
        state.initialPayment = match.initialPaymentAmount || 0;

        // Update term button active state
        $('#term-buttons .term-btn').removeClass('active');
        $('#term-buttons .term-btn[data-term="' + term + '"]').addClass('active');

        subzzLog('SUBZZ CHECKOUT: Selected term', term, 'months');

        updateSlider(match);
        updatePaymentDisplay();
        updateOrderSummary();
        validateForm();
    }

    // -- 6. Slider management -------------------------------------------------
    // RULE: Upfront = first month + optional extra. Min = 1 month's standard payment.
    // Extra above the minimum reduces the remaining (termMonths - 1) monthly payments.
    function updateSlider(card) {
        var totalValue = card.standardMonthlyAmount * card.termMonths;
        var maxInitial = Math.floor(totalValue * 0.5);
        var minInitial = Math.ceil(card.standardMonthlyAmount); // Can't go below 1 month

        var $slider = $('#initial-payment-range');
        $slider.attr('min', minInitial);
        $slider.attr('max', maxInitial);

        // Default to minimum (first month's payment)
        var defaultVal = card.requiresInitialPayment
            ? Math.max(card.initialPaymentAmount, minInitial)
            : minInitial;
        $slider.val(state.initialPayment >= minInitial ? state.initialPayment : defaultVal);
        state.initialPayment = parseFloat($slider.val());

        $('#slider-min-label').text(formatZAR(minInitial));
        $('#slider-max-label').text(formatZAR(maxInitial));
        updateSliderGradient();
    }

    function updateSliderGradient() {
        var $slider = $('#initial-payment-range');
        var min = parseFloat($slider.attr('min')) || 0;
        var max = parseFloat($slider.attr('max')) || 1;
        var val = parseFloat($slider.val()) || 0;
        var pct = max > min ? ((val - min) / (max - min)) * 100 : 0;
        $slider.css('background', 'linear-gradient(to right, #2A8BEA 0%, #2A8BEA ' + pct + '%, #E0E0E0 ' + pct + '%, #E0E0E0 100%)');
    }

    // -- 7. Payment display ---------------------------------------------------
    function updatePaymentDisplay() {
        if (!state.selectedPlan) return;

        var totalValue = state.selectedPlan.standardMonthlyAmount * state.selectedTerm;
        var remainingMonths = state.selectedTerm - 1;
        var monthly = remainingMonths > 0
            ? Math.ceil((totalValue - state.initialPayment) / remainingMonths)
            : 0;
        if (monthly < 0) monthly = 0;

        $('#display-upfront').text(formatZAR(state.initialPayment));
        $('#display-monthly').text(formatZAR(monthly));

        // P4: keep the live status panel in sync (this path fires on every term + slider change)
        updateLimitPanel();
    }

    // -- 7b. P4 over-limit status panel + per-term monthly --------------------
    // Always-on awareness hub. "This plan vs Your limit" + bar + gap message, recomputing live off
    // the current selected plan + deposit. Limit = state.availableBudget (the number viability is
    // actually checked against). ADDITIVE — display only; no decision/limit logic lives here.
    function renderPerTermMonthly() {
        (state.planCards || []).forEach(function (card) {
            $('#term-monthly-' + card.termMonths).text(formatZAR(card.standardMonthlyAmount) + '/mo');
        });
    }

    // Live monthly for the currently selected plan + deposit (mirrors updatePaymentDisplay's math).
    function currentMonthly() {
        if (!state.selectedPlan) return 0;
        var totalValue = state.selectedPlan.standardMonthlyAmount * state.selectedTerm;
        var remainingMonths = state.selectedTerm - 1;
        var m = remainingMonths > 0 ? Math.ceil((totalValue - state.initialPayment) / remainingMonths) : 0;
        return m < 0 ? 0 : m;
    }

    // True when the live monthly is within the customer's available budget (the Continue gate too).
    function isWithinLimit() {
        return currentMonthly() <= (state.availableBudget || 0);
    }

    function updateLimitPanel() {
        var limit = state.availableBudget || 0;
        var monthly = currentMonthly();
        var withinLimit = monthly <= limit;

        $('#limit-status-plan-amount').text(formatZAR(monthly));
        $('#limit-status-cap-amount').text(formatZAR(limit));

        // Bar: over → scale by monthly (limit marker sits at limit/monthly); within → scale by limit
        // (fill shows how much of the limit this plan uses).
        var scaleMax = (withinLimit ? limit : monthly) || 1;
        var fillPct = (withinLimit ? monthly : limit) / scaleMax * 100;
        var overPct = withinLimit ? 0 : (monthly - limit) / scaleMax * 100;
        var markPct = limit / scaleMax * 100;
        $('#limit-bar-fill').css('width', fillPct + '%');
        $('#limit-bar-over').css({ left: fillPct + '%', width: overPct + '%' });
        $('#limit-bar-mark').css('left', markPct + '%');

        $('#limit-status-panel')
            .toggleClass('within-limit', withinLimit)
            .toggleClass('over-limit', !withinLimit);

        if (!state.selectedPlan) {
            $('#limit-status-msg-main').text('');
            $('#limit-status-msg-sub').html('');
        } else if (!withinLimit) {
            $('#limit-status-msg-main').text("You're " + formatZAR(monthly - limit) + "/mo over your limit.");
            // Only offer "raise your limit" when the bank-link uplift is actually available (band, no bank
            // linked). A bank-linked customer can't raise it further — don't dangle a route they can't take.
            var overSub = 'Bring it down with a <b>longer term</b> or a <b>bigger upfront</b> below';
            overSub += state.upliftAvailable ? ' — or <b>raise your limit</b>:' : '.';
            $('#limit-status-msg-sub').html(overSub);
        } else {
            $('#limit-status-msg-main').text('✓ This plan is within your limit.');
            $('#limit-status-msg-sub').html('');
        }

        // Analytics-First: log the FIRST time this customer is shown the over-limit state (once/page).
        if (state.selectedPlan && !withinLimit && !state.overlimitLogged) {
            state.overlimitLogged = true;
            logOverlimitShown();
        }
    }

    // Fire-and-forget over-limit-shown beacon (never blocks the UI). Backend writes a
    // checkout_overlimit_shown PaymentLogs row (see ajax_log_overlimit_shown in class-payment-handler).
    function logOverlimitShown() {
        $.ajax({
            url: cfg.ajaxUrl,
            method: 'POST',
            data: {
                action: 'subzz_log_overlimit_shown',
                nonce: cfg.nonce,
                limit: state.availableBudget,
                monthly: currentMonthly(),
                product_name: cfg.productName || ''
            }
        });
    }

    // -- 8. Order summary -----------------------------------------------------
    function updateOrderSummary() {
        if (!state.selectedPlan) return;

        var plan = state.selectedPlan;
        var totalValue = plan.standardMonthlyAmount * plan.termMonths;
        var remainingMonths = plan.termMonths - 1;
        var monthly = remainingMonths > 0
            ? Math.ceil((totalValue - state.initialPayment) / remainingMonths)
            : 0;
        if (monthly < 0) monthly = 0;

        // Term display
        $('#summary-term').text(plan.termMonths + '-month subscription');

        // Due today is always the upfront amount (which is >= 1 month)
        $('#summary-due-today').text(formatZAR(state.initialPayment));

        // Remaining monthly payments
        $('#summary-monthly-label').text('Monthly Payment (' + remainingMonths + ' months):');
        $('#summary-monthly').text(formatZAR(monthly));
    }

    // -- 9. Billing date selection --------------------------------------------
    function initBillingDateButtons() {
        $('#billing-buttons').on('click', '.billing-btn', function () {
            var day = parseInt($(this).data('day'));
            state.billingDay = day;

            $('#billing-buttons .billing-btn').removeClass('active');
            $(this).addClass('active');

            touchedFields.billingDay = true;
            validateForm();
        });
    }

    // -- 10. Address validation -----------------------------------------------
    var touchedFields = {};

    function initAddressValidation() {
        $('#address-street, #address-city, #address-postal').on('input change', function () {
            validateForm();
        });

        $('#address-street').on('blur', function () {
            touchedFields.street = true;
            validateField('street');
            validateForm();
        });
        $('#address-city').on('blur', function () {
            touchedFields.city = true;
            validateField('city');
            validateForm();
        });
        $('#address-postal').on('blur', function () {
            touchedFields.postal = true;
            validateField('postal');
            validateForm();
        });

        $('#address-province').on('change', function () {
            touchedFields.province = true;
            validateField('province');
            validateForm();
        });
    }

    function validateField(fieldName) {
        var isValid = true;
        var $wrapper, $error;

        switch (fieldName) {
            case 'street':
                $wrapper = $('#field-street');
                $error = $('#error-street');
                isValid = $('#address-street').val().trim().length > 0;
                break;
            case 'city':
                $wrapper = $('#field-city');
                $error = $('#error-city');
                isValid = $('#address-city').val().trim().length > 0;
                break;
            case 'province':
                $wrapper = $('#field-province');
                $error = $('#error-province');
                isValid = !!$('#address-province').val();
                break;
            case 'postal':
                $wrapper = $('#field-postal');
                $error = $('#error-postal');
                var val = $('#address-postal').val().trim();
                isValid = /^\d{4}$/.test(val);
                if (val.length > 0 && !isValid) {
                    $error.text('Enter a 4-digit postal code');
                } else if (val.length === 0) {
                    $error.text('Please enter your postal code');
                }
                break;
        }

        if ($wrapper && $error) {
            if (touchedFields[fieldName] && !isValid) {
                $wrapper.addClass('field-error');
                $error.addClass('visible');
            } else {
                $wrapper.removeClass('field-error');
                $error.removeClass('visible');
            }
        }

        return isValid;
    }

    function isAddressValid() {
        var street = $('#address-street').val().trim();
        var city = $('#address-city').val().trim();
        var province = $('#address-province').val();
        var postal = $('#address-postal').val().trim();
        return street.length > 0 && city.length > 0 && !!province && /^\d{4}$/.test(postal);
    }

    // -- 11. Form validation --------------------------------------------------
    function validateForm() {
        var addressOk = isAddressValid();
        var billingOk = !!state.billingDay;
        // P4 SAFETY BELT: now that any term is selectable, Continue must also require the live monthly
        // to be within the customer's available budget — otherwise an over-limit plan could check out.
        var withinLimit = isWithinLimit();
        var valid = state.selectedPlan && withinLimit && addressOk && billingOk;

        $('#btn-continue').prop('disabled', !valid);

        // Update billing day error (only if user has interacted)
        if (touchedFields.billingDay && !billingOk) {
            $('#error-billing-day').addClass('visible');
        } else {
            $('#error-billing-day').removeClass('visible');
        }

        // Update form status hint
        var $status = $('#form-status');
        var $announcer = $('#form-announcer');
        if (state.selectedPlan && !valid) {
            var hints = [];
            if (!withinLimit) hints.push(state.upliftAvailable
                ? 'bring your monthly within your limit (a longer term, a bigger upfront, or raise your limit above)'
                : 'bring your monthly within your limit (try a longer term or a bigger upfront)');
            if (!addressOk) hints.push('complete your address');
            if (!billingOk) hints.push('select a billing date');
            var msg = 'Please ' + hints.join(' and ') + ' to continue';
            $status.text(msg);
            $announcer.text(msg);
        } else {
            $status.text('');
        }

        return valid;
    }

    function showAllFieldErrors() {
        touchedFields.street = true;
        touchedFields.city = true;
        touchedFields.province = true;
        touchedFields.postal = true;
        touchedFields.billingDay = true;

        validateField('street');
        validateField('city');
        validateField('province');
        validateField('postal');
        validateForm();

        var firstError = document.querySelector('.field-error');
        if (firstError) {
            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstError.classList.add('shake');
            firstError.addEventListener('animationend', function () {
                firstError.classList.remove('shake');
            }, { once: true });
        }
    }

    function showErrorBar(message) {
        var $bar = $('#checkout-error-bar');
        $bar.text(message).addClass('visible');
        setTimeout(function () { $bar.removeClass('visible'); }, 8000);
    }

    // -- 12. Continue -> store order ------------------------------------------
    function handleContinue() {
        if (state.submitting) return;
        if (!validateForm()) {
            showAllFieldErrors();
            return;
        }
        state.submitting = true;

        var $btn = $('#btn-continue');
        $btn.prop('disabled', true).text('Processing...');
        $('#continue-section').hide();
        $('#loading-checkout').show();

        var plan = state.selectedPlan;
        var totalValue = plan.standardMonthlyAmount * plan.termMonths;
        var remainingMonths = plan.termMonths - 1;
        var reducedMonthly = remainingMonths > 0
            ? Math.ceil((totalValue - state.initialPayment) / remainingMonths)
            : 0;

        var orderData = {
            action: 'subzz_store_checkout_order',
            nonce: cfg.nonce,
            customer_email: cfg.customerEmail,
            product_id: cfg.productId,
            variation_id: plan.variationId || cfg.variationId,
            product_name: cfg.productName,
            product_price_incl_vat: plan.standardMonthlyAmount,
            selected_term_months: plan.termMonths,
            standard_monthly_amount: plan.standardMonthlyAmount,
            initial_payment_amount: state.initialPayment,
            reduced_monthly_amount: reducedMonthly,
            total_subscription_value: totalValue,
            billing_day: state.billingDay,
            address_street: $('#address-street').val().trim(),
            address_city: $('#address-city').val().trim(),
            address_province: $('#address-province').val(),
            address_postal: $('#address-postal').val().trim(),
            discount_code: state.discountValid ? state.discountCode : '',
            discount_percentage: state.discountValid ? state.discountPercentage : 0,
            pre_discount_monthly_amount: state.discountValid && plan.preDiscountMonthlyAmount ? plan.preDiscountMonthlyAmount : 0,
            product_attributes: JSON.stringify(cfg.variationAttributes || {})
        };

        subzzLog('SUBZZ CHECKOUT: Storing order', orderData);

        $.ajax({
            url: cfg.ajaxUrl,
            method: 'POST',
            data: orderData,
            timeout: 30000,
            success: function (resp) {
                if (resp.success && resp.data && resp.data.signature_url) {
                    subzzLog('SUBZZ CHECKOUT: Order stored, redirecting to contract');
                    var url = resp.data.signature_url;
                    if (state.billingDay) {
                        url += (url.indexOf('?') !== -1 ? '&' : '?') + 'billing_day=' + state.billingDay;
                    }
                    window.location.href = url;
                } else {
                    console.error('SUBZZ CHECKOUT: Store order failed', resp);
                    showErrorBar(resp.data && resp.data.message ? resp.data.message : 'Failed to process order. Please try again.');
                    state.submitting = false;
                    $('#loading-checkout').hide();
                    $('#continue-section').show();
                    $btn.prop('disabled', false).text('Continue to Contract');
                }
            },
            error: function (xhr, status, err) {
                console.error('SUBZZ CHECKOUT: AJAX error', status, err);
                showErrorBar('Network error. Please check your connection and try again.');
                state.submitting = false;
                $('#loading-checkout').hide();
                $('#continue-section').show();
                $btn.prop('disabled', false).text('Continue to Contract');
            }
        });
    }

    // -- 13. Coupon/discount code ---------------------------------------------
    function initCouponHandler() {
        $('#btn-apply-coupon').on('click', function () {
            var code = $('#coupon-code').val().trim();
            if (!code) {
                showCouponMessage('Please enter a coupon code', 'error');
                return;
            }
            applyCoupon(code);
        });

        // Allow Enter key in coupon input
        $('#coupon-code').on('keypress', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#btn-apply-coupon').click();
            }
        });
    }

    function applyCoupon(code) {
        var $btn = $('#btn-apply-coupon');
        $btn.prop('disabled', true).text('Checking...');

        $.ajax({
            url: cfg.ajaxUrl,
            method: 'POST',
            data: {
                action: 'subzz_validate_coupon',
                nonce: cfg.nonce,
                coupon_code: code
            },
            success: function (resp) {
                if (resp.success && resp.data && resp.data.valid) {
                    state.discountCode = resp.data.code;
                    state.discountPercentage = parseFloat(resp.data.discountPercentage);
                    state.discountValid = true;
                    state.discountDescription = resp.data.description || '';

                    showCouponMessage(state.discountPercentage + '% discount applied (' + state.discountDescription + ')', 'success');
                    $('#coupon-code').prop('disabled', true);
                    $btn.text('Applied').prop('disabled', true);

                    subzzLog('SUBZZ CHECKOUT: Coupon applied -', state.discountCode, state.discountPercentage + '%');

                    // Recalculate plan cards with discount
                    applyDiscountToPlanCards();
                } else {
                    var reason = (resp.data && resp.data.reason) ? resp.data.reason : 'Invalid code';
                    showCouponMessage(reason, 'error');
                    $btn.prop('disabled', false).text('Apply');
                }
            },
            error: function () {
                showCouponMessage('Unable to validate code. Please try again.', 'error');
                $btn.prop('disabled', false).text('Apply');
            }
        });
    }

    function showCouponMessage(message, type) {
        var $msg = $('#coupon-message');
        $msg.text(message)
            .removeClass('coupon-success coupon-error')
            .addClass(type === 'success' ? 'coupon-success' : 'coupon-error')
            .show();
    }

    function applyDiscountToPlanCards() {
        if (!state.discountValid || state.discountPercentage <= 0) return;

        var multiplier = 1 - (state.discountPercentage / 100);

        state.planCards.forEach(function (card) {
            // Store pre-discount amount
            card.preDiscountMonthlyAmount = card.standardMonthlyAmount;
            // Apply discount to monthly and recalculate
            card.standardMonthlyAmount = Math.ceil(card.standardMonthlyAmount * multiplier);
            card.monthlyAmount = card.standardMonthlyAmount;
        });

        // Re-evaluate affordability with discounted prices
        var budget = state.availableBudget;
        state.planCards.forEach(function (card) {
            card.isViable = card.monthlyAmount <= budget;
            card.requiresInitialPayment = false;
            card.initialPaymentAmount = 0;

            if (!card.isViable) {
                var totalValue = card.standardMonthlyAmount * card.termMonths;
                var remainingMonths = card.termMonths - 1;
                var requiredInitial = totalValue - (budget * remainingMonths);
                requiredInitial = Math.max(Math.ceil(requiredInitial), Math.ceil(card.standardMonthlyAmount));
                var maxAllowedInitial = totalValue * 0.5;

                if (requiredInitial <= maxAllowedInitial && remainingMonths > 0) {
                    var reducedMonthly = Math.ceil((totalValue - requiredInitial) / remainingMonths);
                    if (reducedMonthly <= budget) {
                        card.isViable = true;
                        card.requiresInitialPayment = true;
                        card.initialPaymentAmount = requiredInitial;
                        card.monthlyAmount = reducedMonthly;
                    }
                }
            }
        });

        // P4: refresh per-term monthly labels with the discounted amounts
        renderPerTermMonthly();

        // Re-select current term to refresh UI
        if (state.selectedTerm) {
            selectTerm(state.selectedTerm);
        }
    }

    // -- Band→Checkout Phase 2d: bank-link uplift CTA -------------------------
    // Mints a hand-off code server-side (email never leaves the server) and redirects into the
    // signup SPA's bank-link flow. return_url is this page; the SPA validates it against its
    // shop-origin allowlist (and the handler also constrains it to this site).
    function handleBankLinkUpsell() {
        var $btn = $('#btn-banklink-upsell');
        if ($btn.prop('disabled')) return;
        // Flip to the blue .active state on click (per step-2 CSS) before going in-flight.
        $btn.addClass('active').prop('disabled', true).text('Starting…');
        $('#banklink-upsell-error').removeClass('visible').text('');

        $.ajax({
            url: cfg.ajaxUrl,
            method: 'POST',
            data: {
                action: 'subzz_banklink_handoff',
                nonce: cfg.nonce,
                return_url: window.location.href
            },
            success: function (resp) {
                if (resp.success && resp.data && resp.data.redirectUrl) {
                    window.location.href = resp.data.redirectUrl;
                } else {
                    var msg = (resp.data && resp.data.message) ? resp.data.message : 'Unable to start bank linking. Please try again.';
                    $('#banklink-upsell-error').text(msg).addClass('visible');
                    $btn.removeClass('active').prop('disabled', false).text('Increase my limit');
                }
            },
            error: function () {
                $('#banklink-upsell-error').text('Network error. Please try again.').addClass('visible');
                $btn.removeClass('active').prop('disabled', false).text('Increase my limit');
            }
        });
    }

    // -- Init -----------------------------------------------------------------
    $(document).ready(function () {
        fetchAffordabilityAndInit();
        initAddressValidation();
        initBillingDateButtons();
        initCouponHandler();

        // Bank-link uplift CTA (Phase 2d)
        $('#btn-banklink-upsell').on('click', handleBankLinkUpsell);

        // Term button clicks
        $('#term-buttons').on('click', '.term-btn', function () {
            var term = parseInt($(this).data('term'));
            selectTerm(term);
        });

        // Slider input
        $('#initial-payment-range').on('input', function () {
            state.initialPayment = parseFloat(this.value);
            updateSliderGradient();
            updatePaymentDisplay();
            updateOrderSummary();
        });

        // Continue button
        $('#btn-continue').on('click', handleContinue);

        // Retry button
        $('#retry-plans').on('click', function () {
            hideSection('plan-error');
            fetchAffordabilityAndInit();
        });
    });

})(jQuery);
