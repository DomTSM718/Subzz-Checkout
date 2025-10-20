/**
 * Subzz Billing Date Handler
 * Manages Step 1: Billing Date Selection
 * 
 * Responsibilities:
 * - Handle billing date radio button selection
 * - Calculate and display billing preview
 * - Generate contract via AJAX with selected billing day
 * - Emit event when contract is ready for signature
 * - Handle "Change billing date" functionality
 * 
 * Dependencies:
 * - jQuery
 * - window.subzzAjaxUrl (WordPress AJAX URL)
 * - window.subzzNonce (WordPress nonce)
 * - window.subzzContractToken (JWT token)
 * - window.subzzReferenceId (Order reference)
 * - window.subzzCustomerEmail (Customer email)
 * - window.subzzCurrency (Currency symbol)
 * - window.subzzMonthlyAmount (Monthly payment amount)
 * 
 * Events Emitted:
 * - 'subzz:contractGenerated' - When contract is successfully generated
 * 
 * Created: October 19, 2025
 * Version: 1.0.0
 */

(function($) {
    'use strict';
    
    console.log('SUBZZ BILLING DATE: Handler initializing');
    
    // Namespace for billing date functionality
    window.SubzzContract = window.SubzzContract || {};
    
    // State management
    window.SubzzContract.selectedBillingDay = null;
    window.SubzzContract.billingInfo = null;
    window.SubzzContract.contractGenerated = false;
    
    // Initialize on document ready
    $(document).ready(function() {
        console.log('SUBZZ BILLING DATE: DOM ready, setting up billing date selection');
        
        // Validate required global variables
        if (!validateGlobalVariables()) {
            console.error('SUBZZ BILLING DATE ERROR: Required global variables missing');
            return;
        }
        
        console.log('SUBZZ BILLING DATE: Global variables validated');
        console.log('SUBZZ BILLING DATE: Reference ID:', window.subzzReferenceId);
        console.log('SUBZZ BILLING DATE: Customer Email:', window.subzzCustomerEmail);
        
        // Initialize billing date selection
        initializeBillingDateSelection();
        
        // Initialize contract generation button
        initializeContinueButton();
        
        // Initialize change billing date link
        initializeChangeBillingDate();
        
        console.log('SUBZZ BILLING DATE: Handler initialization complete');
    });
    
    /**
     * Validate that all required global variables are present
     */
    function validateGlobalVariables() {
        const required = [
            'subzzContractToken',
            'subzzReferenceId', 
            'subzzCustomerEmail',
            'subzzAjaxUrl',
            'subzzNonce',
            'subzzCurrency',
            'subzzMonthlyAmount'
        ];
        
        const missing = required.filter(function(varName) {
            return typeof window[varName] === 'undefined' || window[varName] === null;
        });
        
        if (missing.length > 0) {
            console.error('SUBZZ BILLING DATE ERROR: Missing required variables:', missing);
            alert('Page configuration error. Please return to checkout and try again.');
            return false;
        }
        
        return true;
    }
    
    /**
     * Initialize billing date radio button selection
     */
    function initializeBillingDateSelection() {
        console.log('SUBZZ BILLING DATE: Initializing radio button handlers');
        
        const $billingRadios = $('input[name="billing_day"]');
        const $previewDiv = $('#billing-preview');
        const $previewText = $('#preview-text');
        
        if ($billingRadios.length === 0) {
            console.warn('SUBZZ BILLING DATE: No billing day radio buttons found');
            return;
        }
        
        console.log('SUBZZ BILLING DATE: Found ' + $billingRadios.length + ' billing day options');
        
        // Handle radio button changes
        $billingRadios.on('change', function() {
            if (this.checked) {
                const billingDay = parseInt(this.value);
                console.log('SUBZZ BILLING DATE: Selected billing day:', billingDay);
                
                // Store selection
                window.SubzzContract.selectedBillingDay = billingDay;
                
                // Update preview
                updateBillingPreview(billingDay, $previewDiv, $previewText);
                
                // Enable continue button
                enableContinueButton();
            }
        });
        
        console.log('SUBZZ BILLING DATE: Radio button handlers initialized');
    }
    
    /**
     * Update billing preview with calculated dates
     */
    function updateBillingPreview(billingDay, $previewDiv, $previewText) {
        console.log('SUBZZ BILLING DATE: Updating preview for billing day:', billingDay);
        
        const today = new Date();
        const currentDay = today.getDate();
        
        // Calculate next billing date (always next month - give customer bonus days)
        let nextBillingDate = new Date(today);
        nextBillingDate.setMonth(nextBillingDate.getMonth() + 1);
        nextBillingDate.setDate(billingDay);
        
        // Handle short months (e.g., Feb 31st becomes Feb 28/29)
        if (nextBillingDate.getDate() !== billingDay) {
            nextBillingDate.setDate(0); // Last day of previous month
        }
        
        // Calculate days of coverage
        const daysDiff = Math.ceil((nextBillingDate - today) / (1000 * 60 * 60 * 24));
        
        // Format dates
        const todayFormatted = formatDate(today);
        const nextBillingFormatted = formatDate(nextBillingDate);
        const dayOrdinal = getDayOrdinal(billingDay);
        
        // Update preview text
        const previewHtml = 
            'Your first payment of <strong>' + window.subzzCurrency + ' ' + 
            window.subzzMonthlyAmount.toFixed(2) + '</strong> today covers you from <strong>' + 
            todayFormatted + '</strong> to <strong>' + nextBillingFormatted + '</strong> (' + 
            daysDiff + ' days). Then <strong>' + window.subzzCurrency + ' ' + 
            window.subzzMonthlyAmount.toFixed(2) + '</strong> on the <strong>' + 
            dayOrdinal + '</strong> of each month.';
        
        $previewText.html(previewHtml);
        
        // Show preview
        $previewDiv.fadeIn(300);
        
        console.log('SUBZZ BILLING DATE: Preview updated', {
            billingDay: billingDay,
            nextBillingDate: nextBillingFormatted,
            daysOfCoverage: daysDiff
        });
    }
    
    /**
     * Initialize continue button
     */
    function initializeContinueButton() {
        const $continueBtn = $('#btn-continue-step-1');
        
        if ($continueBtn.length === 0) {
            console.warn('SUBZZ BILLING DATE: Continue button not found');
            return;
        }
        
        console.log('SUBZZ BILLING DATE: Initializing continue button');
        
        // Button starts disabled
        $continueBtn.prop('disabled', true);
        
        // Handle continue button click
        $continueBtn.on('click', function() {
            const billingDay = window.SubzzContract.selectedBillingDay;
            
            if (!billingDay) {
                alert('Please select a billing date');
                return;
            }
            
            console.log('SUBZZ BILLING DATE: Continue clicked, generating contract with billing day:', billingDay);
            generateContractWithBillingDate(billingDay);
        });
        
        console.log('SUBZZ BILLING DATE: Continue button initialized');
    }
    
    /**
     * Enable continue button
     */
    function enableContinueButton() {
        const $continueBtn = $('#btn-continue-step-1');
        $continueBtn.prop('disabled', false);
        console.log('SUBZZ BILLING DATE: Continue button enabled');
    }
    
    /**
     * Generate contract with selected billing date via AJAX
     */
    function generateContractWithBillingDate(billingDay) {
        console.log('SUBZZ CONTRACT GENERATION: Starting with billing day:', billingDay);
        
        // Hide Step 1
        $('#step-1-billing-date').fadeOut(300);
        
        // Show loading state
        $('#loading-contract').fadeIn(300);
        
        // Scroll to loading
        $('html, body').animate({
            scrollTop: $('#loading-contract').offset().top - 100
        }, 500);
        
        // Prepare AJAX data
        const ajaxData = {
            action: 'subzz_regenerate_contract',
            nonce: window.subzzNonce,
            token: window.subzzContractToken,
            reference_id: window.subzzReferenceId,
            customer_email: window.subzzCustomerEmail,
            billing_day: billingDay
        };
        
        console.log('SUBZZ CONTRACT GENERATION: AJAX data prepared', {
            action: ajaxData.action,
            referenceId: ajaxData.reference_id,
            billingDay: ajaxData.billing_day
        });
        
        // Make AJAX call
        $.ajax({
            url: window.subzzAjaxUrl,
            type: 'POST',
            data: ajaxData,
            timeout: 30000, // 30 seconds
            success: function(response) {
                console.log('SUBZZ CONTRACT GENERATION: Response received', response);
                
                if (response.success) {
                    handleContractGenerationSuccess(response.data, billingDay);
                } else {
                    handleContractGenerationError(response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error('SUBZZ CONTRACT GENERATION ERROR:', {
                    status: status,
                    error: error,
                    httpStatus: xhr.status
                });
                
                handleContractGenerationError('Network error: ' + error);
            }
        });
    }
    
    /**
     * Handle successful contract generation
     */
    function handleContractGenerationSuccess(data, billingDay) {
        console.log('SUBZZ CONTRACT GENERATION: Success!');
        
        // Store billing info
        window.SubzzContract.billingInfo = data.billing_info;
        window.SubzzContract.contractGenerated = true;
        window.SubzzContract.billingDay = billingDay;
        
        // Insert contract HTML
        $('#contract-text-container').html(data.contract_html);
        
        // Hide loading
        $('#loading-contract').fadeOut(300);
        
        // Show billing summary
        const $summaryDiv = $('#billing-date-summary');
        const $displaySpan = $('#selected-billing-day-display');
        $displaySpan.text(data.billing_info.billing_day_formatted + ' of each month');
        $summaryDiv.fadeIn(300);
        
        // Show Steps 2 & 3
        $('#step-2-3-container').fadeIn(300);
        
        // Scroll to billing summary
        setTimeout(function() {
            $('html, body').animate({
                scrollTop: $summaryDiv.offset().top - 100
            }, 500);
        }, 400);
        
        console.log('SUBZZ CONTRACT GENERATION: Contract displayed successfully');
        console.log('SUBZZ CONTRACT GENERATION: Billing info:', data.billing_info);
        
        // Emit event for signature handler
        emitContractGeneratedEvent();
    }
    
    /**
     * Handle contract generation error
     */
    function handleContractGenerationError(errorMessage) {
        console.error('SUBZZ CONTRACT GENERATION ERROR:', errorMessage);
        
        alert('Failed to generate contract: ' + (errorMessage || 'Unknown error'));
        
        // Return to Step 1
        returnToStep1();
    }
    
    /**
     * Emit event that contract has been generated
     */
    function emitContractGeneratedEvent() {
        console.log('SUBZZ CONTRACT GENERATION: Emitting contractGenerated event');
        
        // Create custom event
        const event = new CustomEvent('subzz:contractGenerated', {
            detail: {
                billingDay: window.SubzzContract.billingDay,
                billingInfo: window.SubzzContract.billingInfo
            }
        });
        
        // Dispatch event
        document.dispatchEvent(event);
        
        console.log('SUBZZ CONTRACT GENERATION: Event emitted - signature handler should now initialize');
    }
    
    /**
     * Initialize change billing date link
     */
    function initializeChangeBillingDate() {
        const $changeLink = $('#change-billing-date');
        
        if ($changeLink.length === 0) {
            console.warn('SUBZZ BILLING DATE: Change billing date link not found');
            return;
        }
        
        console.log('SUBZZ BILLING DATE: Initializing change billing date link');
        
        $changeLink.on('click', function(e) {
            e.preventDefault();
            console.log('SUBZZ BILLING DATE: Change billing date clicked');
            returnToStep1();
        });
        
        console.log('SUBZZ BILLING DATE: Change billing date link initialized');
    }
    
    /**
     * Return to Step 1 (change billing date)
     */
    function returnToStep1() {
        console.log('SUBZZ BILLING DATE: Returning to Step 1');
        
        // Hide loading, summary, and steps 2-3
        $('#loading-contract').hide();
        $('#billing-date-summary').hide();
        $('#step-2-3-container').hide();
        
        // Show Step 1 again
        $('#step-1-billing-date').fadeIn(300);
        
        // Scroll to Step 1
        $('html, body').animate({
            scrollTop: $('#step-1-billing-date').offset().top - 100
        }, 500);
        
        // Keep the previously selected radio button checked
        // (selectedBillingDay variable still holds the value)
        console.log('SUBZZ BILLING DATE: Returned to Step 1, previous selection preserved');
    }
    
    /**
     * Format date (e.g., "19 Oct 2025")
     */
    function formatDate(date) {
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 
                       'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return date.getDate() + ' ' + months[date.getMonth()] + ' ' + date.getFullYear();
    }
    
    /**
     * Get day ordinal (e.g., 1st, 2nd, 3rd, 22nd)
     */
    function getDayOrdinal(day) {
        if (day === 1 || day === 21 || day === 31) return day + 'st';
        if (day === 2 || day === 22) return day + 'nd';
        if (day === 3 || day === 23) return day + 'rd';
        return day + 'th';
    }
    
    // Export functions for testing (optional)
    window.SubzzContract.returnToStep1 = returnToStep1;
    
    console.log('SUBZZ BILLING DATE: Module loaded and ready');
    
})(jQuery);