/**
 * Subzz Payment Update — JavaScript
 * Handles card form initialisation, LekkaPay tokenisation, and payment method update.
 *
 * Expects:
 *   - window.subzzPaymentData: { token, subscriptionId, apiUrl }
 *   - LekkaPay SDK loaded (when available) or placeholder form
 *
 * NOTE: LekkaPay SDK integration depends on their documentation.
 * If redirect-based (not hosted fields), the flow uses redirect instead.
 * This implementation provides a placeholder card form for initial deployment.
 *
 * @since 2.0.0
 */
(function($) {
    'use strict';

    var data = window.subzzPaymentData || {};
    var $form = $('#payment-card-form');
    var $submit = $('#payment-update-submit');
    var $result = $('#payment-update-result');

    $(document).ready(function() {
        if (!data.token) return;

        initCardForm();
        initSubmitHandler();
    });

    /**
     * Initialize the card form.
     * LekkaPay uses redirect-based hosted payment pages (HPP), not inline hosted fields.
     * For payment updates, we need to create a LekkaPay session and redirect.
     *
     * Placeholder: simple form with instructions until LekkaPay card-update SDK details are confirmed.
     */
    function initCardForm() {
        $form.html(
            '<div style="text-align: center; padding: 20px;">' +
                '<p style="font-size: 15px; color: #333; margin-bottom: 16px;">' +
                    'Click the button below to securely update your card details.' +
                '</p>' +
                '<p style="font-size: 13px; color: #6c757d;">' +
                    'You will be redirected to our secure payment provider to enter your new card information.' +
                '</p>' +
            '</div>'
        );
        $submit.prop('disabled', false).text('Update Card');
    }

    /**
     * Handle form submission.
     * Creates a LekkaPay session for card update and redirects the customer.
     */
    function initSubmitHandler() {
        $submit.on('click', function(e) {
            e.preventDefault();

            var $btn = $(this);
            $btn.prop('disabled', true).html('<span class="portal-loading"></span> Processing...');
            $result.hide();

            // Call the Azure API to create a payment session for card update
            $.ajax({
                url: data.apiUrl + '/payment/create-session',
                type: 'POST',
                contentType: 'application/json',
                headers: {
                    'X-Subzz-API-Key': '' // API key will be sent from WordPress server-side in production
                },
                data: JSON.stringify({
                    orderReferenceId: data.subscriptionId,
                    customerEmail: '',  // Not needed for update flow
                    customerName: '',   // Not needed for update flow
                    amount: 0,          // Zero-amount tokenisation
                    currency: 'ZAR',
                    purpose: 'payment-update',
                    returnUrl: window.location.href.split('?')[0] + '?token=' + encodeURIComponent(data.token) + '&result=success',
                    cancelUrl: window.location.href.split('?')[0] + '?token=' + encodeURIComponent(data.token) + '&result=cancelled'
                }),
                success: function(response) {
                    if (response && response.checkoutUrl) {
                        window.location.href = response.checkoutUrl;
                    } else {
                        showError('Failed to create payment session. Please try again.');
                        $btn.prop('disabled', false).text('Update Card');
                    }
                },
                error: function(xhr) {
                    var errorMsg = 'An error occurred. Please try again.';
                    if (xhr.responseJSON && xhr.responseJSON.error) {
                        errorMsg = xhr.responseJSON.error;
                    }
                    showError(errorMsg);
                    $btn.prop('disabled', false).text('Update Card');
                }
            });
        });

        // Check for return result from LekkaPay
        var urlParams = new URLSearchParams(window.location.search);
        var result = urlParams.get('result');
        if (result === 'success') {
            showSuccess('Your payment method has been updated successfully.');
            $submit.hide();
            $form.hide();
        } else if (result === 'cancelled') {
            showError('Payment update was cancelled. You can try again.');
        }
    }

    function showSuccess(message) {
        $result.html(
            '<div class="portal-alert portal-alert-success">' +
                '<strong>Success</strong>' +
                '<p>' + escapeHtml(message) + '</p>' +
            '</div>'
        ).show();
    }

    function showError(message) {
        $result.html(
            '<div class="portal-alert portal-alert-error">' +
                '<strong>Error</strong>' +
                '<p>' + escapeHtml(message) + '</p>' +
            '</div>'
        ).show();
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})(jQuery);
