/**
 * Subzz Signature Handler - Enhanced with Variant Support and Legal Compliance
 * Manages signature pad, legal compliance fields, and form submission with Azure backend
 * 
 * HYBRID ARCHITECTURE UPDATE (Oct 19, 2025):
 * - Now waits for billing date selection to complete before initializing
 * - Listens for 'subzz:contractGenerated' event from billing-date-handler.js
 * - Includes billing_day_of_month in signature submission
 * 
 * ENHANCEMENT: Added variant support and legal compliance field collection
 */

jQuery(document).ready(function($) {
    // Guard against multiple initializations
    if (window._subzzSignatureInitialized) {
        console.warn('SUBZZ SIGNATURE: Already initialized, skipping');
        return;
    }
    window._subzzSignatureInitialized = true;

    console.log('SUBZZ SIGNATURE: Handler loading with Azure integration and variant support');
    
    // HYBRID ARCHITECTURE: Wait for contract to be generated before initializing
    if (window.SubzzContract && window.SubzzContract.contractGenerated) {
        // Contract already generated (unlikely but handle it)
        console.log('SUBZZ SIGNATURE: Contract already generated, initializing immediately');
        initializeSignatureHandler();
    } else {
        // Wait for contract generation event from billing-date-handler.js
        console.log('SUBZZ SIGNATURE: Waiting for contract generation to complete...');
        document.addEventListener('subzz:contractGenerated', function(e) {
            console.log('SUBZZ SIGNATURE: Contract generated event received', e.detail);
            initializeSignatureHandler();
        });
    }
});

/**
 * Initialize signature handler (called after contract is generated)
 */
function initializeSignatureHandler() {
    const $ = jQuery;
    
    console.log('SUBZZ SIGNATURE: Initializing signature handler (contract ready)');
    
    // Helper: show branded inline error (replaces alert())
    function showSignatureError(message, $scrollTarget) {
        var $bar = $('#signature-error-bar');
        if ($bar.length === 0) {
            $bar = $('<div id="signature-error-bar" class="subzz-error-bar"></div>');
            $('#sign-agreement').before($bar);
        }
        $bar.text(message).addClass('visible');
        if ($scrollTarget && $scrollTarget.length) {
            $scrollTarget[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            $scrollTarget.addClass('shake');
            $scrollTarget.one('animationend', function () { $scrollTarget.removeClass('shake'); });
        } else {
            $bar[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        setTimeout(function () { $bar.removeClass('visible'); }, 8000);
    }

    // Enhanced validation with detailed logging
    if (!window.subzzContractToken || !window.subzzReferenceId || !window.subzzCustomerEmail) {
        console.error('SUBZZ SIGNATURE ERROR: Missing required signature page data');
        console.error('SUBZZ SIGNATURE ERROR: Available variables:', {
            token: !!window.subzzContractToken,
            referenceId: !!window.subzzReferenceId,
            customerEmail: !!window.subzzCustomerEmail,
            ajaxUrl: !!window.subzzAjaxUrl,
            nonce: !!window.subzzNonce,
            variantInfo: !!window.subzzVariantInfo
        });
        // Can't use showSignatureError here since DOM may not be ready — use a visible div instead
        var $page = $('.subzz-contract-page .container');
        if ($page.length) {
            $page.prepend('<div class="subzz-error-bar visible">Page configuration error. Please return to checkout and try again.</div>');
        }
        return;
    }
    
    console.log('SUBZZ SIGNATURE: Page data confirmed');
    console.log('SUBZZ SIGNATURE: Reference ID:', window.subzzReferenceId);
    console.log('SUBZZ SIGNATURE: Customer Email:', window.subzzCustomerEmail);
    console.log('SUBZZ SIGNATURE: Token length:', window.subzzContractToken.length);
    
    // Log variant info if available
    if (window.subzzVariantInfo && Object.keys(window.subzzVariantInfo).length > 0) {
        console.log('SUBZZ SIGNATURE: Variant info available:', window.subzzVariantInfo);
    } else {
        console.log('SUBZZ SIGNATURE: No variant info provided (using defaults)');
    }

    // Initialize signature pad with enhanced error checking
    const canvas = document.getElementById('signature-pad');
    if (!canvas) {
        console.error('SUBZZ SIGNATURE ERROR: Signature pad canvas not found');
        console.error('SUBZZ SIGNATURE ERROR: Available elements:', {
            canvas: !!document.getElementById('signature-pad'),
            clearButton: !!document.getElementById('clear-signature'),
            typedName: !!document.getElementById('typed-full-name'),
            typedInitials: !!document.getElementById('typed-initials'),
            electronicConsent: !!document.getElementById('electronic-consent'),
            termsCheckbox: !!document.getElementById('terms-consent'),
            signButton: !!document.getElementById('sign-agreement')
        });
        return;
    }
    
    console.log('SUBZZ SIGNATURE: Canvas element found, initializing SignaturePad');
    
    // Check if SignaturePad library is loaded
    if (typeof SignaturePad === 'undefined') {
        console.error('SUBZZ SIGNATURE ERROR: SignaturePad library not loaded');
        var $page2 = $('.subzz-contract-page .container');
        if ($page2.length) {
            $page2.prepend('<div class="subzz-error-bar visible">Signature functionality not available. Please refresh the page.</div>');
        }
        return;
    }
    
    const signaturePad = new SignaturePad(canvas, {
        backgroundColor: 'rgba(255, 255, 255, 0)',
        penColor: 'rgb(0, 0, 0)',
        minWidth: 1,
        maxWidth: 3
    });
    
    console.log('SUBZZ SIGNATURE: SignaturePad initialized successfully');

    // ── Signature Mode State (Draw / Type) ──────────────────────────────
    var signatureMode = 'draw';
    var typedSignatureCanvas = document.getElementById('typed-signature-canvas');

    // Preload Dancing Script font for typed signature rendering
    if (document.fonts && document.fonts.load) {
        document.fonts.load('48px "Dancing Script"').then(function() {
            console.log('SUBZZ SIGNATURE: Dancing Script font preloaded');
        }).catch(function() {
            console.warn('SUBZZ SIGNATURE: Dancing Script font preload failed — will use fallback');
        });
    }

    // Enhanced resize canvas function with logging
    function resizeCanvas() {
        console.log('SUBZZ SIGNATURE: Resizing canvas');
        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        const container = canvas.parentElement;
        
        if (!container) {
            console.error('SUBZZ SIGNATURE ERROR: Canvas container not found');
            return;
        }
        
        const oldWidth = canvas.width;
        const oldHeight = canvas.height;
        
        canvas.width = container.offsetWidth * ratio;
        canvas.height = 200 * ratio;
        canvas.style.width = container.offsetWidth + 'px';
        canvas.style.height = '200px';
        
        canvas.getContext("2d").scale(ratio, ratio);
        signaturePad.clear();
        
        console.log('SUBZZ SIGNATURE: Canvas resized', {
            oldSize: oldWidth + 'x' + oldHeight,
            newSize: canvas.width + 'x' + canvas.height,
            containerWidth: container.offsetWidth,
            ratio: ratio
        });
    }

    // Initial resize with logging
    console.log('SUBZZ SIGNATURE: Performing initial canvas resize');
    resizeCanvas();
    
    // Resize on window resize with debouncing
    let resizeTimeout;
    window.addEventListener('resize', function() {
        console.log('SUBZZ SIGNATURE: Window resize detected, scheduling canvas resize');
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(resizeCanvas, 250);
    });

    // Enhanced clear signature button with logging
    $('#clear-signature').on('click', function() {
        console.log('SUBZZ SIGNATURE: Clear signature button clicked');
        if (signaturePad.isEmpty()) {
            console.log('SUBZZ SIGNATURE: Signature pad already empty');
        } else {
            console.log('SUBZZ SIGNATURE: Clearing signature pad');
            signaturePad.clear();
        }
        validateForm();
    });

    // ── Signature Mode Tab Switching ───────────────────────────────────
    $('.sig-tab').on('click', function() {
        var mode = $(this).data('mode');
        if (mode === signatureMode) return;

        console.log('SUBZZ SIGNATURE: Switching mode from', signatureMode, 'to', mode);
        signatureMode = mode;

        // Update tab active state
        $('.sig-tab').removeClass('active');
        $(this).addClass('active');

        // Toggle panels
        if (mode === 'draw') {
            $('#sig-panel-type').hide();
            $('#sig-panel-draw').fadeIn(200);
            // Clear typed signature data
            $('#typed-signature-input').val('');
            $('#typed-sig-text').hide().text('');
            $('.typed-sig-placeholder').show();
            $('#typed-sig-preview').removeClass('has-content');
            clearTypedCanvas();
        } else {
            $('#sig-panel-draw').hide();
            $('#sig-panel-type').fadeIn(200);
            // Clear drawn signature
            signaturePad.clear();
            updateSignaturePadVisuals();
        }

        validateForm();
    });

    // ── Typed Signature Input Handler ───────────────────────────────────
    $('#typed-signature-input').on('input', function() {
        var text = $(this).val().trim();
        console.log('SUBZZ SIGNATURE: Typed signature input:', text);

        if (text.length > 0) {
            $('#typed-sig-text').text(text).show();
            $('.typed-sig-placeholder').hide();
            $('#typed-sig-preview').addClass('has-content');
            renderTypedToCanvas(text);
        } else {
            $('#typed-sig-text').hide().text('');
            $('.typed-sig-placeholder').show();
            $('#typed-sig-preview').removeClass('has-content');
            clearTypedCanvas();
        }

        validateForm();
    });

    /**
     * Render typed signature text to hidden canvas as base64
     */
    function renderTypedToCanvas(text) {
        if (!typedSignatureCanvas) return;

        var ctx = typedSignatureCanvas.getContext('2d');
        var w = typedSignatureCanvas.width;
        var h = typedSignatureCanvas.height;

        // Clear canvas
        ctx.clearRect(0, 0, w, h);

        // White background
        ctx.fillStyle = '#FFFFFF';
        ctx.fillRect(0, 0, w, h);

        // Render text in Dancing Script
        ctx.fillStyle = '#000000';
        ctx.font = '48px "Dancing Script", cursive';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(text, w / 2, h / 2);

        console.log('SUBZZ SIGNATURE: Typed signature rendered to canvas');
    }

    /**
     * Clear the typed signature hidden canvas
     */
    function clearTypedCanvas() {
        if (!typedSignatureCanvas) return;
        var ctx = typedSignatureCanvas.getContext('2d');
        ctx.clearRect(0, 0, typedSignatureCanvas.width, typedSignatureCanvas.height);
    }

    // NEW: Typed full name field validation
    $('#typed-full-name').on('input blur', function() {
        const value = $(this).val().trim();
        console.log('SUBZZ SIGNATURE: Typed full name changed:', value);
        
        // Validation: name must be at least 2 characters (single names accepted)
        if (value.length > 0 && value.length < 2) {
            $(this).addClass('field-warning');
        } else {
            $(this).removeClass('field-warning');
        }
        
        validateForm();
    });

    // NEW: Typed initials field validation
    $('#typed-initials').on('input blur', function() {
        const value = $(this).val().trim().toUpperCase();
        $(this).val(value); // Convert to uppercase automatically
        console.log('SUBZZ SIGNATURE: Typed initials changed:', value);
        
        // Validate initials (should be 2-4 characters)
        if (value.length > 0 && (value.length < 2 || value.length > 4)) {
            $(this).addClass('field-warning');
            console.log('SUBZZ SIGNATURE: Warning - initials should be 2-4 characters');
        } else {
            $(this).removeClass('field-warning');
        }
        
        validateForm();
    });

    // NEW: Electronic consent checkbox handler
    $('#electronic-consent').on('change', function() {
        const isChecked = $(this).is(':checked');
        console.log('SUBZZ SIGNATURE: Electronic consent changed:', isChecked);
        validateForm();
    });

    // Enhanced terms consent checkbox with logging
    $('#terms-consent').on('change', function() {
        const isChecked = $(this).is(':checked');
        console.log('SUBZZ SIGNATURE: Terms consent changed:', isChecked);
        validateForm();
    });

    // Enhanced signature pad change detection
    signaturePad.addEventListener('endStroke', function() {
        console.log('SUBZZ SIGNATURE: Signature stroke completed');
        validateForm();
    });

    // Enhanced form validation with legal compliance checks and mode awareness
    function validateForm() {
        // Check signature based on current mode
        var hasSignature;
        if (signatureMode === 'draw') {
            hasSignature = !signaturePad.isEmpty();
        } else {
            hasSignature = $('#typed-signature-input').val().trim().length > 0;
        }

        const hasTypedName = $('#typed-full-name').val().trim().length > 0;
        const hasInitials = $('#typed-initials').val().trim().length >= 2;
        const hasElectronicConsent = $('#electronic-consent').is(':checked');
        const hasTermsConsent = $('#terms-consent').is(':checked');

        // All fields must be valid
        const isValid = hasSignature && hasTypedName && hasInitials && hasElectronicConsent && hasTermsConsent;
        
        console.log('SUBZZ SIGNATURE: Form validation check', {
            hasSignature: hasSignature,
            hasTypedName: hasTypedName,
            hasInitials: hasInitials,
            hasElectronicConsent: hasElectronicConsent,
            hasTermsConsent: hasTermsConsent,
            isValid: isValid
        });
        
        const $button = $('#sign-agreement');
        $button.prop('disabled', !isValid);
        
        // Update button text based on validation state
        if (!hasTypedName) {
            $button.text('Please Type Your Full Name');
        } else if (!hasInitials) {
            $button.text('Please Type Your Initials');
        } else if (!hasSignature) {
            $button.text('Please Provide Signature');
        } else if (!hasElectronicConsent) {
            $button.text('Please Accept Electronic Signature Consent');
        } else if (!hasTermsConsent) {
            $button.text('Please Accept Terms and Conditions');
        } else {
            $button.text('Sign Agreement & Continue to Payment →');
        }
        
        console.log('SUBZZ SIGNATURE: Form validation complete - Button enabled:', isValid);
        
        // Visual feedback for validation state
        updateValidationVisuals(hasSignature, hasTypedName, hasInitials, hasElectronicConsent, hasTermsConsent);
    }

    // NEW: Visual feedback for validation
    function updateValidationVisuals(hasSignature, hasTypedName, hasInitials, hasElectronicConsent, hasTermsConsent) {
        // Add visual indicators for completed fields
        $('#typed-full-name').toggleClass('field-valid', hasTypedName);
        $('#typed-initials').toggleClass('field-valid', hasInitials);
        $('.signature-pad-container').toggleClass('signature-valid', hasSignature);
        
        // Update checkbox containers
        $('#electronic-consent').closest('.consent-checkbox').toggleClass('consent-checked', hasElectronicConsent);
        $('#terms-consent').closest('.consent-checkbox').toggleClass('consent-checked', hasTermsConsent);
    }

    // Enhanced sign agreement button click with comprehensive logging
    $('#sign-agreement').on('click', function() {
        console.log('SUBZZ SIGNATURE: Sign agreement button clicked');
        
        // Comprehensive pre-flight validation
        const typedFullName = $('#typed-full-name').val().trim();
        const typedInitials = $('#typed-initials').val().trim().toUpperCase();
        const electronicConsent = $('#electronic-consent').is(':checked');
        const termsConsent = $('#terms-consent').is(':checked');
        
        // Validate typed full name
        if (!typedFullName) {
            console.warn('SUBZZ SIGNATURE: Validation failed - no typed name provided');
            showSignatureError('Please type your full legal name.', $('#typed-full-name'));
            $('#typed-full-name').focus();
            return;
        }

        // Accept single names (some customers legally have one name)
        if (typedFullName.length < 2) {
            console.warn('SUBZZ SIGNATURE: Validation failed - name too short');
            showSignatureError('Please enter your legal name (minimum 2 characters).', $('#typed-full-name'));
            $('#typed-full-name').focus();
            return;
        }

        // Validate initials
        if (!typedInitials || typedInitials.length < 2) {
            console.warn('SUBZZ SIGNATURE: Validation failed - initials not provided');
            showSignatureError('Please type your initials (minimum 2 characters).', $('#typed-initials'));
            $('#typed-initials').focus();
            return;
        }

        // Validate signature (mode-aware)
        if (signatureMode === 'draw' && signaturePad.isEmpty()) {
            console.warn('SUBZZ SIGNATURE: Validation failed - no drawn signature provided');
            showSignatureError('Please draw your signature.', $('.signature-pad-container'));
            return;
        }
        if (signatureMode === 'type' && !$('#typed-signature-input').val().trim()) {
            console.warn('SUBZZ SIGNATURE: Validation failed - no typed signature provided');
            showSignatureError('Please type your signature.', $('#typed-signature-input'));
            return;
        }

        // Validate electronic consent
        if (!electronicConsent) {
            console.warn('SUBZZ SIGNATURE: Validation failed - electronic consent not given');
            showSignatureError('Please consent to electronic signature.', $('#electronic-consent').closest('.consent-checkbox'));
            $('#electronic-consent').focus();
            return;
        }

        // Validate terms consent
        if (!termsConsent) {
            console.warn('SUBZZ SIGNATURE: Validation failed - terms not accepted');
            showSignatureError('Please accept the terms and conditions.', $('#terms-consent').closest('.consent-checkbox'));
            $('#terms-consent').focus();
            return;
        }

        console.log('SUBZZ SIGNATURE: Pre-flight validation passed, starting signature save process');
        console.log('SUBZZ SIGNATURE: Legal compliance data:', {
            typedFullName: typedFullName,
            typedInitials: typedInitials,
            electronicConsent: electronicConsent,
            termsConsent: termsConsent
        });

        // Disable button and show loading state
        const $button = $(this);
        const originalText = $button.text();
        $button.prop('disabled', true).text('Processing Signature...');
        console.log('SUBZZ SIGNATURE: Button disabled, showing loading state');

        // Get signature data from correct canvas based on mode
        let signatureData;
        try {
            if (signatureMode === 'draw') {
                signatureData = signaturePad.toDataURL();
                console.log('SUBZZ SIGNATURE: Drawn signature data captured');
            } else {
                // Re-render to ensure canvas is current, then capture
                var typedText = $('#typed-signature-input').val().trim();
                renderTypedToCanvas(typedText);
                signatureData = typedSignatureCanvas.toDataURL();
                console.log('SUBZZ SIGNATURE: Typed signature data captured');
            }
            console.log('SUBZZ SIGNATURE: Signature data', {
                mode: signatureMode,
                length: signatureData.length,
                format: signatureData.substring(0, 50) + '...'
            });
        } catch (error) {
            console.error('SUBZZ SIGNATURE ERROR: Failed to capture signature data:', error);
            showSignatureError('Failed to capture signature. Please try again.');
            $button.prop('disabled', false).text(originalText);
            return;
        }

        // HYBRID ARCHITECTURE: Get billing day from billing-date-handler
        const billingDayOfMonth = window.SubzzContract && window.SubzzContract.billingDay 
            ? window.SubzzContract.billingDay 
            : null;
        
        console.log('SUBZZ SIGNATURE: Billing day from billing-date-handler:', billingDayOfMonth);
        
        if (!billingDayOfMonth) {
            console.error('SUBZZ SIGNATURE ERROR: Billing day not found - contract may not have been generated properly');
            showSignatureError('Billing date information missing. Please refresh and try again.');
            $button.prop('disabled', false).text(originalText);
            return;
        }

        // Prepare AJAX data with legal compliance fields, variant info, and billing day
        const ajaxData = {
            action: 'subzz_save_signature',
            token: window.subzzContractToken,
            reference_id: window.subzzReferenceId,
            customer_email: window.subzzCustomerEmail,
            signature_data: signatureData,
            
            // NEW: Legal compliance fields
            typed_full_name: typedFullName,
            typed_initials: typedInitials,
            electronic_consent: electronicConsent,
            terms_consent: termsConsent,
            
            // HYBRID ARCHITECTURE: Include billing day from billing-date-handler
            billing_day_of_month: billingDayOfMonth,
            
            // NEW: Variant info if available
            variant_info: window.subzzVariantInfo ? JSON.stringify(window.subzzVariantInfo) : '',
            
            nonce: window.subzzNonce
        };
        
        console.log('SUBZZ SIGNATURE: Prepared AJAX data', {
            action: ajaxData.action,
            referenceId: ajaxData.reference_id,
            customerEmail: ajaxData.customer_email,
            tokenLength: ajaxData.token.length,
            signatureLength: ajaxData.signature_data.length,
            typedName: ajaxData.typed_full_name,
            initials: ajaxData.typed_initials,
            electronicConsent: ajaxData.electronic_consent,
            termsConsent: ajaxData.terms_consent,
            billingDay: ajaxData.billing_day_of_month,
            hasVariantInfo: !!ajaxData.variant_info,
            hasNonce: !!ajaxData.nonce
        });

        console.log('SUBZZ SIGNATURE API: Sending signature with legal compliance data and billing day to Azure backend via WordPress AJAX');

        // Enhanced AJAX call with comprehensive error handling
        $.ajax({
            url: window.subzzAjaxUrl,
            type: 'POST',
            data: ajaxData,
            timeout: 30000, // 30 second timeout
            success: function(response) {
                console.log('SUBZZ SIGNATURE API: Response received from server');
                console.log('SUBZZ SIGNATURE API: Response data:', response);
                
                if (response.success) {
                    console.log('SUBZZ SIGNATURE SUCCESS: Signature saved successfully to Azure');
                    console.log('SUBZZ SIGNATURE SUCCESS: Response details:', {
                        message: response.data?.message,
                        checkoutUrl: response.data?.checkout_url,
                        redirectUrl: response.data?.redirect_url,
                        referenceId: response.data?.reference_id,
                        orderSummary: response.data?.order_summary,
                        variantInfo: response.data?.variant_info,
                        billingDay: response.data?.billing_day
                    });

                    // Determine redirect target: checkout_url (LekkaPay direct) or redirect_url (fallback)
                    var redirectUrl = response.data?.checkout_url || response.data?.redirect_url;
                    var isDirect = !!response.data?.checkout_url;
                    var summary = response.data?.order_summary;

                    console.log('SUBZZ SIGNATURE SUCCESS: Redirect mode:', isDirect ? 'DIRECT to LekkaPay' : 'FALLBACK to subscription-payment');

                    // Update progress indicator: mark Contract as done, Payment as active
                    var progressSteps = document.querySelectorAll('.checkout-progress .progress-step');
                    if (progressSteps.length >= 3) {
                        progressSteps[1].classList.remove('active');
                        progressSteps[1].classList.add('done');
                        progressSteps[2].classList.add('active');

                        // Replace step 2 dot number with checkmark
                        var dot = progressSteps[1].querySelector('.step-dot');
                        if (dot) dot.textContent = '\u2713';
                    }

                    // Activate progress lines between completed steps
                    var progressLines = document.querySelectorAll('.checkout-progress .progress-line');
                    if (progressLines.length >= 2) {
                        progressLines[0].classList.add('active');
                        progressLines[1].classList.add('active');
                    }

                    // Build order summary HTML (compact) if available
                    var orderSummaryHtml = '';
                    if (summary) {
                        var amountLabel = summary.is_initial_payment ? 'Initial Payment' : 'Monthly Payment';
                        orderSummaryHtml =
                            '<div class="order-summary-compact">' +
                            '<div class="summary-row"><span>Name</span><strong>' + summary.customer_name + '</strong></div>' +
                            '<div class="summary-row"><span>Email</span><strong>' + summary.customer_email + '</strong></div>' +
                            '<div class="summary-row"><span>Term</span><strong>' + summary.subscription_months + ' months</strong></div>' +
                            '<div class="summary-row highlight"><span>' + amountLabel + '</span><strong>' + summary.currency + ' ' + Math.ceil(parseFloat(summary.payment_amount)).toLocaleString('en-ZA') + '</strong></div>' +
                            '</div>';
                    }

                    // Show success message with order summary and countdown
                    var countdownSeconds = 3;
                    $('.contract-content').html(
                        '<div class="contract-success">' +
                        '<h2>Contract Signed Successfully!</h2>' +
                        '<div class="subzz-dash-divider">' +
                            '<span style="background:#FF9D00"></span>' +
                            '<span style="background:#F73C5C"></span>' +
                            '<span style="background:#2A8BEA"></span>' +
                            '<span style="background:#48CAED"></span>' +
                        '</div>' +
                        '<p>Your subscription agreement has been digitally signed and securely stored.</p>' +
                        orderSummaryHtml +
                        '<p>Redirecting to ' + (isDirect ? 'secure payment' : 'payment summary') + ' in <strong id="countdown-timer">' + countdownSeconds + '</strong> seconds...</p>' +
                        '</div>'
                    );

                    console.log('SUBZZ SIGNATURE SUCCESS: Success message displayed, starting redirect countdown');

                    // Countdown timer with visible seconds
                    var countdownEl = document.getElementById('countdown-timer');
                    var countdownInterval = setInterval(function() {
                        countdownSeconds--;
                        if (countdownEl) countdownEl.textContent = countdownSeconds;
                        if (countdownSeconds <= 0) {
                            clearInterval(countdownInterval);
                        }
                    }, 1000);

                    // Redirect after countdown
                    if (redirectUrl) {
                        setTimeout(function() {
                            console.log('SUBZZ SIGNATURE REDIRECT: Redirecting to:', redirectUrl);
                            window.location.href = redirectUrl;
                        }, countdownSeconds * 1000);
                    } else {
                        console.error('SUBZZ SIGNATURE ERROR: No redirect URL provided in successful response');
                        console.error('SUBZZ SIGNATURE ERROR: Full response:', response);
                        showSignatureError('Signature saved but redirect failed. Please return to checkout manually.');
                        $button.prop('disabled', false).text(originalText);
                    }
                } else {
                    console.error('SUBZZ SIGNATURE ERROR: Server returned error response');
                    console.error('SUBZZ SIGNATURE ERROR: Error details:', response.data);
                    
                    let errorMessage = 'Error saving signature: ';
                    if (response.data && typeof response.data === 'string') {
                        errorMessage += response.data;
                    } else if (response.data && response.data.message) {
                        errorMessage += response.data.message;
                    } else {
                        errorMessage += 'Unknown error occurred';
                    }
                    
                    showSignatureError(errorMessage);
                    $button.prop('disabled', false).text(originalText);
                }
            },
            error: function(xhr, status, error) {
                console.error('SUBZZ SIGNATURE ERROR: AJAX request failed');
                console.error('SUBZZ SIGNATURE ERROR: Status:', status);
                console.error('SUBZZ SIGNATURE ERROR: Error:', error);
                console.error('SUBZZ SIGNATURE ERROR: HTTP Status:', xhr.status);
                console.error('SUBZZ SIGNATURE ERROR: Response Text:', xhr.responseText);
                
                let errorMessage = 'Network error while saving signature. ';
                
                if (status === 'timeout') {
                    errorMessage += 'Request timed out after 30 seconds. Please check your connection and try again.';
                } else if (xhr.status === 0) {
                    errorMessage += 'No connection to server. Please check your internet connection.';
                } else if (xhr.status >= 500) {
                    errorMessage += 'Server error (' + xhr.status + '). Please try again in a moment.';
                } else if (xhr.status >= 400) {
                    errorMessage += 'Request error (' + xhr.status + '). Please refresh the page and try again.';
                } else {
                    errorMessage += 'Please check your connection and try again.';
                }
                
                showSignatureError(errorMessage);
                $button.prop('disabled', false).text(originalText);
            }
        });
    });

    // Enhanced print functionality with logging
    window.addEventListener('beforeprint', function() {
        console.log('SUBZZ SIGNATURE: Preparing page for printing');
        $('.signature-section').hide();
        $('.contract-actions').hide();
        $('.legal-compliance-section').hide();
        $('body').addClass('printing-contract');
    });

    window.addEventListener('afterprint', function() {
        console.log('SUBZZ SIGNATURE: Restoring page after printing');
        $('.signature-section').show();
        $('.contract-actions').show();
        $('.legal-compliance-section').show();
        $('body').removeClass('printing-contract');
    });
    
    // Enhanced visual feedback with logging (mode-aware)
    function updateSignaturePadVisuals() {
        if (signatureMode === 'draw') {
            const isEmpty = signaturePad.isEmpty();
            if (isEmpty) {
                $('.signature-pad-container').addClass('signature-empty').removeClass('signature-valid');
            } else {
                $('.signature-pad-container').removeClass('signature-empty').addClass('signature-valid');
            }
        }
        // Type mode visuals handled by input handler (has-content class on preview)
    }
    
    // Update visuals on signature changes
    signaturePad.addEventListener('endStroke', updateSignaturePadVisuals);
    $('#clear-signature').on('click', updateSignaturePadVisuals);
    
    // Initial visual update and validation
    updateSignaturePadVisuals();
    validateForm();
    
    console.log('SUBZZ SIGNATURE: Handler initialization complete with Draw/Type modes, variant, legal compliance, and HYBRID architecture support');

    // Debug information for production support
    console.log('SUBZZ SIGNATURE DEBUG: Environment information', {
        userAgent: navigator.userAgent,
        screenResolution: window.screen.width + 'x' + window.screen.height,
        windowSize: window.innerWidth + 'x' + window.innerHeight,
        devicePixelRatio: window.devicePixelRatio,
        jQueryVersion: $.fn.jquery,
        signaturePadLoaded: typeof SignaturePad !== 'undefined',
        pageUrl: window.location.href,
        signatureMode: signatureMode,
        variantSupport: 'enabled',
        legalComplianceFields: 'active',
        hybridArchitecture: 'active',
        drawTypeSignature: 'active'
    });
}