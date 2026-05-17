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
 *   apiUrl - Azure API base (e.g. https://api.subzz.co.za/api)
 *   apiKey - WP API key for the X-Subzz-API-Key header (CHK-002 protection)
 */
(function () {
    'use strict';

    var config = window.subzzPicker || {};
    var apiUrl = config.apiUrl || '';
    var apiKey = config.apiKey || '';

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
        if (orderContext.cohortId) {
            query.set('cohortId', orderContext.cohortId);
        }
        if (orderContext.customerEmail) {
            query.set('customerEmail', orderContext.customerEmail);
        }

        var url = apiUrl + '/payment/vendors' + (query.toString() ? '?' + query.toString() : '');
        var headers = { 'Accept': 'application/json' };
        if (apiKey) {
            headers['X-Subzz-API-Key'] = apiKey;
        }

        fetch(url, { method: 'GET', headers: headers })
            .then(function (res) {
                if (!res.ok) {
                    throw new Error('Vendor lookup failed with HTTP ' + res.status);
                }
                return res.json();
            })
            .then(function (data) {
                availableVendors = (data && data.vendors) || [];
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

        var html = '';
        vendors.forEach(function (vendor, index) {
            var rowId = 'subzz-vendor-' + vendor.vendorId;
            var logoMarkup = vendor.logoUrl
                ? '<img class="subzz-picker-vendor-logo" src="' + escapeHtml(vendor.logoUrl) + '" alt="' + escapeHtml(vendor.displayName) + ' logo">'
                : '<span class="subzz-picker-vendor-logo-fallback" aria-hidden="true"></span>';

            html += '<label class="subzz-picker-vendor" for="' + rowId + '">';
            html += '<input type="radio" name="subzz-picker-vendor" id="' + rowId + '" value="' + escapeHtml(vendor.vendorId) + '"' + (index === 0 ? '' : '') + '>';
            html += logoMarkup;
            html += '<span class="subzz-picker-vendor-name">' + escapeHtml(vendor.displayName) + '</span>';
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
            signatureId: orderContext.signatureId || null,
            amount: orderContext.amount || 0,
            currency: 'ZAR'
        };
        var headers = { 'Content-Type': 'application/json' };
        if (apiKey) {
            headers['X-Subzz-API-Key'] = apiKey;
        }
        return fetch(apiUrl + '/payment/create-session', {
            method: 'POST',
            headers: headers,
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
            elements.submitButton.textContent = 'Continue to payment';
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

    // Bootstrap.
    elements.form.addEventListener('submit', handleSubmit);
    fetchAndRenderVendors();
})();
