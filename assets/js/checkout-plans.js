/**
 * Subzz Checkout Plans — Single-Page Checkout JavaScript
 *
 * IIFE pattern. Uses WooCommerce variation prices (already calculated by product sync)
 * and validates against customer affordability from Azure API.
 *
 * Expects window.subzzCheckout to be set by the template.
 */
(function ($) {
    'use strict';

    // ── State ──────────────────────────────────────────────────────────
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

    // ── Helpers ────────────────────────────────────────────────────────
    function formatZAR(amount) {
        return 'R ' + parseFloat(amount).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function showSection(id) {
        var el = document.getElementById(id);
        if (el) el.style.display = '';
    }

    function hideSection(id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    }

    // ── 1. Fetch affordability then build cards ────────────────────────
    function fetchAffordabilityAndBuildCards() {
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
                        hideSection('plan-cards-container');
                        showSection('not-verified-message');
                        return;
                    }

                    // Plan cards response nests budget under planCards object
                    var pc = resp.data.planCards || {};
                    state.maxAffordable = pc.maxAffordable || 0;
                    state.availableBudget = pc.availableBudget || 0;

                    buildPlanCards();
                } else {
                    console.error('SUBZZ CHECKOUT: Affordability error', resp);
                    hideSection('plan-cards-container');
                    showSection('plan-error');
                }
            },
            error: function (xhr, status, err) {
                console.error('SUBZZ CHECKOUT: AJAX error', status, err);
                hideSection('plan-cards-container');
                showSection('plan-error');
            }
        });
    }

    // ── 2. Build plan cards from variation prices + affordability ──────
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
            if (!card.isViable) {
                var totalValue = monthly * v.termMonths;
                var requiredInitial = totalValue - (budget * v.termMonths);
                requiredInitial = Math.round(requiredInitial * 100) / 100;
                var maxAllowedInitial = totalValue * 0.5;

                if (requiredInitial >= 1000 && requiredInitial <= maxAllowedInitial) {
                    card.isViable = true;
                    card.requiresInitialPayment = true;
                    card.initialPaymentAmount = requiredInitial;
                    card.monthlyAmount = Math.round((totalValue - requiredInitial) / v.termMonths * 100) / 100;
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
        renderPlanCards(cards);
    }

    // ── 3. Render plan cards ───────────────────────────────────────────
    function renderPlanCards(cards) {
        var container = document.getElementById('plan-cards-container');
        container.innerHTML = '';

        var viableCards = cards.filter(function (c) { return c.isViable; });

        if (viableCards.length === 0) {
            container.innerHTML = '<p class="no-plans">No subscription plans available within your budget for this product.</p>';
            return;
        }

        cards.forEach(function (card) {
            if (!card.isViable) return;

            var el = document.createElement('div');
            el.className = 'plan-card' + (card.isRecommended ? ' recommended' : '');
            el.setAttribute('data-term', card.termMonths);

            var badge = card.isRecommended
                ? '<span class="card-badge">Recommended</span>'
                : '';

            var initialLine = '';
            if (card.requiresInitialPayment && card.initialPaymentAmount > 0) {
                initialLine = '<div class="card-detail card-initial">Initial payment: <strong>' +
                    formatZAR(card.initialPaymentAmount) + '</strong></div>';
            }

            el.innerHTML =
                badge +
                '<h3 class="card-term">' + card.termMonths + ' months</h3>' +
                '<div class="card-price">' + formatZAR(card.monthlyAmount) + '<span>/month</span></div>' +
                initialLine;

            el.addEventListener('click', function () {
                selectPlan(card, el);
            });

            container.appendChild(el);
        });
    }

    // ── 4. Plan card selection ─────────────────────────────────────────
    function selectPlan(card, el) {
        var allCards = document.querySelectorAll('.plan-card');
        allCards.forEach(function (c) { c.classList.remove('selected'); });
        el.classList.add('selected');

        state.selectedPlan = card;
        state.selectedTerm = card.termMonths;
        state.initialPayment = card.initialPaymentAmount || 0;

        console.log('SUBZZ CHECKOUT: Selected plan', card.termMonths, 'months');

        showSection('customise-section');
        showSection('address-section');
        showSection('billing-date-section');
        showSection('continue-section');

        updateCustomisePanel(card);
        updateSummary();
        validateForm();
    }

    // ── 5. Customise panel ─────────────────────────────────────────────
    function initCustomisePanel() {
        var $toggle = $('#toggle-customise');
        var $panel = $('#customise-panel');

        $toggle.on('click', function () {
            var isOpen = $panel.is(':visible');
            $panel.slideToggle(200);
            $toggle.find('.toggle-icon').text(isOpen ? '+' : '-');
        });

        // Term buttons
        $('#term-buttons').on('click', '.term-btn', function () {
            var term = parseInt($(this).data('term'));
            $('#term-buttons .term-btn').removeClass('active');
            $(this).addClass('active');

            var match = state.planCards.find(function (c) { return c.termMonths === term && c.isViable; });
            if (match) {
                state.selectedPlan = match;
                state.selectedTerm = term;

                var allCards = document.querySelectorAll('.plan-card');
                allCards.forEach(function (c) {
                    c.classList.toggle('selected', parseInt(c.getAttribute('data-term')) === term);
                });

                updateCustomisePanel(match);
                updateSummary();
                validateForm();
            }
        });

        // Initial payment slider
        var $slider = $('#initial-payment-range');
        $slider.on('input', function () {
            state.initialPayment = parseFloat(this.value);
            $('#slider-value').text(formatZAR(state.initialPayment));
            updateMonthlyPreview();
            updateSummary();
        });
    }

    function updateCustomisePanel(card) {
        $('#term-buttons .term-btn').removeClass('active');
        $('#term-buttons .term-btn[data-term="' + card.termMonths + '"]').addClass('active');

        // Slider: initial payment range
        var totalValue = card.standardMonthlyAmount * card.termMonths;
        var maxInitial = Math.floor(totalValue * 0.5);
        // Only enforce R1,000 minimum when plan requires initial payment
        var minInitial = card.requiresInitialPayment ? 1000 : 0;

        var $slider = $('#initial-payment-range');
        $slider.attr('min', minInitial);
        $slider.attr('max', maxInitial);

        // Reset initial payment to 0 when plan doesn't require it
        if (!card.requiresInitialPayment) {
            state.initialPayment = 0;
        }
        $slider.val(state.initialPayment || card.initialPaymentAmount || minInitial);

        state.initialPayment = parseFloat($slider.val());

        $('#slider-value').text(formatZAR(state.initialPayment));
        $('#slider-max-label').text(formatZAR(maxInitial));

        updateMonthlyPreview();
    }

    function updateMonthlyPreview() {
        if (!state.selectedPlan) return;
        var totalValue = state.selectedPlan.standardMonthlyAmount * state.selectedTerm;
        var reduced = (totalValue - state.initialPayment) / state.selectedTerm;
        if (reduced < 0) reduced = 0;
        $('#monthly-preview-amount').text(formatZAR(reduced));
    }

    // ── 6. Address validation ──────────────────────────────────────────
    function initAddressValidation() {
        $('#address-street, #address-city, #address-postal').on('input change', function () {
            validateForm();
        });
        $('input[name="address_province"]').on('change', function () {
            validateForm();
        });
    }

    function isAddressValid() {
        var street = $('#address-street').val().trim();
        var city = $('#address-city').val().trim();
        var province = $('input[name="address_province"]:checked').val();
        var postal = $('#address-postal').val().trim();
        return street.length > 0 && city.length > 0 && !!province && postal.length === 4;
    }

    // ── 7. Billing date selection ──────────────────────────────────────
    function initBillingDateSelection() {
        $('input[name="billing_day"]').on('change', function () {
            state.billingDay = parseInt(this.value);
            $('.billing-date-option').removeClass('selected');
            $(this).closest('.billing-date-option').addClass('selected');
            validateForm();
        });
    }

    // ── 8. Form validation ─────────────────────────────────────────────
    function validateForm() {
        var valid = state.selectedPlan && isAddressValid() && state.billingDay;
        $('#btn-continue').prop('disabled', !valid);
        return valid;
    }

    // ── 9. Summary bar ─────────────────────────────────────────────────
    function updateSummary() {
        if (!state.selectedPlan) return;
        var plan = state.selectedPlan;
        $('#summary-plan').text(plan.termMonths + ' months');

        var totalValue = plan.standardMonthlyAmount * plan.termMonths;
        var monthly = state.initialPayment > 0
            ? (totalValue - state.initialPayment) / plan.termMonths
            : plan.standardMonthlyAmount;
        if (monthly < 0) monthly = 0;

        var text = formatZAR(monthly) + '/mo';
        if (state.initialPayment > 0) {
            text += ' + ' + formatZAR(state.initialPayment) + ' upfront';
        }
        $('#summary-monthly').text(text);
    }

    // ── 10. Continue → store order ─────────────────────────────────────
    function handleContinue() {
        if (state.submitting || !validateForm()) return;
        state.submitting = true;

        var $btn = $('#btn-continue');
        $btn.prop('disabled', true).text('Processing...');

        var plan = state.selectedPlan;
        var totalValue = plan.standardMonthlyAmount * plan.termMonths;
        var reducedMonthly = state.initialPayment > 0
            ? (totalValue - state.initialPayment) / plan.termMonths
            : plan.standardMonthlyAmount;

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
            address_province: $('input[name="address_province"]:checked').val(),
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
                    alert(resp.data && resp.data.message ? resp.data.message : 'Failed to process order. Please try again.');
                    state.submitting = false;
                    $btn.prop('disabled', false).text('Continue to Contract');
                }
            },
            error: function (xhr, status, err) {
                console.error('SUBZZ CHECKOUT: AJAX error', status, err);
                alert('Network error. Please check your connection and try again.');
                state.submitting = false;
                $btn.prop('disabled', false).text('Continue to Contract');
            }
        });
    }

    // ── Init ───────────────────────────────────────────────────────────
    $(document).ready(function () {
        fetchAffordabilityAndBuildCards();
        initCustomisePanel();
        initAddressValidation();
        initBillingDateSelection();

        $('#btn-continue').on('click', handleContinue);

        $('#retry-plans').on('click', function () {
            hideSection('plan-error');
            showSection('plan-cards-container');
            document.getElementById('plan-cards-container').innerHTML =
                '<div class="plan-card skeleton"><div class="skeleton-badge"></div><div class="skeleton-title"></div><div class="skeleton-price"></div><div class="skeleton-detail"></div></div>'.repeat(3);
            fetchAffordabilityAndBuildCards();
        });
    });

})(jQuery);
