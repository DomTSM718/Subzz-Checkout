/**
 * Subzz Customer Portal — JavaScript
 * Handles tab switching, invoice pagination, PDF downloads, and payment update redirect.
 * IIFE pattern (matches checkout-plans.js).
 *
 * Expects wp_localize_script 'subzzPortal' with: ajaxurl, nonce, paymentUpdateUrl
 *
 * @since 2.0.0
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        initTabs();
        initInvoicePagination();
        initInvoiceDownload();
        initPaymentUpdate();
    });

    // ── Tab switching ─────────────────────────────────────────
    function initTabs() {
        $('.portal-tab').on('click', function() {
            var tab = $(this).data('tab');

            // Update active tab button
            $('.portal-tab').removeClass('active');
            $(this).addClass('active');

            // Update active tab content
            $('.portal-tab-content').removeClass('active');
            $('#tab-' + tab).addClass('active');

            // Update URL without reload
            var url = new URL(window.location);
            url.searchParams.set('tab', tab);
            history.replaceState(null, '', url);
        });
    }

    // ── Invoice pagination ────────────────────────────────────
    function initInvoicePagination() {
        $(document).on('click', '.portal-pagination-prev', function() {
            var $pagination = $(this).closest('.portal-pagination');
            var currentPage = parseInt($pagination.data('current-page'));
            if (currentPage > 1) {
                loadInvoicePage(currentPage - 1);
            }
        });

        $(document).on('click', '.portal-pagination-next', function() {
            var $pagination = $(this).closest('.portal-pagination');
            var currentPage = parseInt($pagination.data('current-page'));
            var totalPages = parseInt($pagination.data('total-pages'));
            if (currentPage < totalPages) {
                loadInvoicePage(currentPage + 1);
            }
        });
    }

    function loadInvoicePage(page) {
        var $tbody = $('#invoice-table-body');
        $tbody.css('opacity', '0.5');

        $.ajax({
            url: subzzPortal.ajaxurl,
            type: 'POST',
            data: {
                action: 'subzz_load_invoices',
                nonce: subzzPortal.nonce,
                page: page
            },
            success: function(response) {
                if (response.success) {
                    $tbody.html(response.data.html).css('opacity', '1');

                    // Update pagination state
                    var $pagination = $('.portal-pagination');
                    $pagination.data('current-page', response.data.page);
                    $pagination.find('.portal-pagination-info').text(
                        'Page ' + response.data.page + ' of ' + response.data.totalPages
                    );
                    $pagination.find('.portal-pagination-prev').prop('disabled', response.data.page <= 1);
                    $pagination.find('.portal-pagination-next').prop('disabled', response.data.page >= response.data.totalPages);
                } else {
                    $tbody.css('opacity', '1');
                    alert('Failed to load invoices. Please try again.');
                }
            },
            error: function() {
                $tbody.css('opacity', '1');
                alert('An error occurred. Please try again.');
            }
        });
    }

    // ── Invoice PDF download ──────────────────────────────────
    function initInvoiceDownload() {
        $(document).on('click', '.portal-download-invoice', function() {
            var $btn = $(this);
            var invoiceId = $btn.data('invoice-id');
            var originalText = $btn.text();

            $btn.prop('disabled', true).text('...');

            $.ajax({
                url: subzzPortal.ajaxurl,
                type: 'POST',
                data: {
                    action: 'subzz_get_invoice_pdf',
                    nonce: subzzPortal.nonce,
                    invoice_id: invoiceId
                },
                success: function(response) {
                    if (response.success && response.data.downloadUrl) {
                        window.open(response.data.downloadUrl, '_blank');
                    } else {
                        alert('Failed to generate PDF download link.');
                    }
                    $btn.prop('disabled', false).text(originalText);
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        });
    }

    // ── Payment update redirect ───────────────────────────────
    function initPaymentUpdate() {
        $('#portal-update-payment').on('click', function() {
            var $btn = $(this);
            var originalHtml = $btn.html();

            $btn.prop('disabled', true).html('<span class="portal-loading"></span> Generating secure link...');

            $.ajax({
                url: subzzPortal.ajaxurl,
                type: 'POST',
                data: {
                    action: 'subzz_generate_payment_token',
                    nonce: subzzPortal.nonce
                },
                success: function(response) {
                    if (response.success && response.data.redirectUrl) {
                        window.location.href = response.data.redirectUrl;
                    } else {
                        alert('Failed to generate payment update link. Please try again.');
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                    $btn.prop('disabled', false).html(originalHtml);
                }
            });
        });
    }

})(jQuery);
