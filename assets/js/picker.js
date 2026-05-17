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
                var vendors = (data && data.vendors) || [];
                renderVendors(vendors);
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
     * Submit handler — POST /payment/create-session with the chosen vendor.
     * Step 7 wraps this in the bounded-retry + re-pick UX. For Step 6 (happy path):
     * single attempt, redirect on success, surface generic error otherwise.
     */
    function handleSubmit(event) {
        event.preventDefault();

        if (!selectedVendor) {
            return;
        }

        elements.submitButton.disabled = true;
        elements.submitButton.textContent = 'Connecting…';
        hideError();

        var payload = {
            vendor: selectedVendor,
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

        fetch(apiUrl + '/payment/create-session', {
            method: 'POST',
            headers: headers,
            body: JSON.stringify(payload)
        })
            .then(function (res) {
                return res.json().then(function (data) {
                    return { status: res.status, ok: res.ok, body: data };
                });
            })
            .then(function (result) {
                if (result.ok && result.body && result.body.checkoutUrl) {
                    window.location.href = result.body.checkoutUrl;
                    return;
                }
                // Step 7 will branch here on errorCode for retry vs re-pick.
                var msg = (result.body && (result.body.error || result.body.message))
                    || 'We could not start your payment. Please try again.';
                showError(msg);
                elements.submitButton.disabled = false;
                elements.submitButton.textContent = 'Continue to payment';
            })
            .catch(function (err) {
                console.error('[subzz-picker] Create-session error', err);
                showError('We could not reach our payment system. Please check your connection and try again.');
                elements.submitButton.disabled = false;
                elements.submitButton.textContent = 'Continue to payment';
            });
    }

    function showError(message) {
        if (!elements.errorBox) return;
        elements.errorBox.textContent = message;
        elements.errorBox.hidden = false;
    }

    function hideError() {
        if (!elements.errorBox) return;
        elements.errorBox.textContent = '';
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
