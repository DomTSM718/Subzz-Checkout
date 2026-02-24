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

        // AJAX handler for order cancellation
        add_action('wp_ajax_subzz_cancel_order', array($this, 'handle_order_cancellation'));
        add_action('wp_ajax_nopriv_subzz_cancel_order', array($this, 'handle_order_cancellation'));
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
        $expires_at = $token_data['exp'];
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
        
        // Enqueue order cancellation script
        wp_enqueue_script(
        'subzz-order-cancellation',
        plugins_url('assets/order-cancellation.js', dirname(__FILE__)),
        array('jquery'),
        '1.0.0',
        true
        );    

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
     * Decode and verify HMAC-SHA256 signed JWT token (CHK-001 fix).
     * Rejects forged, tampered, or expired tokens.
     * Returns associative array of payload fields on success, false on failure.
     */
    private function decode_jwt_token($token) {
        error_log('SUBZZ JWT DECODE: Starting signed token verification for length: ' . strlen($token));

        // Load vendored JWT library
        require_once dirname(__FILE__) . '/vendor/firebase/php-jwt/src/JWT.php';
        require_once dirname(__FILE__) . '/vendor/firebase/php-jwt/src/Key.php';
        require_once dirname(__FILE__) . '/vendor/firebase/php-jwt/src/ExpiredException.php';

        $secret_key = defined('SUBZZ_CHECKOUT_JWT_SECRET') ? SUBZZ_CHECKOUT_JWT_SECRET : wp_salt('auth');

        try {
            $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($secret_key, 'HS256'));
            $token_data = (array) $decoded;

            // Validate required fields
            if (!isset($token_data['reference_id']) || !isset($token_data['customer_email'])) {
                error_log('SUBZZ JWT ERROR: Missing required fields in signed token');
                return false;
            }

            error_log('SUBZZ JWT SUCCESS: Signed token verified successfully');
            return $token_data;

        } catch (\Firebase\JWT\ExpiredException $e) {
            error_log('SUBZZ JWT ERROR: Token expired - ' . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            error_log('SUBZZ JWT ERROR: Token verification failed - ' . $e->getMessage());
            return false;
        }
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
                    <h1>Contract Error</h1>
                </div>

                <div class="contract-content">
                    <div class="error-message-section">
                        <h2><?php echo esc_html($title); ?></h2>
                        <p class="error-description"><?php echo esc_html($message); ?></p>
                        
                        <div class="error-actions">
                            <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="btn-primary">
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
        
        // Extract variant details from new checkout flow fields (stored by ajax_store_checkout_order)
        $standard_monthly = isset($order_data['standard_monthly_amount']) ? floatval($order_data['standard_monthly_amount']) : 0;
        $reduced_monthly = isset($order_data['reduced_monthly_amount']) ? floatval($order_data['reduced_monthly_amount']) : 0;
        $initial_payment = isset($order_data['initial_payment_amount']) ? floatval($order_data['initial_payment_amount']) : 0;
        $subscription_months = isset($order_data['selected_term_months']) ? intval($order_data['selected_term_months']) : 12;
        $currency = $order_data['order_totals']['currency'];

        // Monthly amount: use reduced if initial payment exists, otherwise standard
        $monthly_amount = ($initial_payment > 0 && $reduced_monthly > 0) ? $reduced_monthly : $standard_monthly;
        // Fallback to order_totals if new fields not present (backward compat)
        if ($monthly_amount <= 0) {
            $monthly_amount = floatval($order_data['order_totals']['total']);
        }
        $total_contract_value = $monthly_amount * $subscription_months;

        if ($variant_info) {
            $subscription_months = $variant_info['subscription_duration_months'] ?? $subscription_months;
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
           CONTRACT PAGE STYLES — Aligned with checkout-plans.css design language
           Primary: #3498db | Text: #2c3e50 | Muted: #6c757d | Radius: 12px
           ============================================================================ */

        .subzz-contract-page {
            max-width: 900px;
            margin: 30px auto;
            padding: 0 20px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            color: #333;
        }

        .container {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            padding: 40px;
        }

        /* ── Header & Progress ────────────────────────────────────────────── */
        .contract-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .contract-header h1 {
            color: #2c3e50;
            margin: 0 0 20px;
            font-size: 22px;
            font-weight: 700;
        }

        .checkout-progress {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0;
            margin-bottom: 0;
        }

        .progress-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
        }

        .step-dot {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #dee2e6;
            color: #fff;
            font-weight: 700;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s;
        }

        .progress-step.active .step-dot,
        .progress-step.done .step-dot {
            background: #3498db;
        }

        .progress-step.done .step-dot {
            background: #27ae60;
        }

        .step-label {
            font-size: 12px;
            color: #6c757d;
            font-weight: 500;
        }

        .progress-step.active .step-label {
            color: #3498db;
            font-weight: 600;
        }

        .progress-line {
            width: 48px;
            height: 3px;
            background: #dee2e6;
            margin: 0 4px;
            margin-bottom: 20px;
        }

        /* Legacy progress indicator (hidden when new progress exists) */
        .progress-indicator {
            display: none;
        }

        /* ── Steps ────────────────────────────────────────────────────────── */
        .contract-step {
            margin-bottom: 28px;
            transition: opacity 0.3s ease;
        }

        .contract-step.hidden { display: none; }

        .step-header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 0;
            border-bottom: none;
        }

        .step-number {
            width: 32px;
            height: 32px;
            background: #3498db;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 700;
            margin-right: 12px;
            flex-shrink: 0;
        }

        .step-header h2 {
            color: #2c3e50;
            margin: 0;
            font-size: 20px;
            font-weight: 700;
        }

        .step-description {
            color: #6c757d;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 20px;
            padding: 14px 16px;
            background: #f8f9fa;
            border-left: 3px solid #3498db;
            border-radius: 8px;
        }

        /* ── Billing Options Grid ─────────────────────────────────────────── */
        .billing-options {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
        }

        @media (max-width: 600px) {
            .billing-options { flex-direction: column; }
        }

        .billing-option {
            flex: 1;
            position: relative;
            cursor: pointer;
            display: block;
        }

        .billing-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .option-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 14px 8px;
            background: white;
            border: 2px solid #dee2e6;
            border-radius: 10px;
            transition: all 0.2s;
            min-height: auto;
        }

        .billing-option:hover .option-content {
            border-color: #3498db;
            color: #3498db;
        }

        .billing-option input[type="radio"]:checked + .option-content {
            background: #eaf4fd;
            border-color: #3498db;
        }

        .day-number {
            font-size: 18px;
            font-weight: 700;
            color: #6c757d;
            margin-bottom: 0;
        }

        .billing-option:hover .day-number,
        .billing-option input[type="radio"]:checked + .option-content .day-number {
            color: #3498db;
        }

        .day-label {
            font-size: 12px;
            color: #6c757d;
            font-weight: 500;
        }

        /* ── Billing Preview ──────────────────────────────────────────────── */
        .billing-preview {
            background: #eafaf1;
            border: 1px solid #27ae60;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .preview-icon { font-size: 20px; flex-shrink: 0; }
        .preview-content { flex: 1; }
        .preview-content p { margin: 0; color: #2c3e50; font-size: 14px; line-height: 1.6; }
        .preview-content strong { color: #27ae60; }

        /* ── Loading State ────────────────────────────────────────────────── */
        .loading-state {
            text-align: center;
            padding: 40px 20px;
            background: #f8f9fa;
            border: 2px solid #3498db;
            border-radius: 12px;
            margin-bottom: 28px;
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #e9ecef;
            border-top-color: #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 16px;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        .loading-state p {
            color: #6c757d;
            font-size: 15px;
            font-weight: 600;
            margin: 0;
        }

        /* ── Billing Summary ──────────────────────────────────────────────── */
        .billing-summary {
            background: #eaf4fd;
            border: 2px solid #3498db;
            border-radius: 12px;
            padding: 14px 20px;
            margin-bottom: 28px;
        }

        .summary-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .summary-icon { font-size: 20px; flex-shrink: 0; }

        .summary-text {
            flex: 1;
            font-size: 14px;
            color: #2c3e50;
        }

        .summary-text strong {
            font-weight: 700;
            color: #3498db;
        }

        .change-link {
            color: #3498db;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            padding: 6px 14px;
            border: 2px solid #3498db;
            border-radius: 8px;
            transition: all 0.2s;
            flex-shrink: 0;
        }

        .change-link:hover {
            background: #3498db;
            color: white;
            text-decoration: none;
        }

        .step-actions { text-align: center; }

        /* ── Legacy compliance (kept for JS compatibility) ───────────────── */

        .typed-name-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        @media (max-width: 600px) {
            .typed-name-section { grid-template-columns: 1fr; }
        }

        .form-field { margin-bottom: 0; }

        .form-field label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            color: #2c3e50;
            font-size: 14px;
        }

        .form-field input[type="text"] {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            font-size: 15px;
            transition: border-color 0.2s;
            box-sizing: border-box;
            font-family: inherit;
        }

        .form-field input[type="text"]:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .field-hint {
            display: block;
            font-size: 12px;
            color: #6c757d;
            margin-top: 4px;
            font-style: normal;
            line-height: 1.4;
        }

        .consent-checkboxes {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .consent-checkbox {
            background: white;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 16px;
            transition: all 0.2s;
            cursor: pointer;
        }

        .consent-checkbox:hover {
            border-color: #3498db;
        }

        .consent-checkbox label {
            display: flex;
            align-items: flex-start;
            cursor: pointer;
            margin: 0;
            line-height: 1.5;
            font-size: 14px;
        }

        .consent-checkbox input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-right: 12px;
            margin-top: 2px;
            cursor: pointer;
            flex-shrink: 0;
            accent-color: #3498db;
        }

        .consent-checkbox strong {
            color: #2c3e50;
            display: block;
            margin-bottom: 4px;
        }

        /* ── Signature pad (within sign-section) ──────────────────────────── */

        .signature-pad-container {
            border: 2px solid #dee2e6;
            border-radius: 6px;
            overflow: hidden;
        }

        #signature-pad {
            display: block;
            width: 100% !important;
            cursor: crosshair;
            background: white;
        }

        .signature-controls {
            padding: 8px 12px;
            text-align: right;
            background: #f8f9fa;
            border-top: 1px solid #eee;
        }

        /* ── Contract Terms (scrollable) ──────────────────────────────────── */
        .contract-terms-section {
            background: white;
            border: 2px solid #dee2e6;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
        }

        .contract-terms-section h2 {
            color: #2c3e50;
            margin: 0 0 8px;
            font-size: 20px;
            font-weight: 700;
        }

        .contract-scroll-hint {
            color: #6c757d;
            font-size: 13px;
            margin: 0 0 12px;
        }

        .contract-text-scroll {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #eee;
            border-radius: 8px;
            padding: 20px;
            background: #fafbfc;
            font-size: 14px;
            line-height: 1.6;
        }

        .contract-text-scroll::-webkit-scrollbar {
            width: 6px;
        }

        .contract-text-scroll::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }

        .contract-text-scroll::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 3px;
        }

        .contract-text-scroll::-webkit-scrollbar-thumb:hover {
            background: #aaa;
        }

        /* ── Combined Sign Section ────────────────────────────────────────── */
        .sign-section {
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
        }

        .sign-section h2 {
            color: #2c3e50;
            margin: 0 0 16px;
            font-size: 20px;
            font-weight: 700;
        }

        .sign-fields {
            margin-top: 12px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .sign-card {
            background: white;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 16px;
        }

        .sign-card-label {
            display: block;
            font-weight: 600;
            color: #2c3e50;
            font-size: 14px;
            margin-bottom: 12px;
        }

        /* ── Buttons ──────────────────────────────────────────────────────── */
        /* Uses .btn-primary/.btn-secondary (custom classes) instead of .button
           to avoid CSS conflicts with ANY WordPress theme (Astra, Divi, etc.) */
        .btn-primary {
            display: inline-block;
            padding: 14px 28px;
            font-size: 16px;
            font-weight: 700;
            text-align: center;
            text-decoration: none;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: #fff;
        }

        .btn-primary:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3);
        }

        .btn-primary:disabled {
            background: #adb5bd;
            color: #fff;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-secondary {
            display: inline-block;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 600;
            text-align: center;
            text-decoration: none;
            border: 2px solid #3498db;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            background: #fff;
            color: #3498db;
        }

        .btn-secondary:hover {
            background: #3498db;
            color: #fff;
        }

        .contract-actions {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            margin-top: 24px;
        }

        @media (max-width: 600px) {
            .container { padding: 20px 16px; }
            .contract-actions { flex-direction: column; }
            .sign-section { padding: 20px 16px; }
            .contract-text-scroll { max-height: 300px; }
        }

        /* ── Error page ───────────────────────────────────────────────────── */
        .error-message-section {
            text-align: center;
            padding: 30px 20px;
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 12px;
        }

        .error-actions { margin-top: 20px; }
        </style>
        
        <div class="subzz-contract-page">
            <div class="container">
                <div class="contract-header">
                    <h1>Subscription Agreement</h1>
                    <div class="checkout-progress">
                        <div class="progress-step done">
                            <span class="step-dot">1</span>
                            <span class="step-label">Choose Plan</span>
                        </div>
                        <div class="progress-line"></div>
                        <div class="progress-step active">
                            <span class="step-dot">2</span>
                            <span class="step-label">Contract</span>
                        </div>
                        <div class="progress-line"></div>
                        <div class="progress-step">
                            <span class="step-dot">3</span>
                            <span class="step-label">Payment</span>
                        </div>
                        <div class="progress-line"></div>
                        <div class="progress-step">
                            <span class="step-dot">4</span>
                            <span class="step-label">Complete</span>
                        </div>
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
                                <button id="btn-continue-step-1" class="btn-primary" disabled>
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

                        <!-- Contract Terms (populated via AJAX) — scrollable -->
                        <div class="contract-terms-section">
                            <h2>Subscription Agreement</h2>
                            <p class="contract-scroll-hint">Please review the full agreement below before signing.</p>
                            <div id="contract-text-container" class="contract-text-scroll">
                                <!-- Contract HTML will be inserted here via JavaScript -->
                            </div>
                        </div>

                        <!-- Review & Sign — combined section -->
                        <div class="sign-section">
                            <h2>Review & Sign</h2>

                            <div class="consent-checkboxes">
                                <div class="consent-checkbox">
                                    <label>
                                        <input type="checkbox" id="electronic-consent" required>
                                        <span>
                                            I consent to signing this agreement electronically in accordance
                                            with the Electronic Communications and Transactions Act (ECTA).
                                        </span>
                                    </label>
                                </div>

                                <div class="consent-checkbox">
                                    <label>
                                        <input type="checkbox" id="terms-consent" required>
                                        <span>
                                            I confirm that I have read and agree to all terms and conditions
                                            of this subscription agreement.
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <div class="sign-fields">
                                <div class="sign-card">
                                    <label class="sign-card-label">Your Details</label>
                                    <div class="typed-name-section">
                                        <div class="form-field">
                                            <label for="typed-full-name">Full Name (as on your ID) *</label>
                                            <input type="text" id="typed-full-name" name="typed_full_name"
                                                   placeholder="Enter your full name" required
                                                   value="<?php echo esc_attr($customer_name); ?>">
                                        </div>

                                        <div class="form-field">
                                            <label for="typed-initials">Initials *</label>
                                            <input type="text" id="typed-initials" name="typed_initials"
                                                   placeholder="e.g., JDS" maxlength="10" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="sign-card">
                                    <label class="sign-card-label">Your Signature</label>
                                    <div class="signature-pad-container">
                                        <canvas id="signature-pad" height="180"></canvas>
                                        <div class="signature-controls">
                                            <button id="clear-signature" type="button" class="btn-secondary">Clear</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="contract-actions">
                            <button type="button" id="cancel-order-button" class="btn-secondary"
                                    data-reference-id="<?php echo esc_attr($token_data['reference_id']); ?>">
                                Cancel Order
                            </button>

                            <button id="sign-agreement" class="btn-primary" disabled>
                                Sign & Continue to Payment
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
        window.subzzInitialPayment = <?php echo (float)$initial_payment; ?>;
        window.subzzSubscriptionMonths = <?php echo (int)$subscription_months; ?>;

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
                $order->update_meta_data('_subzz_billing_day', $billing_day_of_month);
                error_log("SUBZZ ORDER UPDATE: Updated billing day {$billing_day_of_month} in order meta");
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
        
        // Get initial payment and term info from order meta (reliable source, set during checkout)
        $initial_payment_amount = 0;
        $subscription_months = 12;
        if (!empty($orders)) {
            $ip = $orders[0]->get_meta('_subzz_initial_payment_amount');
            if ($ip) $initial_payment_amount = floatval($ip);
            $sm = $orders[0]->get_meta('_subzz_selected_term_months');
            if ($sm) $subscription_months = intval($sm);
        }

        // Generate return URL to payment page
        $return_url = add_query_arg(array(
            'reference_id' => $reference_id,
            'signature_confirmed' => 'yes',
            'billing_day' => $billing_day_of_month,
            'subscription_months' => $subscription_months,
            'initial_payment' => $initial_payment_amount
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
    /**
     * Handle order cancellation and cart restoration
     */
    public function handle_order_cancellation() {
        error_log('=== SUBZZ ORDER CANCELLATION: Request received ===');
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'subzz_signature')) {
            error_log('SUBZZ CANCELLATION ERROR: Nonce verification failed');
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        $reference_id = isset($_POST['reference_id']) ? sanitize_text_field($_POST['reference_id']) : '';
        
        if (empty($reference_id)) {
            error_log('SUBZZ CANCELLATION ERROR: No reference ID provided');
            wp_send_json_error(array('message' => 'Invalid request'));
            return;
        }
        
        error_log('SUBZZ CANCELLATION: Processing for Reference ID: ' . $reference_id);
        
        // Find WooCommerce order by reference ID
        $orders = wc_get_orders(array(
            'meta_key' => '_subzz_reference_id',
            'meta_value' => $reference_id,
            'limit' => 1
        ));
        
        if (empty($orders)) {
            error_log('SUBZZ CANCELLATION ERROR: Order not found for reference: ' . $reference_id);
            wp_send_json_error(array('message' => 'Order not found'));
            return;
        }
        
        $order = $orders[0];
        $order_id = $order->get_id();
        
        error_log('SUBZZ CANCELLATION: Found WooCommerce Order ID: ' . $order_id);
        
        // Store order items before cancellation
        $order_items = array();
        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $quantity = $item->get_quantity();
            
            $order_items[] = array(
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'quantity' => $quantity
            );
            
            error_log("SUBZZ CANCELLATION: Stored item - Product: {$product_id}, Qty: {$quantity}");
        }
        
        // Cancel the order
        $order->update_status('cancelled', 'Order cancelled by customer during signature process');
        error_log('SUBZZ CANCELLATION: Order status updated to cancelled');
        
        // Update order metadata
        $order->update_meta_data('_subzz_cancellation_reason', 'customer_cancelled_at_signature');
        $order->update_meta_data('_subzz_cancelled_at', current_time('mysql'));
        $order->save();
        
        // Notify Azure backend about cancellation
        $this->azure_client->update_order_status($reference_id, 'cancelled');
        error_log('SUBZZ CANCELLATION: Azure backend notified');
        
        // Clear any existing cart
        WC()->cart->empty_cart();
        error_log('SUBZZ CANCELLATION: Cart cleared');
        
        // Restore products to cart
        foreach ($order_items as $item) {
            if ($item['variation_id']) {
                WC()->cart->add_to_cart($item['product_id'], $item['quantity'], $item['variation_id']);
            } else {
                WC()->cart->add_to_cart($item['product_id'], $item['quantity']);
            }
        }
        
        // Clear session data
        WC()->session->set('subzz_reference_id', null);
        WC()->session->set('subzz_jwt_token', null);
        error_log('SUBZZ CANCELLATION: Session data cleared');
        
        $cart_count = WC()->cart->get_cart_contents_count();
        error_log("SUBZZ CANCELLATION SUCCESS: Cart restored with {$cart_count} items");
        
        wp_send_json_success(array(
            'message' => 'Order cancelled successfully',
            'cart_url' => wc_get_cart_url(),
            'cart_items_restored' => count($order_items)
        ));
    }
}
?>