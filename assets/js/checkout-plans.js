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
        maxAffordable: 0,        // from affordability check
        availableBudget: 0
    };

    var cfg = window.subzzCheckout;
    if (!cfg || !cfg.ajaxUrl || !cfg.customerEmail) {
        console.error('SUBZZ CHECKOUT: Missing subzzCheckout config');
        return;
    }

    console.log('SUBZZ CHECKOUT: Initialising — email:', cfg.customerEmail, 'variations:', cfg.variationPlans);

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
        console.log('SUBZZ CHECKOUT: Fetching affordability');

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
                if (resp.success && resp.data) {
                    console.log('SUBZZ CHECKOUT: Affordability received', resp.data);

                    if (resp.data.isVerified === false) {
                        showSection('not-verified-message');
                        return;
                    }

                    var pc = resp.data.planCards || {};
                    state.maxAffordable = pc.maxAffordable || 0;
                    state.availableBudget = pc.availableBudget || 0;

                    buildPlanCards();
                    initAfterVerification();
                } else {
                    console.error('SUBZZ CHECKOUT: Affordability error', resp);
                    showSection('plan-error');
                }
            },
            error: function (xhr, status, err) {
                console.error('SUBZZ CHECKOUT: AJAX error', status, err);
                showSection('plan-error');
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
        console.log('SUBZZ CHECKOUT: Built plan cards', cards);
    }

    // -- 3. Show all cards after verification ---------------------------------
    function initAfterVerification() {
        // Show all card sections
        showSection('product-details-card');
        showSection('customise-card');
        showSection('address-card');
        showSection('summary-card');
        showSection('continue-section');

        // Render product attributes
        renderProductAttributes();

        // Auto-select term: from cart first, or default to 18m, or first viable
        var targetTerm = cfg.selectedTerm || 18;
        var match = state.planCards.find(function (c) { return c.termMonths === targetTerm && c.isViable; });
        if (!match) {
            match = state.planCards.find(function (c) { return c.isViable; });
        }

        if (match) {
            selectTerm(match.termMonths);
        } else {
            // No viable plans — show message in customise card
            $('#customise-card .card-heading').after(
                '<p class="no-plans-msg" style="text-align:center;color:rgba(84,84,84,0.6);padding:20px;">No subscription plans available within your budget for this product.</p>'
            );
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
        var match = state.planCards.find(function (c) { return c.termMonths === term && c.isViable; });
        if (!match) return;

        state.selectedPlan = match;
        state.selectedTerm = term;
        state.initialPayment = match.initialPaymentAmount || 0;

        // Update term button active state
        $('#term-buttons .term-btn').removeClass('active');
        $('#term-buttons .term-btn[data-term="' + term + '"]').addClass('active');

        console.log('SUBZZ CHECKOUT: Selected term', term, 'months');

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
        var valid = state.selectedPlan && addressOk && billingOk;

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
            address_postal: $('#address-postal').val().trim()
        };

        console.log('SUBZZ CHECKOUT: Storing order', orderData);

        $.ajax({
            url: cfg.ajaxUrl,
            method: 'POST',
            data: orderData,
            timeout: 30000,
            success: function (resp) {
                if (resp.success && resp.data && resp.data.signature_url) {
                    console.log('SUBZZ CHECKOUT: Order stored, redirecting to contract');
                    var url = resp.data.signature_url;
                    if (state.billingDay) {
                        url += (url.indexOf('?') !== -1 ? '&' : '?') + 'billing_day=' + state.billingDay;
                    }
                    window.location.href = url;
                } else {
                    console.error('SUBZZ CHECKOUT: Store order failed', resp);
                    showErrorBar(resp.data && resp.data.message ? resp.data.message : 'Failed to process order. Please try again.');
                    state.submitting = false;
                    $btn.prop('disabled', false).text('Continue to Contract');
                }
            },
            error: function (xhr, status, err) {
                console.error('SUBZZ CHECKOUT: AJAX error', status, err);
                showErrorBar('Network error. Please check your connection and try again.');
                state.submitting = false;
                $btn.prop('disabled', false).text('Continue to Contract');
            }
        });
    }

    // -- Init -----------------------------------------------------------------
    $(document).ready(function () {
        fetchAffordabilityAndInit();
        initAddressValidation();
        initBillingDateButtons();

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
