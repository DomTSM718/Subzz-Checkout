// H5 (2026-08-26): verbose tracing only when SUBZZ_DEBUG is on (window.subzzDebug is printed by the plugin in wp_head). console.warn/error stay live.
var subzzLog = (typeof window !== "undefined" && window.subzzDebug) ? console.log.bind(console) : function () {};

/**
 * Subzz Phase 5 — Multi-Vendor Payment Picker.
 *
 * On page load:
 *   1. Read order context from inline JSON script tag (populated by PHP from URL params)
 *   2. Call GET /api/payment/vendors?cohortId=...&customerEmail=... (Step 3 endpoint)
 *   3. Render radio buttons + logos + name per vendor (UX-1 branded, secondary weight)
 *
 * On submit:
 *   POST /api/payment/create-session with { vendor, signatureId, amount, ... }
 *   On 200: redirect window.location.href = checkoutUrl
 *   On error: surface error message (Step 7 wires bounded retry + re-pick UX)
 *
 * UX locks honoured (Docs/Planning/Stitch-Parallel-Rail-Plan-2026-05-17.md):
 *   UX-1: render vendor name + logo per row, radio buttons (not big competing buttons)
 *   UX-2: no localStorage persistence - selection state is per-page-load only
 *   UX-4: no per-vendor descriptions, single trust line already in template
 *
 * Configuration injected via wp_localize_script as window.subzzPicker:
 *   ajaxUrl - WP admin-ajax.php; vendors + create-session go through server-side proxies
 *   nonce   - 'subzz_picker' nonce for those proxies (H1, 2026-08-26: the API key is no longer
 *             exposed to the browser)
 */
(function () {
    'use strict';

    var config = window.subzzPicker || {};
    // H1 (2026-08-26): all API traffic goes through WP AJAX proxies; the API key never reaches
    // the browser. ajaxUrl + nonce come from wp_localize_script('subzz-picker').
    var ajaxUrl = config.ajaxUrl || '/wp-admin/admin-ajax.php';
    var ajaxNonce = config.nonce || '';

    var elements = {
        vendorsList: document.getElementById('subzz-picker-vendors'),
        submitButton: document.getElementById('subzz-picker-submit'),
        form: document.getElementById('subzz-picker-form'),
        errorBox: document.getElementById('subzz-picker-error'),
        contextScript: document.getElementById('subzz-picker-order-context')
    };

    if (!elements.vendorsList || !elements.form) {
        // Picker container not present on this page - silently exit (not an error).
        return;
    }

    var orderContext = {};
    try {
        orderContext = JSON.parse(elements.contextScript.textContent || '{}');
    } catch (e) {
        console.error('[subzz-picker] Failed to parse order context JSON', e);
    }

    var selectedVendor = null;
    var availableVendors = [];
    // UX-3 sub-case 3 — if customer returned from a failed vendor HPP, the failedVendor URL
    // param surfaces here (PHP reads ?failedVendor=X). Persists in this module-scope only
    // (UX-2 no localStorage lock); fresh navigation away clears it.
    var lastAttemptedVendor = orderContext.failedVendor || null;

    // UX-3 lock — bounded retry on session-create: 2 retries, 1s + 2s backoff (total 3 attempts).
    // Retryable: HTTP 5xx + network errors. Non-retryable: HTTP 4xx (vendor rejected / customer
    // not found). On retry exhaustion OR non-retryable error -> showRetryScreen with both CTAs.
    var BACKOFF_MS = [0, 1000, 2000]; // delay before attempt N (1-indexed: 0/1/2 -> 0ms/1000ms/2000ms)
    var MAX_ATTEMPTS = BACKOFF_MS.length;

    /**
     * GET /payment/vendors and render the radio group.
     */
    function fetchAndRenderVendors() {
        var query = new URLSearchParams();
        query.set('action', 'subzz_get_payment_vendors');
        query.set('nonce', ajaxNonce);
        if (orderContext.cohortId) {
            query.set('cohortId', orderContext.cohortId);
        }
        if (orderContext.customerEmail) {
            query.set('customerEmail', orderContext.customerEmail);
        }

        var url = ajaxUrl + '?' + query.toString();
        var headers = { 'Accept': 'application/json' };

        fetch(url, { method: 'GET', headers: headers, credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) {
                    throw new Error('Vendor lookup failed with HTTP ' + res.status);
                }
                return res.json();
            })
            .then(function (data) {
                availableVendors = (data && data.vendors) || [];

                // Single-vendor auto-skip: when exactly one gateway is available AND the customer
                // is NOT returning from a failed attempt, the picker is a zero-value stop — create
                // the session and go straight to the gateway. (2+ vendors, or a failed-vendor
                // return, still render the picker so the customer stays in control.)
                if (availableVendors.length === 1 && !lastAttemptedVendor) {
                    showRedirectingState(availableVendors[0]);
                    submitWithRetry(availableVendors[0].vendorId, 1);
                    return;
                }

                renderVendors(availableVendors);
                // UX-3 sub-case 3 mid-flow return: if failedVendor was supplied on page load,
                // show the last-attempt note now that vendors are rendered.
                if (lastAttemptedVendor) {
                    var failed = findVendor(lastAttemptedVendor);
                    showLastAttemptNote((failed && failed.displayName) || lastAttemptedVendor);
                }
            })
            .catch(function (err) {
                console.error('[subzz-picker] Vendor fetch error', err);
                renderEmptyState('We could not load the available payment methods. Please refresh the page or try again later.');
            });
    }

    /**
     * Render vendor radio rows. UX-1 branded with logo + name, secondary visual weight.
     */
    function renderVendors(vendors) {
        if (!vendors || vendors.length === 0) {
            renderEmptyState('No payment methods are available right now. Please try again in a few minutes.');
            return;
        }

        // Card-style glyph (right side) — signals "card payment", colours blue when selected via CSS.
        var glyph = '<svg class="subzz-picker-vendor-glyph" width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2.5"></rect><path d="M2 10h20"></path></svg>';

        var html = '';
        vendors.forEach(function (vendor) {
            var rowId = 'subzz-vendor-' + vendor.vendorId;
            // Identity = real logo image when logoUrl is set, otherwise the vendor name
            // shown ONCE (no logo/name duplication). Slot stays logoUrl-ready for real logos later.
            var identity = vendor.logoUrl
                ? '<img class="subzz-picker-vendor-logo" src="' + escapeHtml(vendor.logoUrl) + '" alt="' + escapeHtml(vendor.displayName) + '">'
                : '<span class="subzz-picker-vendor-name">' + escapeHtml(vendor.displayName) + '</span>';

            html += '<label class="subzz-picker-vendor" for="' + rowId + '">';
            html += '<input type="radio" name="subzz-picker-vendor" id="' + rowId + '" value="' + escapeHtml(vendor.vendorId) + '">';
            html += '<span class="subzz-picker-vendor-text">';
            html += identity;
            html += '<span class="subzz-picker-vendor-sub">Pay securely by card</span>';
            html += '</span>';
            html += glyph;
            html += '</label>';
        });

        elements.vendorsList.innerHTML = html;

        // Wire selection state. UX-2 lock: no auto-select - customer must explicitly pick.
        var radios = elements.vendorsList.querySelectorAll('input[type="radio"]');
        radios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                selectedVendor = radio.value;
                updateSelectionVisuals();
                elements.submitButton.disabled = false;
            });
        });
    }

    function renderEmptyState(message) {
        elements.vendorsList.innerHTML = '<div class="subzz-picker-empty">' + escapeHtml(message) + '</div>';
    }

    /**
     * Single-vendor auto-skip view — replaces the choice UI with a calm "redirecting"
     * message while submitWithRetry creates the session and forwards to the gateway.
     * Hides the submit button + trust line (no choice to make). If the session-create
     * fails, the existing showRetryScreen takes over (Try Again, no switch CTA).
     */
    function showRedirectingState(vendor) {
        var name = (vendor && vendor.displayName) || 'your payment provider';
        elements.vendorsList.innerHTML =
            '<div class="subzz-picker-redirecting" role="status" aria-live="polite">'
            + 'Taking you to ' + escapeHtml(name) + ' to complete payment securely&hellip;'
            + '</div>';
        if (elements.submitButton) elements.submitButton.style.display = 'none';
        var trust = document.querySelector('.subzz-picker-trust');
        if (trust) trust.style.display = 'none';
    }

    function updateSelectionVisuals() {
        var rows = elements.vendorsList.querySelectorAll('.subzz-picker-vendor');
        rows.forEach(function (row) {
            var radio = row.querySelector('input[type="radio"]');
            if (radio && radio.checked) {
                row.classList.add('is-selected');
            } else {
                row.classList.remove('is-selected');
            }
        });
    }

    /**
     * Submit handler — kicks off the bounded-retry sequence (UX-3 lock).
     */
    function handleSubmit(event) {
        event.preventDefault();
        if (!selectedVendor) return;
        submitWithRetry(selectedVendor, 1);
    }

    /**
     * Bounded-retry session-create. Recurses up to MAX_ATTEMPTS times with backoff.
     * Per UX-3: retryable failures (5xx + network) keep trying within budget; non-retryable
     * (4xx) shortcuts straight to the re-pick screen. On retry exhaustion -> re-pick screen.
     */
    function submitWithRetry(vendorId, attempt) {
        setSubmittingState(vendorId, attempt);
        hideError();

        var delay = BACKOFF_MS[attempt - 1] || 0;
        setTimeout(function () {
            sendCreateSession(vendorId)
                .then(function (result) {
                    if (result.ok && result.body && result.body.checkoutUrl) {
                        // Happy path - redirect to vendor HPP.
                        window.location.href = result.body.checkoutUrl;
                        return;
                    }

                    // Non-retryable: 4xx (vendor rejected, customer not found, etc.) - skip to re-pick.
                    var nonRetryable = result.status >= 400 && result.status < 500;
                    if (nonRetryable) {
                        showRetryScreen(
                            vendorId,
                            (result.body && (result.body.error || result.body.message))
                                || 'Payment request was rejected by the provider.'
                        );
                        return;
                    }

                    // Retryable: 5xx or unexpected non-OK. Try again within budget.
                    if (attempt < MAX_ATTEMPTS) {
                        console.warn('[subzz-picker] Attempt ' + attempt + ' failed (HTTP ' + result.status + '), retrying…');
                        submitWithRetry(vendorId, attempt + 1);
                    } else {
                        showRetryScreen(
                            vendorId,
                            (result.body && (result.body.error || result.body.message))
                                || 'We could not reach the payment provider after multiple attempts.'
                        );
                    }
                })
                .catch(function (err) {
                    // Network error / fetch rejection = retryable.
                    console.error('[subzz-picker] Network error attempt ' + attempt, err);
                    if (attempt < MAX_ATTEMPTS) {
                        submitWithRetry(vendorId, attempt + 1);
                    } else {
                        showRetryScreen(
                            vendorId,
                            'We could not reach our payment system. Please check your connection.'
                        );
                    }
                });
        }, delay);
    }

    function sendCreateSession(vendorId) {
        var payload = {
            vendor: vendorId,
            customerEmail: orderContext.customerEmail || '',
            orderReferenceId: orderContext.orderReferenceId || null,
            amount: orderContext.amount || 0,
            currency: 'ZAR'
        };
        // Only include signatureId when we actually have one. The API binds it to a non-nullable
        // System.Guid, so sending an explicit null fails model binding (400 before the controller
        // runs). Omitting it lets the API default to Guid.Empty and skip the signature-based lookup
        // (the customer is resolved by orderReferenceId). Fix, 2026-06-08.
        if (orderContext.signatureId) {
            payload.signatureId = orderContext.signatureId;
        }
        var headers = { 'Content-Type': 'application/json' };
        var url = ajaxUrl + '?action=subzz_create_payment_session&nonce=' + encodeURIComponent(ajaxNonce);
        return fetch(url, {
            method: 'POST',
            headers: headers,
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        }).then(function (res) {
            return res.json().then(
                function (data) { return { status: res.status, ok: res.ok, body: data }; },
                // JSON parse failure (e.g. 502 gateway HTML response) - still surface the status.
                function () { return { status: res.status, ok: res.ok, body: null }; }
            );
        });
    }

    function setSubmittingState(vendorId, attempt) {
        elements.submitButton.disabled = true;
        if (attempt > 1) {
            elements.submitButton.textContent = 'Retrying… (attempt ' + attempt + ' of ' + MAX_ATTEMPTS + ')';
        } else {
            elements.submitButton.textContent = 'Connecting…';
        }
    }

    /**
     * UX-3 re-pick error screen. Hides the picker form, shows the error message + two CTAs:
     *   [Try Again {vendor}] — fresh retry sequence (3 attempts again)
     *   [Use {other vendor} instead] — switches selection to the other available vendor,
     *      re-shows picker pre-selected to the other, with a "Last attempt: X" note above.
     *
     * Single-vendor environments (only LekkaPay enabled, no Stitch) get the Try Again CTA only.
     */
    function showRetryScreen(failedVendorId, errorMessage) {
        lastAttemptedVendor = failedVendorId;
        var failedVendor = findVendor(failedVendorId) || { vendorId: failedVendorId, displayName: failedVendorId };
        var otherVendor = availableVendors.find(function (v) { return v.vendorId !== failedVendorId; });

        var html = '';
        html += '<p class="subzz-picker-error-detail">';
        html += 'We could not reach <strong>' + escapeHtml(failedVendor.displayName) + '</strong> right now.';
        html += '</p>';
        html += '<p class="subzz-picker-error-message">' + escapeHtml(errorMessage) + '</p>';
        html += '<div class="subzz-picker-error-actions">';
        html += '<button type="button" class="btn-primary subzz-picker-retry-cta" data-action="retry">';
        html += 'Try Again with ' + escapeHtml(failedVendor.displayName);
        html += '</button>';
        if (otherVendor) {
            html += '<button type="button" class="subzz-picker-switch-cta" data-action="switch">';
            html += 'Use ' + escapeHtml(otherVendor.displayName) + ' instead';
            html += '</button>';
        }
        html += '</div>';

        elements.errorBox.innerHTML = html;
        elements.errorBox.hidden = false;
        elements.form.hidden = true;

        var retryBtn = elements.errorBox.querySelector('[data-action="retry"]');
        if (retryBtn) {
            retryBtn.addEventListener('click', function () {
                hideError();
                elements.form.hidden = false;
                submitWithRetry(failedVendorId, 1);
            });
        }
        var switchBtn = elements.errorBox.querySelector('[data-action="switch"]');
        if (switchBtn && otherVendor) {
            switchBtn.addEventListener('click', function () {
                hideError();
                elements.form.hidden = false;
                showLastAttemptNote(failedVendor.displayName);
                preSelectVendor(otherVendor.vendorId);
                // Per UX-3 lock: customer in control, no surprise switch -> don't auto-submit.
                // They must click Continue to confirm the new vendor choice.
            });
        }
    }

    function preSelectVendor(vendorId) {
        selectedVendor = vendorId;
        var radio = elements.vendorsList.querySelector('input[type="radio"][value="' + vendorId + '"]');
        if (radio) {
            radio.checked = true;
            updateSelectionVisuals();
            elements.submitButton.disabled = false;
            resetCtaToDefault();
        }
    }

    function showLastAttemptNote(failedVendorDisplayName) {
        var existing = document.getElementById('subzz-picker-last-attempt');
        if (existing) existing.remove();
        var note = document.createElement('p');
        note.id = 'subzz-picker-last-attempt';
        note.className = 'subzz-picker-last-attempt';
        note.textContent = 'Last attempt: ' + failedVendorDisplayName;
        elements.vendorsList.parentNode.insertBefore(note, elements.vendorsList);
    }

    function findVendor(vendorId) {
        return availableVendors.find(function (v) { return v.vendorId === vendorId; });
    }

    function hideError() {
        if (!elements.errorBox) return;
        elements.errorBox.innerHTML = '';
        elements.errorBox.hidden = true;
    }

    function escapeHtml(str) {
        if (str == null) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /**
     * Format the charge amount for display (ZAR; no decimals when whole-rand).
     */
    function formatAmount(value) {
        var n = Number(value) || 0;
        if (n <= 0) return '';
        return 'R' + (n % 1 === 0 ? n.toFixed(0) : n.toFixed(2));
    }

    /**
     * Default CTA label with the amount surfaced at the point of action.
     * Full label on desktop ("Continue to payment · R139"); CSS swaps to the
     * short label ("Pay R139") on narrow screens where the long copy wraps.
     */
    function ctaDefaultHtml() {
        var amt = formatAmount(orderContext.amount);
        if (!amt) return 'Continue to payment';
        return '<span class="subzz-picker-cta-full">Continue to payment <span class="subzz-picker-dot">·</span> </span>'
             + '<span class="subzz-picker-cta-short">Pay </span>'
             + '<span class="subzz-picker-amt">' + escapeHtml(amt) + '</span>';
    }

    function resetCtaToDefault() {
        if (elements.submitButton) {
            elements.submitButton.innerHTML = ctaDefaultHtml();
        }
    }

    // Bootstrap.
    elements.form.addEventListener('submit', handleSubmit);
    resetCtaToDefault();
    fetchAndRenderVendors();
})();
