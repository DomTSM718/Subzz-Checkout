<?php
/**
 * Contract Integration Class - Updated for Payment Page Redirect and Variant Support
 * 
 * CHANGES MADE:
 * 1. Updated redirect after signature to go to payment page instead of checkout
 * 2. Removed signature return handler as it's no longer needed
 * 3. All existing functionality preserved except checkout return flow
 * 4. VARIANT SUPPORT: Now captures and passes variant info from Azure response
 * 5. ORDER REFERENCE ENHANCEMENT: Passes reference_id for database order retrieval
 * 
 * PDF GENERATION FIX (Oct 7, 2025):
 * 6. CONTRACT HTML STORAGE: Now stores contract_html when generated (line 152)
 * 7. SIGNATURE DATA INCLUSION: Now sends signature_image_data to Azure (line 485)
 * 8. Both fields required for PDF generation system to function
 * 
 * LEGAL COMPLIANCE FIX (Oct 9, 2025):
 * 9. HTML RESTRUCTURE: Moved consent checkboxes to legal compliance section
 * 10. STEP ORGANIZATION: Added clear Step 1, 2, 3 structure for better UX
 * 11. CSS ENHANCEMENTS: Professional styling for legal compliance fields
 * 12. ACCESSIBILITY: Improved labels, hints, and visual feedback
 */

if (!defined('ABSPATH')) {
    exit;
}

class Subzz_Contract_Integration {
    
    private $azure_client;
    
    public function __construct() {
        $this->azure_client = new Subzz_Azure_API_Client();
        add_action('init', array($this, 'init'));
    }

    public function init() {
        // Handle contract signature page routing
        add_action('template_redirect', array($this, 'handle_contract_signature_page'));
        
        // AJAX handlers for signature processing
        add_action('wp_ajax_subzz_save_signature', array($this, 'save_signature'));
        add_action('wp_ajax_nopriv_subzz_save_signature', array($this, 'save_signature'));
    }

    /**
     * Handle contract signature page display - ENHANCED WITH VARIANT SUPPORT
     */
    public function handle_contract_signature_page() {
        global $wp;
        
        // Check if this is the contract signature URL
        if (!isset($wp->request) || $wp->request !== 'contract-signature') {
            return;
        }
        
        error_log('=== SUBZZ CONTRACT PAGE: Enhanced request received ===');
        error_log('SUBZZ CONTRACT PAGE: URL path: ' . $wp->request);
        error_log('SUBZZ CONTRACT PAGE: Query string: ' . (isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : 'none'));
        
        // Get and validate JWT token from URL
        if (!isset($_GET['token'])) {
            error_log('SUBZZ CONTRACT ERROR: No token provided in URL');
            $this->display_styled_error_page('Missing Contract Token', 'Missing contract token. Please return to checkout and try again.');
            exit;
        }

        $jwt_token = sanitize_text_field($_GET['token']);
        error_log('SUBZZ TOKEN RECEIVED: Length: ' . strlen($jwt_token) . ' characters');
        error_log('SUBZZ TOKEN RECEIVED: Preview: ' . substr($jwt_token, 0, 50) . '...');
        
        // Decode JWT token with detailed logging
        error_log('SUBZZ TOKEN DECODE: Starting JWT token decode process');
        $token_data = $this->decode_jwt_token($jwt_token);
        
        if (!$token_data) {
            error_log('SUBZZ TOKEN ERROR: JWT decode failed - token invalid or malformed');
            $this->display_styled_error_page('Invalid Token', 'Invalid or expired contract token. Please return to checkout and try again.');
            exit;
        }
        
        // Check token expiry with detailed logging
        $current_time = time();
        $expires_at = $token_data['expires_at'];
        $time_until_expiry = $expires_at - $current_time;
        
        error_log("SUBZZ TOKEN VALIDATION: Current time: {$current_time} (" . date('Y-m-d H:i:s', $current_time) . ")");
        error_log("SUBZZ TOKEN VALIDATION: Token expires: {$expires_at} (" . date('Y-m-d H:i:s', $expires_at) . ")");
        error_log("SUBZZ TOKEN VALIDATION: Time until expiry: {$time_until_expiry} seconds");
        
        if ($time_until_expiry <= 0) {
            error_log('SUBZZ TOKEN ERROR: JWT token has expired');
            $this->display_styled_error_page('Token Expired', 'Contract token has expired. Please return to checkout and try again.');
            exit;
        }
        
        error_log('SUBZZ TOKEN SUCCESS: JWT token is valid and not expired');
        error_log('SUBZZ TOKEN DATA: Reference ID: ' . $token_data['reference_id']);
        error_log('SUBZZ TOKEN DATA: Customer email: ' . $token_data['customer_email']);
        
        // Retrieve order data from Azure with API call logging
        error_log('SUBZZ API CALL: Retrieving order data from Azure for Reference ID: ' . $token_data['reference_id']);
        $order_data = $this->azure_client->retrieve_order_data($token_data['reference_id']);
        
        if (!$order_data) {
            error_log('SUBZZ API ERROR: Failed to retrieve order data from Azure');
            error_log('SUBZZ API ERROR: Reference ID used: ' . $token_data['reference_id']);
            $this->display_styled_error_page('Unable to Load Order', 'Unable to load order information. Please return to checkout and try again.');
            exit;
        }
        
        error_log('SUBZZ API SUCCESS: Order data retrieved from Azure');
        error_log('SUBZZ ORDER DATA: WooCommerce Order ID: ' . (isset($order_data['woocommerce_order_id']) ? $order_data['woocommerce_order_id'] : 'missing'));
        error_log('SUBZZ ORDER DATA: Customer email: ' . (isset($order_data['customer_email']) ? $order_data['customer_email'] : 'missing'));
        error_log('SUBZZ ORDER DATA: Customer name: ' . (isset($order_data['customer_data']['first_name']) ? $order_data['customer_data']['first_name'] . ' ' . $order_data['customer_data']['last_name'] : 'missing'));
        error_log('SUBZZ ORDER DATA: Order total: ' . (isset($order_data['order_totals']['total']) ? $order_data['order_totals']['currency'] . ' ' . $order_data['order_totals']['total'] : 'missing'));
        error_log('SUBZZ ORDER DATA: Items count: ' . (isset($order_data['order_items']) ? count($order_data['order_items']) : 'missing'));
        
        // Log detailed order items for subscription detection
        if (isset($order_data['order_items']) && is_array($order_data['order_items'])) {
            $subscription_items = array_filter($order_data['order_items'], function($item) { return isset($item['is_subscription']) && $item['is_subscription']; });
            error_log('SUBZZ ORDER DATA: Subscription items found: ' . count($subscription_items));
            
            foreach ($subscription_items as $index => $item) {
                error_log("SUBZZ ORDER DATA: Subscription item {$index} - ID: {$item['product_id']}, Name: {$item['name']}, Price: {$item['price']}");
            }
        }
        
        // VARIANT ENHANCEMENT: Generate contract and capture full response from Azure
        error_log('SUBZZ API CALL: Generating contract from Azure with variant support and order reference');
        error_log('SUBZZ API CALL: Using customer email: ' . $token_data['customer_email']);
        error_log('SUBZZ API CALL: Using reference ID for database retrieval: ' . $token_data['reference_id']);
        
        // Get FULL response including variant info
        // ORDER REFERENCE ENHANCEMENT: Pass reference_id for database order retrieval
        $contract_response = $this->azure_client->generate_contract(
            $token_data['customer_email'], 
            $order_data,
            $token_data['reference_id']  // Pass the reference ID for database lookup
        );
        
        if (!$contract_response || !isset($contract_response['contractHtml'])) {
            error_log('SUBZZ API ERROR: Failed to generate contract from Azure');
            error_log('SUBZZ API ERROR: Customer email used: ' . $token_data['customer_email']);
            $this->display_styled_error_page('Unable to Generate Contract', 'Unable to generate contract. Please return to checkout and try again.');
            exit;
        }
        
        // Extract contract HTML and variant info from response
        $contract_html = $contract_response['contractHtml'];
        $variant_info = isset($contract_response['variant_info']) ? $contract_response['variant_info'] : null;
        $agreement_reference = isset($contract_response['agreementReference']) ? $contract_response['agreementReference'] : null;
        
        error_log('SUBZZ API SUCCESS: Contract generated from Azure');
        error_log('SUBZZ CONTRACT HTML: Generated content length: ' . strlen($contract_html) . ' characters');
        error_log('SUBZZ CONTRACT HTML: Content preview: ' . substr(strip_tags($contract_html), 0, 100) . '...');
        
        // *** PDF FIX: STORE CONTRACT HTML FOR LATER USE ***
        // Store contract HTML in transient for retrieval during signature save
        // Transient expires in 1 hour (more than enough time for customer to sign)
        $transient_key = 'subzz_contract_html_' . $token_data['reference_id'];
        set_transient($transient_key, $contract_html, HOUR_IN_SECONDS);
        error_log('SUBZZ PDF FIX: Contract HTML stored in transient: ' . $transient_key);
        error_log('SUBZZ PDF FIX: Transient will expire in 1 hour');
        
        if ($variant_info) {
            error_log('SUBZZ VARIANT INFO: Received variant data from Azure');
            error_log('SUBZZ VARIANT INFO: Duration: ' . ($variant_info['subscription_duration_months'] ?? 'not set') . ' months');
            error_log('SUBZZ VARIANT INFO: Monthly amount: ' . ($variant_info['monthly_amount'] ?? 'not set'));
            error_log('SUBZZ VARIANT INFO: Total value: ' . ($variant_info['total_contract_value'] ?? 'not set'));
            error_log('SUBZZ VARIANT INFO: Full data: ' . json_encode($variant_info));
        } else {
            error_log('SUBZZ VARIANT INFO: No variant data received from Azure (using defaults)');
        }
        
        if ($agreement_reference) {
            error_log('SUBZZ CONTRACT DATA: Agreement reference: ' . $agreement_reference);
        }
        
        // Log what data will be passed to the display page
        error_log('SUBZZ PAGE DISPLAY: Preparing to display contract signature page with variant support');
        error_log('SUBZZ PAGE DISPLAY: JWT token length: ' . strlen($jwt_token));
        error_log('SUBZZ PAGE DISPLAY: Token data keys: ' . implode(', ', array_keys($token_data)));
        error_log('SUBZZ PAGE DISPLAY: Order data keys: ' . implode(', ', array_keys($order_data)));
        error_log('SUBZZ PAGE DISPLAY: Contract HTML ready: ' . (empty($contract_html) ? 'NO' : 'YES'));
        error_log('SUBZZ PAGE DISPLAY: Variant info available: ' . ($variant_info ? 'YES' : 'NO'));
        
        // Display contract signature page WITH VARIANT INFO
        $this->display_contract_signature_page($jwt_token, $token_data, $order_data, $contract_html, $variant_info);
        exit;
    }

    /**
     * Decode JWT token - STREAMLINED WITH FOCUSED LOGGING
     */
    private function decode_jwt_token($token) {
        error_log('SUBZZ JWT DECODE: Starting decode process for token length: ' . strlen($token));
        
        // Decode from base64 (matches generate_jwt_token method in payment handler)
        $decoded = base64_decode($token);
        if (!$decoded) {
            error_log('SUBZZ JWT ERROR: Base64 decode failed');
            return false;
        }
        
        error_log('SUBZZ JWT DECODE: Base64 decode successful, JSON length: ' . strlen($decoded));
        
        // Decode JSON
        $token_data = json_decode($decoded, true);
        if ($token_data === null) {
            error_log('SUBZZ JWT ERROR: JSON decode failed - ' . json_last_error_msg());
            error_log('SUBZZ JWT ERROR: Raw decoded content: ' . $decoded);
            return false;
        }
        
        // Validate required fields
        if (!isset($token_data['reference_id']) || !isset($token_data['customer_email'])) {
            error_log('SUBZZ JWT ERROR: Missing required fields in token');
            error_log('SUBZZ JWT ERROR: Available keys: ' . implode(', ', array_keys($token_data)));
            return false;
        }
        
        error_log('SUBZZ JWT SUCCESS: Token decoded successfully');
        error_log('SUBZZ JWT DATA: Reference ID: ' . $token_data['reference_id']);
        error_log('SUBZZ JWT DATA: Customer email: ' . $token_data['customer_email']);
        error_log('SUBZZ JWT DATA: Issued at: ' . date('Y-m-d H:i:s', $token_data['issued_at']));
        error_log('SUBZZ JWT DATA: Expires at: ' . date('Y-m-d H:i:s', $token_data['expires_at']));
        
        return $token_data;
    }

    /**
     * Display styled error page - STREAMLINED
     */
    private function display_styled_error_page($title, $message) {
        get_header();
        
        error_log('SUBZZ ERROR PAGE: Displaying error - ' . $title);
        ?>
        <div class="subzz-contract-page">
            <div class="container">
                <div class="contract-header">
                    <h1>📋 Contract Signature Error</h1>
                    <div class="progress-indicator">
                        ✅ Order Details → ❌ Agreement Error → ⏸ Payment → ⭕ Complete
                    </div>
                </div>

                <div class="contract-content">
                    <div class="error-message-section">
                        <h2><?php echo esc_html($title); ?></h2>
                        <p class="error-description"><?php echo esc_html($message); ?></p>
                        
                        <div class="error-actions">
                            <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="button primary">
                                ← Return to Checkout
                            </a>
                        </div>
                        
                        <div class="help-section">
                            <h3>Need Help?</h3>
                            <p>If you continue to experience issues, please contact our support team.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        
        get_footer();
    }
    
    /**
     * Display the contract signature page - ENHANCED WITH VARIANT AND LEGAL COMPLIANCE SUPPORT
     * LEGAL COMPLIANCE FIX (Oct 9, 2025): Restructured HTML to group all legal fields together
     */
    private function display_contract_signature_page($jwt_token, $token_data, $order_data, $contract_html, $variant_info = null) {
        get_header();
        
        error_log('SUBZZ PAGE RENDER: Starting contract signature page render with variant support');
        
        // Log what customer data will be displayed
        $customer_name = $order_data['customer_data']['first_name'] . ' ' . $order_data['customer_data']['last_name'];
        $customer_email = $order_data['customer_data']['email'];
        $customer_phone = isset($order_data['customer_data']['phone']) ? $order_data['customer_data']['phone'] : 'Not provided';
        
        error_log('SUBZZ PAGE RENDER: Customer info - Name: ' . $customer_name . ', Email: ' . $customer_email . ', Phone: ' . $customer_phone);
        
        // Extract variant details for display
        $subscription_months = 12; // Default
        $monthly_amount = $order_data['order_totals']['total'];
        $total_contract_value = $monthly_amount * $subscription_months;
        $currency = $order_data['order_totals']['currency'];
        
        if ($variant_info) {
            $subscription_months = $variant_info['subscription_duration_months'] ?? 12;
            $monthly_amount = $variant_info['monthly_amount'] ?? $monthly_amount;
            $total_contract_value = $variant_info['total_contract_value'] ?? ($monthly_amount * $subscription_months);
            
            error_log('SUBZZ PAGE RENDER: Using variant info - ' . $subscription_months . ' months at ' . $currency . ' ' . $monthly_amount);
            error_log('SUBZZ PAGE RENDER: Total contract value: ' . $currency . ' ' . $total_contract_value);
        } else {
            error_log('SUBZZ PAGE RENDER: No variant info - using defaults (12 months)');
        }
        
        // Log subscription items that will be displayed
        $subscription_items = array_filter($order_data['order_items'], function($item) { 
            return isset($item['is_subscription']) && $item['is_subscription']; 
        });
        
        error_log('SUBZZ PAGE RENDER: Displaying ' . count($subscription_items) . ' subscription items');
        
        foreach ($subscription_items as $index => $item) {
            error_log("SUBZZ PAGE RENDER: Item {$index} - Name: {$item['name']}, Price: {$currency} {$item['price']}");
        }
        
        // Log contract content status
        error_log('SUBZZ PAGE RENDER: Contract HTML status: ' . (empty($contract_html) ? 'EMPTY' : 'HAS CONTENT'));
        if (!empty($contract_html)) {
            error_log('SUBZZ PAGE RENDER: Contract HTML length: ' . strlen($contract_html) . ' characters');
        }
        
        ?>
        
        <style>
        /* ============================================================================
           LEGAL COMPLIANCE STYLING - Added Oct 9, 2025
           Professional, accessible styling for legal compliance section
           ============================================================================ */
        
        /* Legal Compliance Section - Main Container */
        .legal-compliance-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 3px solid #495057;
            border-radius: 12px;
            padding: 35px;
            margin-bottom: 35px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .legal-compliance-section h2 {
            color: #2c3e50;
            margin-bottom: 30px;
            font-size: 26px;
            font-weight: 700;
            text-align: center;
            padding-bottom: 15px;
            border-bottom: 3px solid #007bff;
        }

        /* Compliance Steps */
        .compliance-step {
            background: white;
            border: 2px solid #dee2e6;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 25px;
            transition: all 0.3s ease;
        }

        .compliance-step:hover {
            border-color: #007bff;
            box-shadow: 0 3px 12px rgba(0,123,255,0.15);
        }

        .compliance-step:last-child {
            margin-bottom: 0;
        }

        .compliance-step h3 {
            color: #495057;
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #007bff;
            display: flex;
            align-items: center;
        }

        .compliance-step h3:before {
            content: '';
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            border-radius: 50%;
            margin-right: 12px;
            flex-shrink: 0;
        }

        /* Typed Name Section - Grid Layout */
        .typed-name-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }

        @media (max-width: 768px) {
            .typed-name-section {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }

        /* Form Fields */
        .form-field {
            margin-bottom: 0;
        }

        .form-field label {
            display: block;
            font-weight: 600;
            margin-bottom: 10px;
            color: #2c3e50;
            font-size: 15px;
        }

        .form-field input[type="text"] {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #ced4da;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .form-field input[type="text"]:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
        }

        .form-field input.field-valid {
            border-color: #28a745 !important;
            background-color: #f0fff4;
        }

        .form-field input.field-valid:focus {
            box-shadow: 0 0 0 3px rgba(40,167,69,0.1);
        }

        .form-field input.field-warning {
            border-color: #ffc107 !important;
            background-color: #fffbf0;
        }

        .field-hint {
            display: block;
            font-size: 13px;
            color: #6c757d;
            margin-top: 8px;
            font-style: italic;
            line-height: 1.4;
        }

        /* Consent Checkboxes */
        .consent-checkboxes {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .consent-checkbox {
            background: white;
            border: 2px solid #dee2e6;
            border-radius: 10px;
            padding: 20px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .consent-checkbox:hover {
            border-color: #007bff;
            box-shadow: 0 2px 10px rgba(0,123,255,0.1);
            transform: translateY(-2px);
        }

        .consent-checkbox.consent-checked {
            background: linear-gradient(135deg, #e7f3ff 0%, #d0e8ff 100%);
            border-color: #007bff;
            border-width: 3px;
        }

        .consent-checkbox label {
            display: flex;
            align-items: flex-start;
            cursor: pointer;
            margin: 0;
            line-height: 1.6;
        }

        .consent-checkbox input[type="checkbox"] {
            width: 22px;
            height: 22px;
            margin-right: 15px;
            margin-top: 2px;
            cursor: pointer;
            flex-shrink: 0;
            accent-color: #007bff;
        }

        .consent-checkbox strong {
            color: #2c3e50;
            display: block;
            margin-bottom: 5px;
        }

        /* Signature Section - Updated Styling */
        .signature-section {
            background: white;
            border: 3px solid #495057;
            border-radius: 12px;
            padding: 35px;
            margin-bottom: 35px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .signature-section h2 {
            color: #2c3e50;
            margin-bottom: 15px;
            font-size: 26px;
            font-weight: 700;
        }

        .signature-section > p {
            color: #6c757d;
            font-size: 15px;
            margin-bottom: 25px;
        }

        .signature-pad-container {
            border: 3px solid #dee2e6;
            border-radius: 10px;
            padding: 15px;
            background: #fafafa;
            margin-top: 20px;
            transition: all 0.3s ease;
        }

        .signature-pad-container.signature-valid {
            border-color: #28a745;
            background: linear-gradient(135deg, #f0fff4 0%, #e6f9ed 100%);
            box-shadow: 0 0 0 3px rgba(40,167,69,0.1);
        }

        .signature-pad-container.signature-empty {
            border-color: #dc3545;
            background: #fff5f5;
        }

        #signature-pad {
            display: block;
            cursor: crosshair;
            background: white;
            border-radius: 6px;
        }

        .signature-controls {
            margin-top: 15px;
            text-align: center;
        }

        /* Contract Actions - Button Styling */
        .contract-actions {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            margin-top: 35px;
        }

        @media (max-width: 768px) {
            .contract-actions {
                flex-direction: column;
            }
        }

        .button.primary {
            flex: 1;
            font-size: 18px;
            font-weight: 600;
            padding: 16px 32px;
        }

        .button.primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .legal-compliance-section {
                padding: 25px 20px;
            }
            
            .compliance-step {
                padding: 20px 15px;
            }
            
            .signature-section {
                padding: 25px 20px;
            }
        }
        </style>
        
        <div class="subzz-contract-page">
            <div class="container">
                <div class="contract-header">
                    <h1>📋 Subscription Agreement Review</h1>
                    <div class="progress-indicator">
                        ✅ Order Details → ✅ Agreement → ⏳ Payment → ⭕ Complete
                    </div>
                </div>

                <div class="contract-content">
                    <!-- Order Summary from Azure -->
                    <div class="order-summary-section">
                        <h2>Your Subscription Details</h2>
                        <div class="customer-info">
                            <h3>Customer Information</h3>
                            <p><strong>Name:</strong> <?php echo esc_html($customer_name); ?></p>
                            <p><strong>Email:</strong> <?php echo esc_html($customer_email); ?></p>
                            <?php if (!empty($order_data['customer_data']['phone'])): ?>
                            <p><strong>Phone:</strong> <?php echo esc_html($order_data['customer_data']['phone']); ?></p>
                            <?php endif; ?>
                        </div>
                        
                        <div class="order-items">
                            <?php foreach ($subscription_items as $item): ?>
                                <div class="subscription-item">
                                    <h3><?php echo esc_html($item['name']); ?></h3>
                                    <p><strong>Subscription Duration:</strong> <?php echo esc_html($subscription_months); ?> months</p>
                                    <p><strong>Monthly Payment:</strong> <?php echo esc_html($currency); ?> <?php echo esc_html(number_format((float)$monthly_amount, 2)); ?></p>
                                    <p><strong>Total Contract Value:</strong> <?php echo esc_html($currency); ?> <?php echo esc_html(number_format((float)$total_contract_value, 2)); ?></p>
                                    <p><strong>Quantity:</strong> <?php echo esc_html($item['quantity']); ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="order-totals">
                            <p><strong>Total Monthly Payment: <?php echo esc_html($currency); ?> <?php echo esc_html(number_format((float)$monthly_amount, 2)); ?></strong></p>
                        </div>
                    </div>

                    <!-- Contract Terms from Azure -->
                    <div class="contract-terms-section">
                        <h2>Subscription Agreement Terms</h2>
                        <div class="contract-text">
                            <?php 
                            if (!empty($contract_html)) {
                                echo $contract_html;
                                error_log('SUBZZ PAGE RENDER: Contract HTML displayed successfully');
                            } else {
                                echo '<p><strong>Error:</strong> Contract terms could not be loaded. Please contact support.</p>';
                                error_log('SUBZZ PAGE RENDER ERROR: Contract HTML is empty - displaying error message');
                            }
                            ?>
                        </div>
                        
                        <div class="pdf-download">
                            <a href="<?php echo home_url('/wp-content/uploads/subzz-agreement.pdf'); ?>" target="_blank" class="button secondary">
                                📄 Download Full Agreement (PDF)
                            </a>
                        </div>
                    </div>

                    <!-- ============================================================================
                         LEGAL COMPLIANCE SECTION - RESTRUCTURED (Oct 9, 2025)
                         All legal compliance fields grouped together in clear steps
                         ============================================================================ -->
                    <div class="legal-compliance-section">
                        <h2>Legal Verification & Consent</h2>
                        
                        <!-- Step 1: Type Your Legal Information -->
                        <div class="compliance-step">
                            <h3>Step 1: Type Your Legal Information</h3>
                            <div class="typed-name-section">
                                <div class="form-field">
                                    <label for="typed-full-name">Full Legal Name (as it appears on your ID): *</label>
                                    <input type="text" id="typed-full-name" name="typed_full_name" 
                                           placeholder="Enter your full name" required 
                                           value="<?php echo esc_attr($customer_name); ?>">
                                    <small class="field-hint">Enter your complete first and last name exactly as it appears on your ID</small>
                                </div>
                                
                                <div class="form-field">
                                    <label for="typed-initials">Your Initials: *</label>
                                    <input type="text" id="typed-initials" name="typed_initials" 
                                           placeholder="e.g., JDS" maxlength="10" required>
                                    <small class="field-hint">Minimum 2 characters, will be automatically converted to uppercase</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Step 2: Legal Consent & Acknowledgment (CHECKBOXES MOVED HERE) -->
                        <div class="compliance-step">
                            <h3>Step 2: Legal Consent & Acknowledgment</h3>
                            <div class="consent-checkboxes">
                                <div class="consent-checkbox">
                                    <label>
                                        <input type="checkbox" id="electronic-consent" required>
                                        <span>
                                            <strong>Electronic Signature Consent:</strong><br>
                                            I consent to signing this agreement electronically and agree that my electronic 
                                            signature is the legal equivalent of my manual signature on this agreement, in 
                                            accordance with the South African Electronic Communications and Transactions Act (ECTA).
                                        </span>
                                    </label>
                                </div>
                                
                                <div class="consent-checkbox">
                                    <label>
                                        <input type="checkbox" id="terms-consent" required>
                                        <span>
                                            <strong>Terms & Conditions Acceptance:</strong><br>
                                            I confirm that I have read, understood, and agree to all terms and conditions of this 
                                            <?php echo esc_html($subscription_months); ?>-month subscription agreement with a 
                                            total value of <?php echo esc_html($currency); ?> 
                                            <?php echo esc_html(number_format((float)$total_contract_value, 2)); ?>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Signature Section - SIMPLIFIED (checkboxes moved out) -->
                    <div class="signature-section">
                        <h2>Step 3: Digital Signature</h2>
                        <p>By signing below, you confirm all information above is correct and authorize monthly automatic payments for the subscription period.</p>
                        
                        <div class="signature-pad-container">
                            <canvas id="signature-pad" width="600" height="200"></canvas>
                            <div class="signature-controls">
                                <button id="clear-signature" type="button" class="button secondary">Clear Signature</button>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="contract-actions">
                        <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="button secondary">
                            ← Back to Checkout
                        </a>
                        
                        <button id="sign-agreement" class="button primary" disabled>
                            Sign Agreement & Continue to Payment →
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        // Pass data to JavaScript with variant support
        window.subzzContractToken = '<?php echo esc_js($jwt_token); ?>';
        window.subzzReferenceId = '<?php echo esc_js($token_data['reference_id']); ?>';
        window.subzzCustomerEmail = '<?php echo esc_js($token_data['customer_email']); ?>';
        window.subzzAjaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
        window.subzzNonce = '<?php echo wp_create_nonce('subzz_signature'); ?>';
        
        // Pass variant info to JavaScript
        window.subzzVariantInfo = <?php echo json_encode($variant_info ?: new stdClass()); ?>;
        
        console.log('SUBZZ PAGE JS: Contract page JavaScript variables loaded');
        console.log('SUBZZ PAGE JS: Reference ID:', window.subzzReferenceId);
        console.log('SUBZZ PAGE JS: Customer email:', window.subzzCustomerEmail);
        console.log('SUBZZ PAGE JS: Token length:', window.subzzContractToken.length);
        console.log('SUBZZ PAGE JS: Variant info:', window.subzzVariantInfo);
        </script>

        <?php
        get_footer();
        
        error_log('SUBZZ PAGE RENDER: Contract signature page rendered successfully with legal compliance structure');
    }

    /**
     * Save signature via AJAX - ENHANCED WITH VARIANT AND LEGAL COMPLIANCE SUPPORT + PDF FIX
     */
    public function save_signature() {
        error_log('=== SUBZZ SIGNATURE SAVE: Enhanced AJAX request received with variant support and PDF fix ===');
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'subzz_signature')) {
            error_log('SUBZZ SIGNATURE ERROR: Nonce verification failed');
            wp_die('Security check failed');
        }

        // Extract and log POST data
        $jwt_token = sanitize_text_field($_POST['token']);
        $reference_id = sanitize_text_field($_POST['reference_id']);
        $customer_email = sanitize_email($_POST['customer_email']);
        $signature_data = sanitize_textarea_field($_POST['signature_data']);
        
        // Extract new legal compliance fields
        $typed_full_name = isset($_POST['typed_full_name']) ? sanitize_text_field($_POST['typed_full_name']) : '';
        $typed_initials = isset($_POST['typed_initials']) ? sanitize_text_field($_POST['typed_initials']) : '';
        $electronic_consent = isset($_POST['electronic_consent']) ? filter_var($_POST['electronic_consent'], FILTER_VALIDATE_BOOLEAN) : false;
        $terms_consent = isset($_POST['terms_consent']) ? filter_var($_POST['terms_consent'], FILTER_VALIDATE_BOOLEAN) : false;
        
        // Extract variant info if provided
        $variant_info = isset($_POST['variant_info']) ? json_decode(stripslashes($_POST['variant_info']), true) : null;
        
        error_log('SUBZZ SIGNATURE DATA: Reference ID: ' . $reference_id);
        error_log('SUBZZ SIGNATURE DATA: Customer email: ' . $customer_email);
        error_log('SUBZZ SIGNATURE DATA: Signature data length: ' . strlen($signature_data) . ' characters');
        error_log('SUBZZ SIGNATURE DATA: JWT token length: ' . strlen($jwt_token) . ' characters');
        
        // Log legal compliance data
        error_log('SUBZZ SIGNATURE LEGAL: Typed full name: ' . $typed_full_name);
        error_log('SUBZZ SIGNATURE LEGAL: Typed initials: ' . $typed_initials);
        error_log('SUBZZ SIGNATURE LEGAL: Electronic consent: ' . ($electronic_consent ? 'YES' : 'NO'));
        error_log('SUBZZ SIGNATURE LEGAL: Terms consent: ' . ($terms_consent ? 'YES' : 'NO'));
        
        // Log variant info
        if ($variant_info) {
            error_log('SUBZZ SIGNATURE VARIANT: Duration: ' . ($variant_info['subscription_duration_months'] ?? 'not set') . ' months');
            error_log('SUBZZ SIGNATURE VARIANT: Monthly amount: ' . ($variant_info['monthly_amount'] ?? 'not set'));
            error_log('SUBZZ SIGNATURE VARIANT: Total value: ' . ($variant_info['total_contract_value'] ?? 'not set'));
        } else {
            error_log('SUBZZ SIGNATURE VARIANT: No variant info provided');
        }
        
        // Verify JWT token matches the signature request
        error_log('SUBZZ SIGNATURE VALIDATION: Verifying JWT token');
        $token_data = $this->decode_jwt_token($jwt_token);
        
        if (!$token_data) {
            error_log('SUBZZ SIGNATURE ERROR: JWT token validation failed during signature save');
            wp_send_json_error('Invalid token');
            return;
        }
        
        if ($token_data['reference_id'] !== $reference_id) {
            error_log('SUBZZ SIGNATURE ERROR: Reference ID mismatch - Token: ' . $token_data['reference_id'] . ', Request: ' . $reference_id);
            wp_send_json_error('Token reference mismatch');
            return;
        }
        
        error_log('SUBZZ SIGNATURE VALIDATION: JWT token verified successfully');

        // *** PDF FIX: RETRIEVE CONTRACT HTML FROM TRANSIENT ***
        $transient_key = 'subzz_contract_html_' . $reference_id;
        $contract_html = get_transient($transient_key);
        
        if ($contract_html) {
            error_log('SUBZZ PDF FIX: Contract HTML retrieved from transient: ' . $transient_key);
            error_log('SUBZZ PDF FIX: Contract HTML length: ' . strlen($contract_html) . ' characters');
            
            // Delete transient after retrieval (cleanup)
            delete_transient($transient_key);
            error_log('SUBZZ PDF FIX: Transient deleted after retrieval');
        } else {
            error_log('SUBZZ PDF WARNING: Contract HTML not found in transient: ' . $transient_key);
            error_log('SUBZZ PDF WARNING: PDF generation may fail - contract_html will be NULL');
        }

        // Prepare additional data for Azure including variant and legal fields
        // *** PDF FIX: ADD CONTRACT_HTML AND SIGNATURE_IMAGE_DATA ***
        $additional_data = array(
            'typed_full_name' => $typed_full_name,
            'typed_initials' => $typed_initials,
            'electronic_consent' => $electronic_consent,
            'terms_consent' => $terms_consent,
            'contract_html' => $contract_html,           // PDF FIX: Added
            'signature_image_data' => $signature_data     // PDF FIX: Added (renamed from signature_data)
        );
        
        // Add variant info if available
        if ($variant_info) {
            $additional_data['variant_info'] = $variant_info;
        }

        error_log('SUBZZ PDF FIX: Additional data now includes contract_html and signature_image_data');
        error_log('SUBZZ PDF FIX: contract_html status: ' . ($contract_html ? 'PRESENT' : 'NULL'));
        error_log('SUBZZ PDF FIX: signature_image_data length: ' . strlen($signature_data) . ' characters');

        // Store signature in Azure with enhanced data
        error_log('SUBZZ API CALL: Storing signature in Azure with variant, legal compliance, and PDF data');
        error_log('SUBZZ API CALL: Using customer email: ' . $customer_email);
        error_log('SUBZZ API CALL: Using reference ID: ' . $reference_id);
        error_log('SUBZZ API CALL: Additional data keys: ' . implode(', ', array_keys($additional_data)));
        
        $signature_stored = $this->azure_client->store_signature($customer_email, $signature_data, $reference_id, $additional_data);
        
        if (!$signature_stored) {
            error_log('SUBZZ API ERROR: Failed to store signature in Azure');
            error_log('SUBZZ API ERROR: Customer email: ' . $customer_email);
            error_log('SUBZZ API ERROR: Reference ID: ' . $reference_id);
            wp_send_json_error('Failed to save signature. Please try again.');
            return;
        }
        
        error_log('SUBZZ API SUCCESS: Signature stored in Azure successfully with all compliance and PDF data');
        
        // Find and update the corresponding WooCommerce order
        error_log('SUBZZ ORDER UPDATE: Looking for WooCommerce order with reference ID: ' . $reference_id);
        $orders = wc_get_orders(array(
            'meta_key' => '_subzz_reference_id',
            'meta_value' => $reference_id,
            'limit' => 1
        ));
        
        if (!empty($orders)) {
            $order = $orders[0];
            $order_id = $order->get_id();
            
            error_log("SUBZZ ORDER UPDATE: Found WooCommerce Order {$order_id}");
            
            // Update order meta to reflect signature completion with variant info
            $order->add_meta_data('_subzz_signature_completed', 'yes');
            $order->add_meta_data('_subzz_signature_completed_at', time());
            $order->add_meta_data('_subzz_contract_signed', 'yes');
            
            // Store variant info in order meta
            if ($variant_info) {
                $order->add_meta_data('_subzz_subscription_months', $variant_info['subscription_duration_months'] ?? 12);
                $order->add_meta_data('_subzz_monthly_amount', $variant_info['monthly_amount'] ?? 0);
                $order->add_meta_data('_subzz_total_contract_value', $variant_info['total_contract_value'] ?? 0);
            }
            
            // Store legal compliance confirmation
            $order->add_meta_data('_subzz_typed_name', $typed_full_name);
            $order->add_meta_data('_subzz_typed_initials', $typed_initials);
            $order->add_meta_data('_subzz_electronic_consent', $electronic_consent ? 'yes' : 'no');
            $order->add_meta_data('_subzz_terms_consent', $terms_consent ? 'yes' : 'no');
            
            // Clear redirect flags to prevent further redirects
            $order->update_meta_data('_subzz_redirect_required', 'no');
            $order->update_meta_data('_subzz_redirect_processed', 'yes');
            
            $order->save();
            
            error_log("SUBZZ ORDER UPDATE: Updated Order {$order_id} with signature completion and variant data");
            error_log("SUBZZ ORDER UPDATE: Redirect flags cleared to prevent duplicate redirects");
            
        } else {
            error_log('SUBZZ ORDER WARNING: No WooCommerce order found with reference ID: ' . $reference_id);
        }
        
        // Update order status to signature_completed
        error_log('SUBZZ API CALL: Updating order status to signature_completed');
        $status_updated = $this->azure_client->update_order_status($reference_id, 'signature_completed');

        if (!$status_updated) {
            error_log('SUBZZ API WARNING: Failed to update order status in Azure');
            error_log('SUBZZ API WARNING: Signature was saved but order status not updated');
        } else {
            error_log('SUBZZ API SUCCESS: Order status updated to signature_completed');
        }
        
        // Generate return URL to payment page with variant info
        $return_url = add_query_arg(array(
            'reference_id' => $reference_id,
            'signature_confirmed' => 'yes',
            'subscription_months' => $variant_info['subscription_duration_months'] ?? 12
        ), home_url('/subscription-payment/'));

        error_log('SUBZZ SIGNATURE SUCCESS: Complete signature workflow finished with variant support and PDF data');
        error_log('SUBZZ SIGNATURE SUCCESS: Redirecting to payment page: ' . $return_url);

        wp_send_json_success(array(
            'message' => 'Contract signed successfully! Redirecting to payment...',
            'redirect_url' => $return_url,
            'reference_id' => $reference_id,
            'variant_info' => $variant_info
        ));
    }
}
?>