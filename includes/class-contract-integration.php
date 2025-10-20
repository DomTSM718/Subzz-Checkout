<?php
/**
 * Contract Integration Class - HYBRID Architecture (Fixed Button Visibility)
 * 
 * CHANGELOG:
 * - Oct 19, 2025: FIXED - Moved contract-actions inside step-2-3-container
 * - Oct 19, 2025: HYBRID Architecture refactoring - Removed all inline JavaScript
 * - Oct 19, 2025: Billing date selection moved to billing-date-handler.js
 * - Oct 19, 2025: Signature handling moved to signature-handler.js
 * - Oct 19, 2025: Kept only PHP logic, HTML structure, and global variables
 * 
 * PREVIOUS CHANGES:
 * - Oct 19, 2025: Added billing date selection (Step 1)
 * - Oct 19, 2025: Progressive disclosure UI implementation
 * - Oct 19, 2025: Contract regeneration with selected billing date
 * - Oct 9, 2025: Legal compliance restructure
 * - Oct 7, 2025: PDF generation fix (contract HTML storage)
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
        
        // AJAX handler for contract regeneration with billing date
        add_action('wp_ajax_subzz_regenerate_contract', array($this, 'regenerate_contract_with_billing_date'));
        add_action('wp_ajax_nopriv_subzz_regenerate_contract', array($this, 'regenerate_contract_with_billing_date'));
    }

    /**
     * Handle contract signature page display - HYBRID ARCHITECTURE
     */
    public function handle_contract_signature_page() {
        global $wp;
        
        // Check if this is the contract signature URL
        if (!isset($wp->request) || $wp->request !== 'contract-signature') {
            return;
        }
        
        error_log('=== SUBZZ CONTRACT PAGE: HYBRID Architecture request received ===');
        error_log('SUBZZ CONTRACT PAGE: URL path: ' . $wp->request);
        
        // Get and validate JWT token from URL
        if (!isset($_GET['token'])) {
            error_log('SUBZZ CONTRACT ERROR: No token provided in URL');
            $this->display_styled_error_page('Missing Contract Token', 'Missing contract token. Please return to checkout and try again.');
            exit;
        }

        $jwt_token = sanitize_text_field($_GET['token']);
        error_log('SUBZZ TOKEN RECEIVED: Length: ' . strlen($jwt_token) . ' characters');
        
        // Decode JWT token
        error_log('SUBZZ TOKEN DECODE: Starting JWT token decode process');
        $token_data = $this->decode_jwt_token($jwt_token);
        
        if (!$token_data) {
            error_log('SUBZZ TOKEN ERROR: JWT decode failed - token invalid or malformed');
            $this->display_styled_error_page('Invalid Token', 'Invalid or expired contract token. Please return to checkout and try again.');
            exit;
        }
        
        // Check token expiry
        $current_time = time();
        $expires_at = $token_data['expires_at'];
        $time_until_expiry = $expires_at - $current_time;
        
        error_log("SUBZZ TOKEN VALIDATION: Current time: {$current_time}");
        error_log("SUBZZ TOKEN VALIDATION: Token expires: {$expires_at}");
        error_log("SUBZZ TOKEN VALIDATION: Time until expiry: {$time_until_expiry} seconds");
        
        if ($time_until_expiry <= 0) {
            error_log('SUBZZ TOKEN ERROR: JWT token has expired');
            $this->display_styled_error_page('Token Expired', 'Contract token has expired. Please return to checkout and try again.');
            exit;
        }
        
        error_log('SUBZZ TOKEN SUCCESS: JWT token is valid and not expired');
        error_log('SUBZZ TOKEN DATA: Reference ID: ' . $token_data['reference_id']);
        error_log('SUBZZ TOKEN DATA: Customer email: ' . $token_data['customer_email']);
        
        // Retrieve order data from Azure
        error_log('SUBZZ API CALL: Retrieving order data from Azure for Reference ID: ' . $token_data['reference_id']);
        $order_data = $this->azure_client->retrieve_order_data($token_data['reference_id']);
        
        if (!$order_data) {
            error_log('SUBZZ API ERROR: Failed to retrieve order data from Azure');
            $this->display_styled_error_page('Unable to Load Order', 'Unable to load order information. Please return to checkout and try again.');
            exit;
        }
        
        error_log('SUBZZ API SUCCESS: Order data retrieved from Azure');
        error_log('SUBZZ HYBRID ARCHITECTURE: Contract will be generated after billing date selection');
        
        // Display contract signature page - contract generation deferred to JavaScript
        $this->display_contract_signature_page($jwt_token, $token_data, $order_data, null, null);
        exit;
    }

    /**
     * AJAX handler to regenerate contract with billing date
     */
    public function regenerate_contract_with_billing_date() {
        error_log('=== SUBZZ BILLING DATE: Contract regeneration requested ===');
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'subzz_signature')) {
            error_log('SUBZZ BILLING DATE ERROR: Nonce verification failed');
            wp_send_json_error('Security check failed');
            return;
        }

        // Extract POST data
        $jwt_token = sanitize_text_field($_POST['token']);
        $reference_id = sanitize_text_field($_POST['reference_id']);
        $customer_email = sanitize_email($_POST['customer_email']);
        $billing_day = intval($_POST['billing_day']);
        
        error_log('SUBZZ BILLING DATE: Reference ID: ' . $reference_id);
        error_log('SUBZZ BILLING DATE: Customer email: ' . $customer_email);
        error_log('SUBZZ BILLING DATE: Selected billing day: ' . $billing_day);
        
        // Validate billing day
        if (!in_array($billing_day, array(1, 8, 15, 22))) {
            error_log('SUBZZ BILLING DATE ERROR: Invalid billing day selected: ' . $billing_day);
            wp_send_json_error('Invalid billing day selected');
            return;
        }
        
        // Verify JWT token
        $token_data = $this->decode_jwt_token($jwt_token);
        if (!$token_data || $token_data['reference_id'] !== $reference_id) {
            error_log('SUBZZ BILLING DATE ERROR: JWT token validation failed');
            wp_send_json_error('Invalid token');
            return;
        }
        
        // Retrieve order data
        error_log('SUBZZ BILLING DATE: Retrieving order data from Azure');
        $order_data = $this->azure_client->retrieve_order_data($reference_id);
        
        if (!$order_data) {
            error_log('SUBZZ BILLING DATE ERROR: Failed to retrieve order data');
            wp_send_json_error('Unable to load order information');
            return;
        }
        
        // Generate contract WITH billing date
        error_log('SUBZZ BILLING DATE: Generating contract with billing day: ' . $billing_day);
        $contract_response = $this->azure_client->generate_contract(
            $customer_email,
            $order_data,
            $reference_id,
            $billing_day
        );
        
        if (!$contract_response || !isset($contract_response['contractHtml'])) {
            error_log('SUBZZ BILLING DATE ERROR: Failed to generate contract from Azure');
            wp_send_json_error('Unable to generate contract');
            return;
        }
        
        // Extract contract HTML and billing info
        $contract_html = $contract_response['contractHtml'];
        $variant_info = isset($contract_response['variant_info']) ? $contract_response['variant_info'] : null;
        
        // Store contract HTML in transient for later use during signature
        $transient_key = 'subzz_contract_html_' . $reference_id;
        set_transient($transient_key, $contract_html, HOUR_IN_SECONDS);
        error_log('SUBZZ BILLING DATE: Contract HTML stored in transient: ' . $transient_key);
        
        // Extract billing date information from response
        $billing_info = array(
            'billing_day_of_month' => isset($contract_response['billing_day_of_month']) ? $contract_response['billing_day_of_month'] : $billing_day,
            'billing_day_formatted' => isset($contract_response['billing_day_formatted']) ? $contract_response['billing_day_formatted'] : $this->format_billing_day($billing_day),
            'first_billing_date' => isset($contract_response['first_billing_date']) ? $contract_response['first_billing_date'] : null,
            'next_billing_date' => isset($contract_response['next_billing_date']) ? $contract_response['next_billing_date'] : null,
            'days_of_coverage' => isset($contract_response['days_of_coverage']) ? $contract_response['days_of_coverage'] : null
        );
        
        error_log('SUBZZ BILLING DATE SUCCESS: Contract generated with billing date');
        error_log('SUBZZ BILLING DATE: Next billing date: ' . ($billing_info['next_billing_date'] ?? 'not set'));
        error_log('SUBZZ BILLING DATE: Days of coverage: ' . ($billing_info['days_of_coverage'] ?? 'not set'));
        
        // Return success with contract HTML and billing info
        wp_send_json_success(array(
            'contract_html' => $contract_html,
            'variant_info' => $variant_info,
            'billing_info' => $billing_info
        ));
    }
    
    /**
     * Helper function to format billing day (e.g., 1 -> "1st", 22 -> "22nd")
     */
    private function format_billing_day($day) {
        $suffix = 'th';
        if ($day == 1 || $day == 21 || $day == 31) {
            $suffix = 'st';
        } elseif ($day == 2 || $day == 22) {
            $suffix = 'nd';
        } elseif ($day == 3 || $day == 23) {
            $suffix = 'rd';
        }
        return $day . $suffix;
    }

    /**
     * Decode JWT token
     */
    private function decode_jwt_token($token) {
        error_log('SUBZZ JWT DECODE: Starting decode process for token length: ' . strlen($token));
        
        // Decode from base64
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
            return false;
        }
        
        // Validate required fields
        if (!isset($token_data['reference_id']) || !isset($token_data['customer_email'])) {
            error_log('SUBZZ JWT ERROR: Missing required fields in token');
            return false;
        }
        
        error_log('SUBZZ JWT SUCCESS: Token decoded successfully');
        
        return $token_data;
    }

    /**
     * Display styled error page
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
                        ✅ Order Details → ❌ Agreement Error → ⏸ Payment → ⏹ Complete
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
     * Display the contract signature page - HYBRID ARCHITECTURE (HTML + CSS only)
     * 
     * NOTE: All JavaScript has been moved to separate files:
     * - billing-date-handler.js (Step 1: Billing date selection)
     * - signature-handler.js (Steps 2 & 3: Legal compliance and signature)
     */
    private function display_contract_signature_page($jwt_token, $token_data, $order_data, $contract_html = null, $variant_info = null) {
        get_header();
        
        error_log('SUBZZ PAGE RENDER: Starting contract signature page render (HYBRID architecture)');
        
        // Extract customer and order data
        $customer_name = $order_data['customer_data']['first_name'] . ' ' . $order_data['customer_data']['last_name'];
        $customer_email = $order_data['customer_data']['email'];
        $customer_phone = isset($order_data['customer_data']['phone']) ? $order_data['customer_data']['phone'] : 'Not provided';
        
        // Extract variant details for display
        $subscription_months = 12; // Default
        $monthly_amount = $order_data['order_totals']['total'];
        $total_contract_value = $monthly_amount * $subscription_months;
        $currency = $order_data['order_totals']['currency'];
        
        if ($variant_info) {
            $subscription_months = $variant_info['subscription_duration_months'] ?? 12;
            $monthly_amount = $variant_info['monthly_amount'] ?? $monthly_amount;
            $total_contract_value = $variant_info['total_contract_value'] ?? ($monthly_amount * $subscription_months);
        }
        
        // Get subscription items
        $subscription_items = array_filter($order_data['order_items'], function($item) { 
            return isset($item['is_subscription']) && $item['is_subscription']; 
        });
        
        ?>
        
        <style>
        /* ============================================================================
           BASE STYLES AND EXISTING LEGAL COMPLIANCE STYLING
           ============================================================================ */
        
        .subzz-contract-page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .container {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .contract-header {
            text-align: center;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 3px solid #007bff;
        }
        
        .contract-header h1 {
            color: #2c3e50;
            margin-bottom: 15px;
            font-size: 32px;
        }
        
        .progress-indicator {
            font-size: 14px;
            color: #6c757d;
            font-weight: 500;
        }
        
        /* ============================================================================
           BILLING DATE SELECTION - STYLES
           ============================================================================ */
        
        .contract-step {
            margin-bottom: 40px;
            transition: opacity 0.3s ease;
        }
        
        .contract-step.hidden {
            display: none;
        }
        
        .step-header {
            display: flex;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 3px solid #007bff;
        }
        
        .step-number {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 700;
            margin-right: 15px;
            flex-shrink: 0;
        }
        
        .step-header h2 {
            color: #2c3e50;
            margin: 0;
            font-size: 26px;
            font-weight: 700;
        }
        
        .step-description {
            color: #495057;
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-left: 4px solid #007bff;
            border-radius: 6px;
        }
        
        /* Billing Options Grid */
        .billing-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 768px) {
            .billing-options {
                grid-template-columns: 1fr;
            }
        }
        
        .billing-option {
            position: relative;
            cursor: pointer;
            display: block;
        }
        
        .billing-option input[type="radio"] {
            position: absolute;
            opacity: 0;
        }
        
        .option-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 30px 20px;
            background: white;
            border: 3px solid #dee2e6;
            border-radius: 12px;
            transition: all 0.3s ease;
            min-height: 140px;
        }
        
        .billing-option:hover .option-content {
            border-color: #007bff;
            box-shadow: 0 4px 12px rgba(0,123,255,0.15);
            transform: translateY(-2px);
        }
        
        .billing-option input[type="radio"]:checked + .option-content {
            background: linear-gradient(135deg, #e7f3ff 0%, #d0e8ff 100%);
            border-color: #007bff;
            border-width: 4px;
            box-shadow: 0 6px 16px rgba(0,123,255,0.25);
        }
        
        .day-number {
            font-size: 36px;
            font-weight: 700;
            color: #007bff;
            margin-bottom: 8px;
        }
        
        .billing-option input[type="radio"]:checked + .option-content .day-number {
            color: #0056b3;
        }
        
        .day-label {
            font-size: 15px;
            color: #6c757d;
            font-weight: 500;
        }
        
        /* Billing Preview */
        .billing-preview {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            border: 3px solid #4caf50;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }
        
        .preview-icon {
            font-size: 32px;
            flex-shrink: 0;
        }
        
        .preview-content {
            flex: 1;
        }
        
        .preview-content p {
            margin: 0;
            color: #2e7d32;
            font-size: 16px;
            line-height: 1.6;
            font-weight: 500;
        }
        
        .preview-content strong {
            color: #1b5e20;
        }
        
        /* Loading State */
        .loading-state {
            text-align: center;
            padding: 60px 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 3px solid #007bff;
            border-radius: 12px;
            margin-bottom: 40px;
        }
        
        .loading-spinner {
            width: 60px;
            height: 60px;
            border: 6px solid #e9ecef;
            border-top-color: #007bff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .loading-state p {
            color: #495057;
            font-size: 18px;
            font-weight: 600;
            margin: 0;
        }
        
        /* Billing Summary */
        .billing-summary {
            background: linear-gradient(135deg, #e7f3ff 0%, #d0e8ff 100%);
            border: 3px solid #007bff;
            border-radius: 12px;
            padding: 20px 30px;
            margin-bottom: 30px;
        }
        
        .summary-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }
        
        .summary-icon {
            font-size: 28px;
            flex-shrink: 0;
        }
        
        .summary-text {
            flex: 1;
            font-size: 16px;
            color: #2c3e50;
        }
        
        .summary-text strong {
            font-weight: 700;
            color: #0056b3;
        }
        
        .change-link {
            color: #007bff;
            text-decoration: none;
            font-weight: 600;
            padding: 8px 16px;
            border: 2px solid #007bff;
            border-radius: 6px;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }
        
        .change-link:hover {
            background: #007bff;
            color: white;
            text-decoration: none;
        }
        
        /* Step Actions */
        .step-actions {
            text-align: center;
        }
        
        /* ============================================================================
           LEGAL COMPLIANCE STYLING - EXISTING (Oct 9, 2025)
           ============================================================================ */
        
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

        .field-hint {
            display: block;
            font-size: 13px;
            color: #6c757d;
            margin-top: 8px;
            font-style: italic;
            line-height: 1.4;
        }

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

        /* Signature Section */
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

        /* Other Sections */
        .order-summary-section,
        .contract-terms-section {
            background: white;
            border: 2px solid #dee2e6;
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 30px;
        }

        .order-summary-section h2,
        .contract-terms-section h2 {
            color: #2c3e50;
            margin-bottom: 20px;
            font-size: 24px;
            padding-bottom: 10px;
            border-bottom: 2px solid #dee2e6;
        }

        /* Buttons */
        .button {
            display: inline-block;
            padding: 14px 28px;
            font-size: 16px;
            font-weight: 600;
            text-align: center;
            text-decoration: none;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .button.primary {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
        }

        .button.primary:hover {
            background: linear-gradient(135deg, #0056b3 0%, #004085 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,123,255,0.3);
        }

        .button.primary:disabled {
            background: #6c757d;
            cursor: not-allowed;
            opacity: 0.6;
            transform: none;
            box-shadow: none;
        }

        .button.secondary {
            background: white;
            color: #007bff;
            border: 2px solid #007bff;
        }

        .button.secondary:hover {
            background: #007bff;
            color: white;
        }

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
            
            .container {
                padding: 20px;
            }
            
            .legal-compliance-section,
            .signature-section {
                padding: 20px;
            }
        }
        </style>
        
        <div class="subzz-contract-page">
            <div class="container">
                <div class="contract-header">
                    <h1>📋 Subscription Agreement Review</h1>
                    <div class="progress-indicator">
                        ✅ Order Details → ⏳ Agreement → ⏸ Payment → ⏹ Complete
                    </div>
                </div>

                <div class="contract-content">
                    
                    <!-- ============================================================================
                         STEP 1: BILLING DATE SELECTION
                         JavaScript in: billing-date-handler.js
                         ============================================================================ -->
                    <div id="step-1-billing-date" class="contract-step active">
                        <div class="step-header">
                            <span class="step-number">1</span>
                            <h2>Select Your Billing Date</h2>
                        </div>
                        
                        <div class="billing-date-selection">
                            <p class="step-description">
                                Choose the day of the month when you'd like your subscription payment to be processed. 
                                Your first payment of <strong><?php echo esc_html($currency); ?> <?php echo esc_html(number_format((float)$monthly_amount, 2)); ?></strong> 
                                will be charged today.
                            </p>
                            
                            <div class="billing-options">
                                <label class="billing-option">
                                    <input type="radio" name="billing_day" value="1" id="billing-day-1">
                                    <span class="option-content">
                                        <span class="day-number">1st</span>
                                        <span class="day-label">of the month</span>
                                    </span>
                                </label>
                                
                                <label class="billing-option">
                                    <input type="radio" name="billing_day" value="8" id="billing-day-8">
                                    <span class="option-content">
                                        <span class="day-number">8th</span>
                                        <span class="day-label">of the month</span>
                                    </span>
                                </label>
                                
                                <label class="billing-option">
                                    <input type="radio" name="billing_day" value="15" id="billing-day-15">
                                    <span class="option-content">
                                        <span class="day-number">15th</span>
                                        <span class="day-label">of the month</span>
                                    </span>
                                </label>
                                
                                <label class="billing-option">
                                    <input type="radio" name="billing_day" value="22" id="billing-day-22">
                                    <span class="option-content">
                                        <span class="day-number">22nd</span>
                                        <span class="day-label">of the month</span>
                                    </span>
                                </label>
                            </div>
                            
                            <div id="billing-preview" class="billing-preview" style="display: none;">
                                <div class="preview-icon">ℹ️</div>
                                <div class="preview-content">
                                    <p id="preview-text"></p>
                                </div>
                            </div>
                            
                            <div class="step-actions">
                                <button id="btn-continue-step-1" class="button primary" disabled>
                                    Continue to Review Contract →
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Loading State -->
                    <div id="loading-contract" class="loading-state" style="display: none;">
                        <div class="loading-spinner"></div>
                        <p>Generating your contract with selected billing date...</p>
                    </div>

                    <!-- Billing Date Summary -->
                    <div id="billing-date-summary" class="billing-summary" style="display: none;">
                        <div class="summary-content">
                            <span class="summary-icon">✅</span>
                            <span class="summary-text">
                                <strong>Your Billing Date:</strong> <span id="selected-billing-day-display"></span>
                            </span>
                            <a href="#" id="change-billing-date" class="change-link">Change</a>
                        </div>
                    </div>

                    <!-- ============================================================================
                         STEPS 2 & 3: CONTRACT REVIEW AND SIGNATURE
                         JavaScript in: signature-handler.js
                         NOTE: contract-actions moved INSIDE this container to hide initially
                         ============================================================================ -->
                    <div id="step-2-3-container" class="contract-step" style="display: none;">
                        
                        <!-- Order Summary -->
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

                        <!-- Contract Terms (populated via AJAX) -->
                        <div class="contract-terms-section">
                            <h2>Subscription Agreement Terms</h2>
                            <div id="contract-text-container" class="contract-text">
                                <!-- Contract HTML will be inserted here via JavaScript -->
                            </div>
                        </div>

                        <!-- STEP 2: Legal Compliance Section -->
                        <div class="legal-compliance-section">
                            <h2>Step 2: Legal Verification & Consent</h2>
                            
                            <div class="compliance-step">
                                <h3>Step 2A: Type Your Legal Information</h3>
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
                            
                            <div class="compliance-step">
                                <h3>Step 2B: Legal Consent & Acknowledgment</h3>
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

                        <!-- STEP 3: Signature Section -->
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

                        <!-- FIXED: Action Buttons now INSIDE step-2-3-container -->
                        <div class="contract-actions">
                            <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="button secondary">
                                ← Back to Checkout
                            </a>
                            
                            <button id="sign-agreement" class="button primary" disabled>
                                Sign Agreement & Continue to Payment →
                            </button>
                        </div>

                    </div> <!-- End step-2-3-container -->

                </div>
            </div>
        </div>

        <script>
        // ============================================================================
        // GLOBAL VARIABLES ONLY - All JavaScript logic moved to separate files
        // ============================================================================
        
        // Pass data to JavaScript files
        window.subzzContractToken = '<?php echo esc_js($jwt_token); ?>';
        window.subzzReferenceId = '<?php echo esc_js($token_data['reference_id']); ?>';
        window.subzzCustomerEmail = '<?php echo esc_js($token_data['customer_email']); ?>';
        window.subzzAjaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
        window.subzzNonce = '<?php echo wp_create_nonce('subzz_signature'); ?>';
        window.subzzCurrency = '<?php echo esc_js($currency); ?>';
        window.subzzMonthlyAmount = <?php echo (float)$monthly_amount; ?>;
        
        <?php if ($variant_info): ?>
        window.subzzVariantInfo = <?php echo json_encode($variant_info); ?>;
        <?php endif; ?>
        
        console.log('SUBZZ PAGE JS: Global variables loaded for HYBRID architecture');
        console.log('SUBZZ PAGE JS: billing-date-handler.js will handle Step 1');
        console.log('SUBZZ PAGE JS: signature-handler.js will handle Steps 2 & 3');
        </script>

        <?php
        
        get_footer();
        
        error_log('SUBZZ PAGE RENDER: Contract signature page rendered successfully (HYBRID architecture)');
    }

    /**
     * Save signature via AJAX - HYBRID ARCHITECTURE
     */
    public function save_signature() {
        error_log('=== SUBZZ SIGNATURE SAVE: AJAX request received (HYBRID architecture) ===');
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'subzz_signature')) {
            error_log('SUBZZ SIGNATURE ERROR: Nonce verification failed');
            wp_die('Security check failed');
        }

        // Extract POST data
        $jwt_token = sanitize_text_field($_POST['token']);
        $reference_id = sanitize_text_field($_POST['reference_id']);
        $customer_email = sanitize_email($_POST['customer_email']);
        $signature_data = sanitize_textarea_field($_POST['signature_data']);
        
        // Extract legal compliance fields
        $typed_full_name = isset($_POST['typed_full_name']) ? sanitize_text_field($_POST['typed_full_name']) : '';
        $typed_initials = isset($_POST['typed_initials']) ? sanitize_text_field($_POST['typed_initials']) : '';
        $electronic_consent = isset($_POST['electronic_consent']) ? filter_var($_POST['electronic_consent'], FILTER_VALIDATE_BOOLEAN) : false;
        $terms_consent = isset($_POST['terms_consent']) ? filter_var($_POST['terms_consent'], FILTER_VALIDATE_BOOLEAN) : false;
        
        // Extract billing day (HYBRID ARCHITECTURE - from billing-date-handler.js)
        $billing_day_of_month = isset($_POST['billing_day_of_month']) ? intval($_POST['billing_day_of_month']) : null;
        
        // Extract variant info if provided
        $variant_info = isset($_POST['variant_info']) ? json_decode(stripslashes($_POST['variant_info']), true) : null;
        
        error_log('SUBZZ SIGNATURE DATA: Reference ID: ' . $reference_id);
        error_log('SUBZZ SIGNATURE DATA: Customer email: ' . $customer_email);
        error_log('SUBZZ SIGNATURE DATA: Billing day: ' . ($billing_day_of_month ?? 'not provided'));
        error_log('SUBZZ SIGNATURE LEGAL: Typed full name: ' . $typed_full_name);
        error_log('SUBZZ SIGNATURE LEGAL: Typed initials: ' . $typed_initials);
        
        // Validate billing day
        if ($billing_day_of_month && !in_array($billing_day_of_month, array(1, 8, 15, 22))) {
            error_log('SUBZZ SIGNATURE ERROR: Invalid billing day: ' . $billing_day_of_month);
            wp_send_json_error('Invalid billing day');
            return;
        }
        
        // Verify JWT token
        $token_data = $this->decode_jwt_token($jwt_token);
        
        if (!$token_data) {
            error_log('SUBZZ SIGNATURE ERROR: JWT token validation failed');
            wp_send_json_error('Invalid token');
            return;
        }
        
        if ($token_data['reference_id'] !== $reference_id) {
            error_log('SUBZZ SIGNATURE ERROR: Reference ID mismatch');
            wp_send_json_error('Token reference mismatch');
            return;
        }
        
        error_log('SUBZZ SIGNATURE VALIDATION: JWT token verified successfully');

        // Retrieve contract HTML from transient
        $transient_key = 'subzz_contract_html_' . $reference_id;
        $contract_html = get_transient($transient_key);
        
        if ($contract_html) {
            error_log('SUBZZ PDF: Contract HTML retrieved from transient: ' . strlen($contract_html) . ' chars');
            delete_transient($transient_key);
        } else {
            error_log('SUBZZ PDF WARNING: Contract HTML not found in transient');
        }

        // Prepare additional data for Azure
        $additional_data = array(
            'typed_full_name' => $typed_full_name,
            'typed_initials' => $typed_initials,
            'electronic_consent' => $electronic_consent,
            'terms_consent' => $terms_consent,
            'contract_html' => $contract_html,
            'signature_image_data' => $signature_data,
            'billing_day_of_month' => $billing_day_of_month
        );
        
        if ($variant_info) {
            $additional_data['variant_info'] = $variant_info;
        }

        error_log('SUBZZ SIGNATURE: Additional data includes billing_day_of_month: ' . ($billing_day_of_month ?? 'NULL'));

        // Store signature in Azure
        error_log('SUBZZ API CALL: Storing signature in Azure with billing date and legal compliance');
        
        $signature_stored = $this->azure_client->store_signature($customer_email, $signature_data, $reference_id, $additional_data);
        
        if (!$signature_stored) {
            error_log('SUBZZ API ERROR: Failed to store signature in Azure');
            wp_send_json_error('Failed to save signature. Please try again.');
            return;
        }
        
        error_log('SUBZZ API SUCCESS: Signature stored in Azure successfully');
        
        // Find and update WooCommerce order
        $orders = wc_get_orders(array(
            'meta_key' => '_subzz_reference_id',
            'meta_value' => $reference_id,
            'limit' => 1
        ));
        
        if (!empty($orders)) {
            $order = $orders[0];
            $order_id = $order->get_id();
            
            $order->add_meta_data('_subzz_signature_completed', 'yes');
            $order->add_meta_data('_subzz_signature_completed_at', time());
            $order->add_meta_data('_subzz_contract_signed', 'yes');
            
            if ($billing_day_of_month) {
                $order->add_meta_data('_subzz_billing_day', $billing_day_of_month);
                error_log("SUBZZ ORDER UPDATE: Stored billing day {$billing_day_of_month} in order meta");
            }
            
            if ($variant_info) {
                $order->add_meta_data('_subzz_subscription_months', $variant_info['subscription_duration_months'] ?? 12);
                $order->add_meta_data('_subzz_monthly_amount', $variant_info['monthly_amount'] ?? 0);
                $order->add_meta_data('_subzz_total_contract_value', $variant_info['total_contract_value'] ?? 0);
            }
            
            $order->add_meta_data('_subzz_typed_name', $typed_full_name);
            $order->add_meta_data('_subzz_typed_initials', $typed_initials);
            $order->add_meta_data('_subzz_electronic_consent', $electronic_consent ? 'yes' : 'no');
            $order->add_meta_data('_subzz_terms_consent', $terms_consent ? 'yes' : 'no');
            
            $order->update_meta_data('_subzz_redirect_required', 'no');
            $order->update_meta_data('_subzz_redirect_processed', 'yes');
            
            $order->save();
            
            error_log("SUBZZ ORDER UPDATE: Updated Order {$order_id} with signature completion and billing day");
        }
        
        // Update order status
        $status_updated = $this->azure_client->update_order_status($reference_id, 'signature_completed');

        if (!$status_updated) {
            error_log('SUBZZ API WARNING: Failed to update order status in Azure');
        } else {
            error_log('SUBZZ API SUCCESS: Order status updated to signature_completed');
        }
        
        // Generate return URL to payment page
        $return_url = add_query_arg(array(
            'reference_id' => $reference_id,
            'signature_confirmed' => 'yes',
            'billing_day' => $billing_day_of_month,
            'subscription_months' => $variant_info['subscription_duration_months'] ?? 12
        ), home_url('/subscription-payment/'));

        error_log('SUBZZ SIGNATURE SUCCESS: Complete workflow finished (HYBRID architecture)');
        error_log('SUBZZ SIGNATURE SUCCESS: Redirecting to payment page: ' . $return_url);

        wp_send_json_success(array(
            'message' => 'Contract signed successfully! Redirecting to payment...',
            'redirect_url' => $return_url,
            'reference_id' => $reference_id,
            'billing_day' => $billing_day_of_month,
            'variant_info' => $variant_info
        ));
    }
}
?>