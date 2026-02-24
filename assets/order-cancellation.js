/**
 * Order Cancellation Handler
 * 
 * File: order-cancellation.js
 * Purpose: Handle order cancellation from contract signature page
 * Date: October 20, 2025
 * Issue: #1 - Back to Checkout button empties cart
 * Solution: Cancel order and restore cart
 */

(function($) {
    'use strict';
    
    console.log('SUBZZ CANCELLATION: Script loaded');
    
    $(document).ready(function() {
        
        console.log('SUBZZ CANCELLATION: DOM ready, looking for cancel button');
        
        // Handle cancel order button click
        $('#cancel-order-button').on('click', function(e) {
            e.preventDefault();
            
            console.log('SUBZZ CANCELLATION: Cancel button clicked');
            
            // Show confirmation dialog
            const confirmed = confirm(
                'Are you sure you want to cancel this order?\n\n' +
                'Your cart will be restored and you can start over.\n\n' +
                'Click OK to cancel the order, or Cancel to continue with your signature.'
            );
            
            if (!confirmed) {
                console.log('SUBZZ CANCELLATION: User cancelled the cancellation');
                return;
            }
            
            const button = $(this);
            const referenceId = button.data('reference-id');
            
            if (!referenceId) {
                alert('Error: Unable to identify order. Please refresh the page and try again.');
                console.error('SUBZZ CANCELLATION ERROR: No reference ID found');
                return;
            }
            
            console.log('SUBZZ CANCELLATION: Processing cancellation for:', referenceId);
            
            // Disable button and show loading state
            button.prop('disabled', true);
            const originalText = button.text();
            button.text('Cancelling order...');
            
            // Send AJAX request
            $.ajax({
                url: window.subzzAjaxUrl,
                type: 'POST',
                data: {
                    action: 'subzz_cancel_order',
                    nonce: window.subzzNonce,
                    reference_id: referenceId
                },
                success: function(response) {
                    console.log('SUBZZ CANCELLATION: Server response:', response);
                    
                    if (response.success) {
                        console.log('SUBZZ CANCELLATION SUCCESS: Cart items restored:', response.data.cart_items_restored);
                        
                        // Show success message
                        alert(
                            'Order cancelled successfully!\n\n' +
                            'Your cart has been restored with ' + response.data.cart_items_restored + ' item(s).\n\n' +
                            'Redirecting to cart...'
                        );
                        
                        // Redirect to cart
                        window.location.href = response.data.cart_url;
                        
                    } else {
                        console.error('SUBZZ CANCELLATION ERROR:', response.data.message);
                        
                        alert('Failed to cancel order: ' + response.data.message);
                        
                        // Re-enable button
                        button.prop('disabled', false);
                        button.text(originalText);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('SUBZZ CANCELLATION AJAX ERROR:', {
                        status: status,
                        error: error,
                        response: xhr.responseText
                    });
                    
                    alert(
                        'An error occurred while cancelling the order.\n\n' +
                        'Please try again or contact support if the problem persists.'
                    );
                    
                    // Re-enable button
                    button.prop('disabled', false);
                    button.text(originalText);
                }
            });
        });
        
        console.log('SUBZZ CANCELLATION: Event handler registered for #cancel-order-button');
    });
    
})(jQuery);