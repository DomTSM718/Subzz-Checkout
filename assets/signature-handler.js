/**
 * Subzz Signature Handler - Enhanced with Variant Support and Legal Compliance
 * Manages signature pad, legal compliance fields, and form submission with Azure backend
 * ENHANCEMENT: Added variant support and legal compliance field collection
 */

jQuery(document).ready(function($) {
    console.log('SUBZZ SIGNATURE: Handler loading with Azure integration and variant support');
    
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
        alert('Page configuration error. Please return to checkout and try again.');
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
        alert('Signature functionality not available. Please refresh the page.');
        return;
    }
    
    const signaturePad = new SignaturePad(canvas, {
        backgroundColor: 'rgba(255, 255, 255, 0)',
        penColor: 'rgb(0, 0, 0)',
        minWidth: 1,
        maxWidth: 3
    });
    
    console.log('SUBZZ SIGNATURE: SignaturePad initialized successfully');

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
            oldSize: `${oldWidth}x${oldHeight}`,
            newSize: `${canvas.width}x${canvas.height}`,
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

    // NEW: Typed full name field validation
    $('#typed-full-name').on('input blur', function() {
        const value = $(this).val().trim();
        console.log('SUBZZ SIGNATURE: Typed full name changed:', value);
        
        // Basic validation for full name (should have at least first and last name)
        if (value.length > 0 && value.split(' ').length < 2) {
            $(this).addClass('field-warning');
            console.log('SUBZZ SIGNATURE: Warning - full name should include first and last name');
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

    // Enhanced form validation with legal compliance checks
    function validateForm() {
        // Check all required fields
        const hasSignature = !signaturePad.isEmpty();
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
            alert('Please type your full legal name.');
            $('#typed-full-name').focus();
            return;
        }
        
        if (typedFullName.split(' ').length < 2) {
            console.warn('SUBZZ SIGNATURE: Validation failed - full name incomplete');
            alert('Please enter your full name (first and last name).');
            $('#typed-full-name').focus();
            return;
        }
        
        // Validate initials
        if (!typedInitials || typedInitials.length < 2) {
            console.warn('SUBZZ SIGNATURE: Validation failed - initials not provided');
            alert('Please type your initials (minimum 2 characters).');
            $('#typed-initials').focus();
            return;
        }
        
        // Validate signature
        if (signaturePad.isEmpty()) {
            console.warn('SUBZZ SIGNATURE: Validation failed - no signature provided');
            alert('Please provide your signature.');
            return;
        }
        
        // Validate electronic consent
        if (!electronicConsent) {
            console.warn('SUBZZ SIGNATURE: Validation failed - electronic consent not given');
            alert('Please consent to electronic signature.');
            $('#electronic-consent').focus();
            return;
        }

        // Validate terms consent
        if (!termsConsent) {
            console.warn('SUBZZ SIGNATURE: Validation failed - terms not accepted');
            alert('Please accept the terms and conditions.');
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

        // Get signature data with validation
        let signatureData;
        try {
            signatureData = signaturePad.toDataURL();
            console.log('SUBZZ SIGNATURE: Signature data captured', {
                length: signatureData.length,
                format: signatureData.substring(0, 50) + '...'
            });
        } catch (error) {
            console.error('SUBZZ SIGNATURE ERROR: Failed to capture signature data:', error);
            alert('Failed to capture signature. Please try again.');
            $button.prop('disabled', false).text(originalText);
            return;
        }

        // Prepare AJAX data with legal compliance fields and variant info
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
            hasVariantInfo: !!ajaxData.variant_info,
            hasNonce: !!ajaxData.nonce
        });

        console.log('SUBZZ SIGNATURE API: Sending signature with legal compliance data to Azure backend via WordPress AJAX');

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
                        redirectUrl: response.data?.redirect_url,
                        referenceId: response.data?.reference_id,
                        variantInfo: response.data?.variant_info
                    });
                    
                    // Show enhanced success message
                    $('.contract-content').html(
                        '<div class="contract-success">' +
                        '<h2>✅ Contract Signed Successfully!</h2>' +
                        '<p>Your subscription agreement has been digitally signed and securely stored.</p>' +
                        '<p><strong>Legal Confirmation:</strong></p>' +
                        '<ul style="text-align: left; display: inline-block;">' +
                        '<li>✓ Signed by: ' + typedFullName + '</li>' +
                        '<li>✓ Initials: ' + typedInitials + '</li>' +
                        '<li>✓ Electronic signature consent: Given</li>' +
                        '<li>✓ Terms and conditions: Accepted</li>' +
                        (window.subzzVariantInfo && window.subzzVariantInfo.subscription_duration_months ? 
                            '<li>✓ Subscription duration: ' + window.subzzVariantInfo.subscription_duration_months + ' months</li>' : '') +
                        '</ul>' +
                        '<p>You will now be redirected to complete your payment.</p>' +
                        '<div style="margin: 20px 0;">' +
                        '<div style="background: #f8f9fa; padding: 15px; border-radius: 5px; font-size: 14px; color: #666;">' +
                        '<strong>What happens next:</strong><br>' +
                        '• Your signed contract is securely stored<br>' +
                        '• You will complete payment on a secure payment page<br>' +
                        '• Your subscription will be activated immediately after payment<br>' +
                        '• You will receive email confirmation with contract details' +
                        '</div>' +
                        '</div>' +
                        '<p><strong>Redirecting to payment in 3 seconds...</strong></p>' +
                        '</div>'
                    );

                    console.log('SUBZZ SIGNATURE SUCCESS: Success message displayed with legal confirmation, starting redirect countdown');

                    // Enhanced redirect with validation
                    if (response.data && response.data.redirect_url) {
                        setTimeout(function() {
                            console.log('SUBZZ SIGNATURE REDIRECT: Redirecting to payment page');
                            console.log('SUBZZ SIGNATURE REDIRECT: URL:', response.data.redirect_url);
                            window.location.href = response.data.redirect_url;
                        }, 3000);
                    } else {
                        console.error('SUBZZ SIGNATURE ERROR: No redirect URL provided in successful response');
                        console.error('SUBZZ SIGNATURE ERROR: Full response:', response);
                        alert('Signature saved but redirect failed. Please return to checkout manually.');
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
                    
                    alert(errorMessage);
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
                
                alert(errorMessage);
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
    
    // Enhanced visual feedback with logging
    function updateSignaturePadVisuals() {
        const isEmpty = signaturePad.isEmpty();
        
        if (isEmpty) {
            $('.signature-pad-container').addClass('signature-empty').removeClass('signature-valid');
            console.log('SUBZZ SIGNATURE: Visual feedback - signature pad empty');
        } else {
            $('.signature-pad-container').removeClass('signature-empty').addClass('signature-valid');
            console.log('SUBZZ SIGNATURE: Visual feedback - signature present');
        }
    }
    
    // Update visuals on signature changes
    signaturePad.addEventListener('endStroke', updateSignaturePadVisuals);
    $('#clear-signature').on('click', updateSignaturePadVisuals);
    
    // Initial visual update and validation
    updateSignaturePadVisuals();
    validateForm();
    
    console.log('SUBZZ SIGNATURE: Handler initialization complete with variant and legal compliance support');
    
    // Debug information for production support
    console.log('SUBZZ SIGNATURE DEBUG: Environment information', {
        userAgent: navigator.userAgent,
        screenResolution: window.screen.width + 'x' + window.screen.height,
        windowSize: window.innerWidth + 'x' + window.innerHeight,
        devicePixelRatio: window.devicePixelRatio,
        jQueryVersion: $.fn.jquery,
        signaturePadLoaded: typeof SignaturePad !== 'undefined',
        pageUrl: window.location.href,
        variantSupport: 'enabled',
        legalComplianceFields: 'active'
    });
    
    // Add CSS for field validation states
    const style = document.createElement('style');
    style.innerHTML = `
        .field-valid { border-color: #28a745 !important; }
        .field-warning { border-color: #ffc107 !important; }
        .signature-valid { border-color: #28a745 !important; }
        .consent-checked { background-color: #f0f8ff; }
        .signature-empty { border-color: #dc3545 !important; }
    `;
    document.head.appendChild(style);
});