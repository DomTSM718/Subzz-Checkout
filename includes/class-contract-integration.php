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
        
        subzz_log('=== SUBZZ CONTRACT PAGE: HYBRID Architecture request received ===');
        subzz_log('SUBZZ CONTRACT PAGE: URL path: ' . $wp->request);
        
        // Get and validate JWT token from URL
        if (!isset($_GET['token'])) {
            subzz_log('SUBZZ CONTRACT ERROR: No token provided in URL');
            $this->display_styled_error_page('Missing Contract Token', 'Missing contract token. Please return to checkout and try again.');
            exit;
        }

        $jwt_token = sanitize_text_field($_GET['token']);
        subzz_log('SUBZZ TOKEN RECEIVED: Length: ' . strlen($jwt_token) . ' characters');
        
        // Decode JWT token
        subzz_log('SUBZZ TOKEN DECODE: Starting JWT token decode process');
        $token_data = $this->decode_jwt_token($jwt_token);
        
        if (!$token_data) {
            subzz_log('SUBZZ TOKEN ERROR: JWT decode failed - token invalid or malformed');
            $this->display_styled_error_page('Invalid Token', 'Invalid or expired contract token. Please return to checkout and try again.');
            exit;
        }
        
        // Check token expiry
        $current_time = time();
        $expires_at = $token_data['exp'];
        $time_until_expiry = $expires_at - $current_time;
        
        subzz_log("SUBZZ TOKEN VALIDATION: Current time: {$current_time}");
        subzz_log("SUBZZ TOKEN VALIDATION: Token expires: {$expires_at}");
        subzz_log("SUBZZ TOKEN VALIDATION: Time until expiry: {$time_until_expiry} seconds");
        
        if ($time_until_expiry <= 0) {
            subzz_log('SUBZZ TOKEN ERROR: JWT token has expired');
            $this->display_styled_error_page('Token Expired', 'Contract token has expired. Please return to checkout and try again.');
            exit;
        }
        
        subzz_log('SUBZZ TOKEN SUCCESS: JWT token is valid and not expired');
        subzz_log('SUBZZ TOKEN DATA: Reference ID: ' . $token_data['reference_id']);
        subzz_log('SUBZZ TOKEN DATA: Customer email: ' . $token_data['customer_email']);
        
        // Retrieve order data from Azure
        subzz_log('SUBZZ API CALL: Retrieving order data from Azure for Reference ID: ' . $token_data['reference_id']);
        $order_data = $this->azure_client->retrieve_order_data($token_data['reference_id']);
        
        if (!$order_data) {
            subzz_log('SUBZZ API ERROR: Failed to retrieve order data from Azure');
            $this->display_styled_error_page('Unable to Load Order', 'Unable to load order information. Please return to checkout and try again.');
            exit;
        }
        
        subzz_log('SUBZZ API SUCCESS: Order data retrieved from Azure');
        subzz_log('SUBZZ HYBRID ARCHITECTURE: Contract will be generated after billing date selection');
        
        // Enqueue order cancellation script
        wp_enqueue_script(
        'subzz-order-cancellation',
        plugins_url('assets/order-cancellation.js', dirname(__FILE__)),
        array('jquery'),
        '1.0.0',
        true
        );    

        // Add body class to enable CSS hiding of theme product elements (before get_header)
        add_filter('body_class', function($classes) { $classes[] = 'subzz-contract-active'; return $classes; });

        // Display contract signature page - contract generation deferred to JavaScript
        $this->display_contract_signature_page($jwt_token, $token_data, $order_data, null, null);
        exit;
    }

    /**
     * AJAX handler to regenerate contract with billing date
     */
    public function regenerate_contract_with_billing_date() {
        subzz_log('=== SUBZZ BILLING DATE: Contract regeneration requested ===');
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'subzz_signature')) {
            subzz_log('SUBZZ BILLING DATE ERROR: Nonce verification failed');
            wp_send_json_error('Security check failed');
            return;
        }

        // Extract POST data
        $jwt_token = sanitize_text_field($_POST['token']);
        $reference_id = sanitize_text_field($_POST['reference_id']);
        $customer_email = sanitize_email($_POST['customer_email']);
        $billing_day = intval($_POST['billing_day']);
        
        subzz_log('SUBZZ BILLING DATE: Reference ID: ' . $reference_id);
        subzz_log('SUBZZ BILLING DATE: Customer email: ' . $customer_email);
        subzz_log('SUBZZ BILLING DATE: Selected billing day: ' . $billing_day);
        
        // Validate billing day
        if (!in_array($billing_day, array(1, 8, 15, 22))) {
            subzz_log('SUBZZ BILLING DATE ERROR: Invalid billing day selected: ' . $billing_day);
            wp_send_json_error('Invalid billing day selected');
            return;
        }
        
        // Verify JWT token
        $token_data = $this->decode_jwt_token($jwt_token);
        if (!$token_data || $token_data['reference_id'] !== $reference_id) {
            subzz_log('SUBZZ BILLING DATE ERROR: JWT token validation failed');
            wp_send_json_error('Invalid token');
            return;
        }
        
        // Retrieve order data
        subzz_log('SUBZZ BILLING DATE: Retrieving order data from Azure');
        $order_data = $this->azure_client->retrieve_order_data($reference_id);
        
        if (!$order_data) {
            subzz_log('SUBZZ BILLING DATE ERROR: Failed to retrieve order data');
            wp_send_json_error('Unable to load order information');
            return;
        }
        
        // Generate contract WITH billing date
        subzz_log('SUBZZ BILLING DATE: Generating contract with billing day: ' . $billing_day);
        $contract_response = $this->azure_client->generate_contract(
            $customer_email,
            $order_data,
            $reference_id,
            $billing_day
        );
        
        if (!$contract_response || !isset($contract_response['contractHtml'])) {
            subzz_log('SUBZZ BILLING DATE ERROR: Failed to generate contract from Azure');
            wp_send_json_error('Unable to generate contract');
            return;
        }
        
        // Extract contract HTML and billing info
        $contract_html = $contract_response['contractHtml'];
        $variant_info = isset($contract_response['variant_info']) ? $contract_response['variant_info'] : null;
        
        // Store contract HTML in transient for later use during signature
        $transient_key = 'subzz_contract_html_' . $reference_id;
        set_transient($transient_key, $contract_html, HOUR_IN_SECONDS);
        subzz_log('SUBZZ BILLING DATE: Contract HTML stored in transient: ' . $transient_key);
        
        // Extract billing date information from response
        $billing_info = array(
            'billing_day_of_month' => isset($contract_response['billing_day_of_month']) ? $contract_response['billing_day_of_month'] : $billing_day,
            'billing_day_formatted' => isset($contract_response['billing_day_formatted']) ? $contract_response['billing_day_formatted'] : $this->format_billing_day($billing_day),
            'first_billing_date' => isset($contract_response['first_billing_date']) ? $contract_response['first_billing_date'] : null,
            'next_billing_date' => isset($contract_response['next_billing_date']) ? $contract_response['next_billing_date'] : null,
            'days_of_coverage' => isset($contract_response['days_of_coverage']) ? $contract_response['days_of_coverage'] : null
        );
        
        subzz_log('SUBZZ BILLING DATE SUCCESS: Contract generated with billing date');
        subzz_log('SUBZZ BILLING DATE: Next billing date: ' . ($billing_info['next_billing_date'] ?? 'not set'));
        subzz_log('SUBZZ BILLING DATE: Days of coverage: ' . ($billing_info['days_of_coverage'] ?? 'not set'));
        
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
        subzz_log('SUBZZ JWT DECODE: Starting signed token verification for length: ' . strlen($token));

        // Load vendored JWT library
        require_once dirname(__FILE__) . '/jwt/JWT.php';
        require_once dirname(__FILE__) . '/jwt/Key.php';
        require_once dirname(__FILE__) . '/jwt/ExpiredException.php';

        $secret_key = defined('SUBZZ_CHECKOUT_JWT_SECRET') ? SUBZZ_CHECKOUT_JWT_SECRET : wp_salt('auth');

        try {
            $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($secret_key, 'HS256'));
            $token_data = (array) $decoded;

            // Validate required fields
            if (!isset($token_data['reference_id']) || !isset($token_data['customer_email'])) {
                subzz_log('SUBZZ JWT ERROR: Missing required fields in signed token');
                return false;
            }

            subzz_log('SUBZZ JWT SUCCESS: Signed token verified successfully');
            return $token_data;

        } catch (\Firebase\JWT\ExpiredException $e) {
            subzz_log('SUBZZ JWT ERROR: Token expired - ' . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            subzz_log('SUBZZ JWT ERROR: Token verification failed - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Display styled error page
     */
    private function display_styled_error_page($title, $message) {
        get_header();

        subzz_log('SUBZZ ERROR PAGE: Displaying error - ' . $title);
        ?>
        <div class="subzz-checkout-header">
            <a href="<?php echo esc_url(home_url('/')); ?>">
                <img src="<?php echo esc_url(plugin_dir_url(dirname(__FILE__)) . 'assets/img/logo-white.png'); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>">
            </a>
        </div>
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
        
        subzz_log('SUBZZ PAGE RENDER: Starting contract signature page render (HYBRID architecture)');
        
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

        <div class="subzz-checkout-header">
            <a href="<?php echo esc_url(home_url('/')); ?>">
                <img src="<?php echo esc_url(plugin_dir_url(dirname(__FILE__)) . 'assets/img/logo-white.png'); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>">
            </a>
        </div>

        <style>
        /* ============================================================================
           CONTRACT PAGE STYLES — Figma Redesign (card-based layout)
           Uses Subzz Design System tokens where possible.
           ============================================================================ */

        /* Hide WooCommerce/Astra theme product elements injected via get_header() */
        .subzz-contract-page ~ .woocommerce-products-header,
        .subzz-contract-page ~ .wc-block-grid,
        body.subzz-contract-active .woocommerce-products-header,
        body.subzz-contract-active .wc-block-grid,
        body.subzz-contract-active .related.products,
        body.subzz-contract-active .up-sells,
        body.subzz-contract-active .cross-sells,
        body.subzz-contract-active .woocommerce-breadcrumb,
        body.subzz-contract-active .woocommerce-result-count,
        body.subzz-contract-active .woocommerce-ordering,
        body.subzz-contract-active .ast-woocommerce-container .product,
        body.subzz-contract-active .entry-content > .woocommerce:not(.subzz-contract-page),
        body.subzz-contract-active .ast-single-post .entry-header,
        body.subzz-contract-active .hentry .entry-header {
            display: none !important;
        }

        .subzz-contract-page {
            max-width: 900px;
            margin: 30px auto;
            padding: 0 20px;
            font-family: var(--subzz-font-family);
            color: var(--subzz-gray);
            background: var(--subzz-page-bg);
        }

        .container {
            background: transparent;
            border-radius: 0;
            box-shadow: none;
            padding: 0;
        }

        /* ── Progress card ────────────────────────────────────────────────── */
        .contract-header {
            background: var(--subzz-white);
            border-radius: 12px;
            box-shadow: var(--subzz-shadow-sm);
            padding: 24px;
            margin-bottom: 24px;
            text-align: center;
        }

        .contract-header h1 { display: none; }

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
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--subzz-border);
            color: #fff;
            font-weight: 700;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s;
        }

        .progress-step.active .step-dot {
            background: var(--subzz-red);
            box-shadow: var(--subzz-shadow-lg);
        }

        .progress-step.done .step-dot {
            background: var(--subzz-blue);
        }

        .step-label {
            font-size: 12px;
            color: rgba(84, 84, 84, 0.5);
            font-weight: 500;
        }

        .progress-step.active .step-label {
            color: var(--subzz-red);
            font-weight: 600;
        }

        .progress-step.done .step-label {
            color: var(--subzz-blue);
            font-weight: 600;
        }

        .progress-line {
            flex: 1;
            height: 4px;
            background: var(--subzz-border);
            margin: 0 4px;
            margin-bottom: 20px;
            transition: background 0.3s;
        }

        .progress-line.active {
            background: var(--subzz-red);
        }

        .progress-line.done {
            background: var(--subzz-blue);
        }

        /* Legacy elements hidden */
        .subzz-dash-divider { display: none; }
        .progress-indicator { display: none; }

        /* ── Contract card (shared) ───────────────────────────────────────── */
        .contract-card {
            background: var(--subzz-white);
            border-radius: 12px;
            box-shadow: var(--subzz-shadow-sm);
            padding: 24px;
            margin-bottom: 24px;
        }

        /* ── Steps ────────────────────────────────────────────────────────── */
        .contract-step {
            margin-bottom: 0;
            transition: opacity 0.3s ease;
        }

        .contract-step.hidden { display: none; }

        .step-header { display: none; }

        .step-description { display: none; }

        /* ── Billing Summary (cyan border per design) ─────────────────────── */
        .billing-summary {
            background: var(--subzz-white);
            border: 2px solid var(--subzz-cyan);
            border-radius: 12px;
            padding: 14px 20px;
            margin-bottom: 24px;
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
            color: var(--subzz-gray);
        }

        .summary-text strong {
            font-weight: 700;
            color: var(--subzz-gray);
        }

        .change-link {
            color: var(--subzz-blue);
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            padding: 6px 14px;
            border: 2px solid var(--subzz-blue);
            border-radius: 8px;
            transition: all 0.2s;
            flex-shrink: 0;
        }

        .change-link:hover {
            background: var(--subzz-blue);
            color: white;
            text-decoration: none;
        }

        /* ── Contract Terms (scrollable) ──────────────────────────────────── */
        .contract-terms-section {
            background: var(--subzz-white);
            border-radius: 12px;
            box-shadow: var(--subzz-shadow-sm);
            padding: 24px;
            margin-bottom: 24px;
        }

        .contract-terms-section h2 {
            color: var(--subzz-gray);
            margin: 0 0 8px;
            font-size: 20px;
            font-weight: 700;
        }

        .contract-scroll-hint {
            color: rgba(84, 84, 84, 0.7);
            font-size: 13px;
            margin: 0 0 12px;
        }

        .contract-text-wrapper {
            position: relative;
        }

        .contract-text-scroll {
            max-height: 400px;
            overflow-y: auto;
            border: 2px solid var(--subzz-border);
            border-radius: 8px;
            padding: 20px;
            background: #FAFAFA;
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

        /* Scroll indicator overlay */
        .scroll-indicator {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 64px;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            padding-bottom: 12px;
            pointer-events: none;
            border-radius: 0 0 8px 8px;
            background: linear-gradient(to bottom, transparent, rgba(250, 250, 250, 0.95));
            transition: opacity 0.3s;
        }

        .scroll-indicator-content {
            display: flex;
            align-items: center;
            gap: 8px;
            animation: bounce 2s infinite;
        }

        .scroll-indicator-content span {
            font-size: 12px;
            font-weight: 600;
            color: var(--subzz-blue);
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-4px); }
        }

        .scroll-indicator.hidden {
            opacity: 0;
        }

        /* ── Combined Sign Section ────────────────────────────────────────── */
        .sign-section {
            background: var(--subzz-white);
            border-radius: 12px;
            box-shadow: var(--subzz-shadow-sm);
            padding: 24px;
            margin-bottom: 24px;
        }

        .sign-section h2 {
            color: var(--subzz-gray);
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
            border: 2px solid var(--subzz-border);
            border-radius: 8px;
            padding: 16px;
        }

        .sign-card-label {
            display: block;
            font-weight: 600;
            color: var(--subzz-gray);
            font-size: 14px;
            margin-bottom: 12px;
        }

        /* Form fields */
        .typed-name-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-field { margin-bottom: 0; }

        .form-field label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            color: var(--subzz-gray);
            font-size: 14px;
        }

        .form-field input[type="text"] {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid var(--subzz-border);
            border-radius: 8px;
            font-size: 15px;
            transition: border-color 0.2s;
            box-sizing: border-box;
            font-family: inherit;
            color: var(--subzz-gray);
        }

        .form-field input[type="text"]:focus {
            outline: none;
            border-color: var(--subzz-border-focus);
            box-shadow: 0 0 0 3px rgba(72, 202, 237, 0.15);
        }

        .field-hint {
            display: block;
            font-size: 12px;
            color: rgba(84, 84, 84, 0.7);
            margin-top: 4px;
            font-style: normal;
            line-height: 1.4;
        }

        /* Consent checkboxes */
        .consent-checkboxes {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .consent-checkbox {
            background: white;
            border: 2px solid var(--subzz-border);
            border-radius: 8px;
            padding: 16px;
            transition: all 0.2s;
            cursor: pointer;
        }

        .consent-checkbox:hover {
            border-color: var(--subzz-cyan);
        }

        .consent-checkbox.consent-checked {
            border-color: var(--subzz-cyan);
            background: rgba(72, 202, 237, 0.03);
        }

        .consent-checkbox label {
            display: flex;
            align-items: flex-start;
            cursor: pointer;
            margin: 0;
            line-height: 1.5;
            font-size: 14px;
            color: var(--subzz-gray);
        }

        .consent-checkbox input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-right: 12px;
            margin-top: 2px;
            cursor: pointer;
            flex-shrink: 0;
            accent-color: var(--subzz-blue);
        }

        .consent-checkbox a {
            color: var(--subzz-blue);
            text-decoration: underline;
        }

        .consent-checkbox a:hover {
            text-decoration: none;
        }

        /* Signature pad */
        .signature-pad-container {
            border: 2px solid var(--subzz-border);
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
            border-top: 1px solid var(--subzz-border);
        }

        /* ── Signature Mode Tabs (Draw / Type) ───────────────────────────── */
        .signature-mode-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
        }

        .sig-tab {
            flex: 1;
            padding: 10px 16px;
            border: 2px solid var(--subzz-border);
            border-radius: var(--subzz-radius-md);
            background: transparent;
            color: var(--subzz-gray);
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
            text-align: center;
        }

        .sig-tab:hover {
            border-color: var(--subzz-blue);
            color: var(--subzz-blue);
        }

        .sig-tab.active {
            background: var(--subzz-blue);
            border-color: var(--subzz-blue);
            color: #fff;
        }

        /* Typed signature input */
        .typed-sig-input {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid var(--subzz-border);
            border-radius: var(--subzz-radius-md);
            font-size: 15px;
            font-family: inherit;
            color: var(--subzz-gray);
            box-sizing: border-box;
            transition: border-color 0.2s;
            margin-bottom: 12px;
        }

        .typed-sig-input:focus {
            outline: none;
            border-color: var(--subzz-cyan);
            box-shadow: 0 0 0 3px rgba(72, 202, 237, 0.15);
        }

        /* Typed signature preview */
        .typed-sig-preview {
            min-height: 150px;
            border: 2px solid var(--subzz-border);
            border-radius: var(--subzz-radius-md);
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            transition: border-color 0.2s;
        }

        .typed-sig-preview.has-content {
            border-color: var(--subzz-cyan);
        }

        .typed-sig-placeholder {
            font-size: 14px;
            color: rgba(84, 84, 84, 0.5);
            margin: 0;
        }

        .typed-sig-text {
            font-family: 'Dancing Script', cursive;
            font-size: 36px;
            color: #000;
            margin: 0;
            word-break: break-word;
            text-align: center;
        }

        .signature-hint {
            font-size: 12px;
            color: rgba(84, 84, 84, 0.7);
            margin-top: 8px;
        }

        /* ── Buttons ──────────────────────────────────────────────────────── */
        .btn-primary {
            display: inline-block;
            padding: 14px 28px;
            font-size: 16px;
            font-weight: 700;
            text-align: center;
            text-decoration: none;
            border: none;
            border-radius: var(--subzz-radius-md);
            cursor: pointer;
            transition: all 0.2s;
            background: var(--subzz-red);
            color: #fff;
            font-family: inherit;
        }

        .btn-primary:hover:not(:disabled) {
            opacity: 0.9;
            transform: translateY(-1px);
            box-shadow: var(--subzz-shadow-lg);
        }

        .btn-primary:disabled {
            opacity: 0.5;
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
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            background: #fff;
            font-family: inherit;
        }

        /* Cancel button: charcoal outline */
        .btn-cancel {
            border: 2px solid var(--subzz-gray);
            color: var(--subzz-gray);
        }

        .btn-cancel:hover {
            background: var(--subzz-gray);
            color: #fff;
        }

        .contract-actions {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            margin-top: 24px;
        }

        /* ── Loading State (kept for JS compat) ──────────────────────────── */
        .loading-state {
            text-align: center;
            padding: 40px 20px;
            background: var(--subzz-white);
            border: 2px solid var(--subzz-blue);
            border-radius: 12px;
            margin-bottom: 24px;
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid var(--subzz-border);
            border-top-color: var(--subzz-blue);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 16px;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        .loading-state p {
            color: rgba(84, 84, 84, 0.7);
            font-size: 15px;
            font-weight: 600;
            margin: 0;
        }

        .step-actions { text-align: center; }

        /* ── Error page ───────────────────────────────────────────────────── */
        .error-message-section {
            text-align: center;
            padding: 30px 20px;
            background: var(--subzz-warning-bg);
            border: 1px solid var(--subzz-warning-border);
            border-radius: 12px;
        }

        .error-actions { margin-top: 20px; }

        /* ── Responsive ───────────────────────────────────────────────────── */
        @media (max-width: 600px) {
            .contract-header { padding: 16px; }
            .contract-card,
            .contract-terms-section,
            .sign-section { padding: 16px; }
            .contract-actions { flex-direction: column; }
            .contract-text-scroll { max-height: 300px; }

            .step-dot {
                width: 32px;
                height: 32px;
                font-size: 12px;
            }

            .step-label { font-size: 10px; }

            .typed-name-section { grid-template-columns: 1fr; }

            .summary-content {
                flex-direction: column;
                text-align: center;
            }
        }
        </style>
        
        <script>document.body.classList.add('subzz-contract-active');</script>
        <div class="subzz-contract-page">
            <div class="container">
                <!-- Step indicator card -->
                <div class="contract-header">
                    <h1>Subscription Agreement</h1>
                    <div class="checkout-progress">
                        <div class="progress-step done">
                            <span class="step-dot">&#10003;</span>
                            <span class="step-label">Plan</span>
                        </div>
                        <div class="progress-line active"></div>
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

                    <!-- Billing Date Summary (shown immediately from URL param) -->
                    <div id="billing-date-summary" class="billing-summary" style="display: none;">
                        <div class="summary-content">
                            <span class="summary-icon">&#10003;</span>
                            <span class="summary-text">
                                <strong>Your Billing Date:</strong> <span id="selected-billing-day-display"></span>
                            </span>
                            <a href="#" id="change-billing-date" class="change-link">Change</a>
                        </div>
                    </div>

                    <!-- Loading State (contract generation) -->
                    <div id="loading-contract" class="loading-state" style="display: none;">
                        <div class="loading-spinner"></div>
                        <p>Generating your contract...</p>
                    </div>

                    <!-- Contract Review and Signature (no billing Step 1 — billing date comes from checkout page) -->
                    <div id="step-2-3-container" class="contract-step" style="display: none;">

                        <!-- Contract Terms (populated via AJAX) — scrollable with scroll indicator -->
                        <div class="contract-terms-section">
                            <h2>Subscription Agreement</h2>
                            <p class="contract-scroll-hint">Please scroll through and review the full agreement before signing below.</p>
                            <div class="contract-text-wrapper">
                                <div id="contract-text-container" class="contract-text-scroll">
                                    <!-- Contract HTML will be inserted here via JavaScript -->
                                </div>
                                <div class="scroll-indicator" id="scroll-indicator">
                                    <div class="scroll-indicator-content">
                                        <span>Scroll to read agreement</span>
                                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M8 3V13M8 13L12 9M8 13L4 9" stroke="#2A8BEA" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </div>
                                </div>
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
                                            with the <a href="https://www.gov.za/documents/electronic-communications-and-transactions-act" target="_blank" rel="noopener noreferrer">Electronic Communications and Transactions Act (ECTA)</a>.
                                        </span>
                                    </label>
                                </div>

                                <div class="consent-checkbox">
                                    <label>
                                        <input type="checkbox" id="terms-consent" required>
                                        <span>
                                            I confirm that I have read and agree to all
                                            <a href="/terms-and-conditions/" target="_blank" rel="noopener noreferrer">terms and conditions</a>
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
                                    <label class="sign-card-label">Your Signature <span class="required-asterisk">*</span></label>

                                    <!-- Draw / Type tabs -->
                                    <div class="signature-mode-tabs">
                                        <button type="button" class="sig-tab active" data-mode="draw">Draw Signature</button>
                                        <button type="button" class="sig-tab" data-mode="type">Type Signature</button>
                                    </div>

                                    <!-- Draw panel (default) -->
                                    <div id="sig-panel-draw" class="sig-panel">
                                        <div class="signature-pad-container">
                                            <canvas id="signature-pad" height="180"></canvas>
                                            <div class="signature-controls">
                                                <button id="clear-signature" type="button" class="btn-secondary" style="border: 2px solid var(--subzz-cyan); color: var(--subzz-cyan);">Clear</button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Type panel (hidden by default) -->
                                    <div id="sig-panel-type" class="sig-panel" style="display:none;">
                                        <input type="text" id="typed-signature-input" class="typed-sig-input"
                                               placeholder="Type your full name" autocomplete="off">
                                        <div class="typed-sig-preview" id="typed-sig-preview">
                                            <p class="typed-sig-placeholder">Your signature will appear here</p>
                                            <p class="typed-sig-text" id="typed-sig-text" style="display:none;"></p>
                                        </div>
                                    </div>

                                    <!-- Hidden canvas for rendering typed signature to base64 -->
                                    <canvas id="typed-signature-canvas" width="600" height="200" style="display:none;"></canvas>

                                    <p class="signature-hint">Your signature is securely stored and legally binding. A copy of your signed agreement will be emailed to you.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="contract-actions">
                            <button type="button" id="cancel-order-button" class="btn-secondary btn-cancel"
                                    data-reference-id="<?php echo esc_attr($token_data['reference_id']); ?>">
                                Cancel Order
                            </button>

                            <button id="sign-agreement" class="btn-primary" disabled>
                                Sign Agreement & Continue to Payment
                            </button>
                        </div>

                    </div> <!-- End step-2-3-container -->

                </div>
            </div>

            <!-- Screen reader announcements -->
            <div class="sr-only" aria-live="polite" id="contract-announcer"></div>
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
        
        subzz_log('SUBZZ PAGE RENDER: Contract signature page rendered successfully (HYBRID architecture)');
    }

    /**
     * Save signature via AJAX - HYBRID ARCHITECTURE
     */
    public function save_signature() {
        subzz_log('=== SUBZZ SIGNATURE SAVE: AJAX request received (HYBRID architecture) ===');
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'subzz_signature')) {
            subzz_log('SUBZZ SIGNATURE ERROR: Nonce verification failed');
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
        
        subzz_log('SUBZZ SIGNATURE DATA: Reference ID: ' . $reference_id);
        subzz_log('SUBZZ SIGNATURE DATA: Customer email: ' . $customer_email);
        subzz_log('SUBZZ SIGNATURE DATA: Billing day: ' . ($billing_day_of_month ?? 'not provided'));
        subzz_log('SUBZZ SIGNATURE LEGAL: Typed full name: ' . $typed_full_name);
        subzz_log('SUBZZ SIGNATURE LEGAL: Typed initials: ' . $typed_initials);
        
        // Validate billing day
        if ($billing_day_of_month && !in_array($billing_day_of_month, array(1, 8, 15, 22))) {
            subzz_log('SUBZZ SIGNATURE ERROR: Invalid billing day: ' . $billing_day_of_month);
            wp_send_json_error('Invalid billing day');
            return;
        }
        
        // Verify JWT token
        $token_data = $this->decode_jwt_token($jwt_token);
        
        if (!$token_data) {
            subzz_log('SUBZZ SIGNATURE ERROR: JWT token validation failed');
            wp_send_json_error('Invalid token');
            return;
        }
        
        if ($token_data['reference_id'] !== $reference_id) {
            subzz_log('SUBZZ SIGNATURE ERROR: Reference ID mismatch');
            wp_send_json_error('Token reference mismatch');
            return;
        }
        
        subzz_log('SUBZZ SIGNATURE VALIDATION: JWT token verified successfully');

        // Retrieve contract HTML from transient
        $transient_key = 'subzz_contract_html_' . $reference_id;
        $contract_html = get_transient($transient_key);

        if ($contract_html) {
            subzz_log('SUBZZ PDF: Contract HTML retrieved from transient: ' . strlen($contract_html) . ' chars');
            delete_transient($transient_key);
        } else {
            // get_transient returns false when not found — must send null (not false)
            // to Azure, otherwise JSON sends boolean false for a string field
            $contract_html = null;
            subzz_log('SUBZZ PDF WARNING: Contract HTML not found in transient');
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

        subzz_log('SUBZZ SIGNATURE: Additional data includes billing_day_of_month: ' . ($billing_day_of_month ?? 'NULL'));

        // Store signature in Azure
        subzz_log('SUBZZ API CALL: Storing signature in Azure with billing date and legal compliance');
        
        $signature_stored = $this->azure_client->store_signature($customer_email, $signature_data, $reference_id, $additional_data);
        
        if (!$signature_stored) {
            subzz_log('SUBZZ API ERROR: Failed to store signature in Azure');
            wp_send_json_error('Failed to save signature. Please try again.');
            return;
        }
        
        subzz_log('SUBZZ API SUCCESS: Signature stored in Azure successfully');
        
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
                subzz_log("SUBZZ ORDER UPDATE: Updated billing day {$billing_day_of_month} in order meta");
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
            
            subzz_log("SUBZZ ORDER UPDATE: Updated Order {$order_id} with signature completion and billing day");
        }
        
        // Update order status
        $status_updated = $this->azure_client->update_order_status($reference_id, 'signature_completed');

        if (!$status_updated) {
            subzz_log('SUBZZ API WARNING: Failed to update order status in Azure');
        } else {
            subzz_log('SUBZZ API SUCCESS: Order status updated to signature_completed');
        }
        
        // Get initial payment and term info from order meta (reliable source, set during checkout)
        $initial_payment_amount = 0;
        $subscription_months = 12;
        $monthly_amount = 0;
        if (!empty($orders)) {
            $ip = $orders[0]->get_meta('_subzz_initial_payment_amount');
            if ($ip) $initial_payment_amount = floatval($ip);
            $sm = $orders[0]->get_meta('_subzz_selected_term_months');
            if ($sm) $subscription_months = intval($sm);
            $ma = $orders[0]->get_meta('_subzz_monthly_amount');
            if ($ma) $monthly_amount = floatval($ma);
        }

        // --- PAYMENT SESSION ---
        // Create LekkaPay session directly here for single-redirect flow.
        // Previously deferred to subscription-payment.php (caused double redirect).
        // The code 99 bug (22 Mar) was from duplicate sessions — now we only create here.
        // subscription-payment.php is kept as fallback if session creation fails.
        $checkout_url = null;
        $order_summary = null;

        try {
            // 1. Retrieve order data from Azure
            subzz_log('SUBZZ SIGNATURE: Retrieving order data for payment session');
            $order_data = $this->azure_client->retrieve_order_data($reference_id);

            if ($order_data) {
                // 2. Update status to payment_pending
                if (isset($order_data['order_status']) && $order_data['order_status'] === 'signature_completed') {
                    $this->azure_client->update_order_status($reference_id, 'payment_pending');
                    subzz_log('SUBZZ SIGNATURE: Order status updated to payment_pending');
                }

                // 3. Extract customer data
                $customer_data = array(
                    'email' => $order_data['customer_email'] ?? $customer_email,
                    'full_name' => trim(($order_data['customer_data']['first_name'] ?? '') . ' ' . ($order_data['customer_data']['last_name'] ?? ''))
                );
                if (empty($customer_data['full_name'])) {
                    $customer_data['full_name'] = $typed_full_name;
                }

                // 4. Determine payment amount (initial payment or monthly)
                $total_amount = $order_data['order_totals']['total'] ?? '0';
                $currency = $order_data['order_totals']['currency'] ?? 'ZAR';
                $payment_amount = ($initial_payment_amount > 0) ? $initial_payment_amount : $total_amount;

                // 5. Update WC order meta
                if (!empty($orders)) {
                    $order = $orders[0];
                    $order->update_meta_data('_subzz_payment_status', 'pending');
                    $order->update_meta_data('_subzz_payment_provider', 'lekkapay');
                    $order->update_meta_data('_subzz_order_status', 'payment_pending');
                    $order->save();
                }

                // 6. Create LekkaPay session via Azure API (single session creation point)
                subzz_log('SUBZZ SIGNATURE: Creating LekkaPay session via Azure API');
                $session_data = array(
                    'orderReferenceId' => $reference_id,
                    'customerEmail' => $customer_data['email'],
                    'customerName' => $customer_data['full_name'],
                    'amount' => floatval($payment_amount),
                    'currency' => $currency
                );

                $session_response = $this->azure_client->create_lekkapay_session($session_data);

                if ($session_response && isset($session_response['checkoutUrl'])) {
                    $checkout_url = $session_response['checkoutUrl'];
                    subzz_log('SUBZZ SIGNATURE: LekkaPay session created — direct checkout URL ready');
                } else {
                    subzz_log('SUBZZ SIGNATURE WARNING: LekkaPay session creation failed — falling back to subscription-payment page');
                }

                // Build order summary for success page display
                $order_summary = array(
                    'customer_name' => $customer_data['full_name'],
                    'customer_email' => $customer_data['email'],
                    'payment_amount' => $payment_amount,
                    'currency' => $currency,
                    'subscription_months' => $subscription_months,
                    'is_initial_payment' => ($initial_payment_amount > 0)
                );
            } else {
                subzz_log('SUBZZ SIGNATURE WARNING: Could not retrieve order data — falling back to subscription-payment page');
            }
        } catch (Exception $e) {
            subzz_log('SUBZZ SIGNATURE WARNING: Exception during payment session creation: ' . $e->getMessage());
            // Fall through to fallback redirect_url below
        }

        // Fallback: redirect to subscription-payment.php (used when LekkaPay session creation fails)
        $fallback_url = add_query_arg(array(
            'reference_id' => $reference_id,
            'signature_confirmed' => 'yes',
            'billing_day' => $billing_day_of_month,
            'subscription_months' => $subscription_months,
            'initial_payment' => $initial_payment_amount
        ), home_url('/subscription-payment/'));

        subzz_log('SUBZZ SIGNATURE SUCCESS: Complete workflow finished (HYBRID architecture)');

        $response_data = array(
            'message' => 'Contract signed successfully! Redirecting to payment...',
            'redirect_url' => $fallback_url,
            'reference_id' => $reference_id,
            'billing_day' => $billing_day_of_month,
            'variant_info' => $variant_info
        );

        // Add checkout_url + order_summary if LekkaPay session was created
        if ($checkout_url) {
            $response_data['checkout_url'] = $checkout_url;
            $response_data['order_summary'] = $order_summary;
            subzz_log('SUBZZ SIGNATURE SUCCESS: Direct LekkaPay redirect ready — skipping subscription-payment page');
        } else {
            subzz_log('SUBZZ SIGNATURE SUCCESS: Falling back to subscription-payment page: ' . $fallback_url);
        }

        wp_send_json_success($response_data);
    }
    /**
     * Handle order cancellation and cart restoration
     */
    public function handle_order_cancellation() {
        subzz_log('=== SUBZZ ORDER CANCELLATION: Request received ===');
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'subzz_signature')) {
            subzz_log('SUBZZ CANCELLATION ERROR: Nonce verification failed');
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        $reference_id = isset($_POST['reference_id']) ? sanitize_text_field($_POST['reference_id']) : '';
        
        if (empty($reference_id)) {
            subzz_log('SUBZZ CANCELLATION ERROR: No reference ID provided');
            wp_send_json_error(array('message' => 'Invalid request'));
            return;
        }
        
        subzz_log('SUBZZ CANCELLATION: Processing for Reference ID: ' . $reference_id);
        
        // Find WooCommerce order by reference ID
        $orders = wc_get_orders(array(
            'meta_key' => '_subzz_reference_id',
            'meta_value' => $reference_id,
            'limit' => 1
        ));
        
        if (empty($orders)) {
            subzz_log('SUBZZ CANCELLATION ERROR: Order not found for reference: ' . $reference_id);
            wp_send_json_error(array('message' => 'Order not found'));
            return;
        }
        
        $order = $orders[0];
        $order_id = $order->get_id();
        
        subzz_log('SUBZZ CANCELLATION: Found WooCommerce Order ID: ' . $order_id);
        
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
            
            subzz_log("SUBZZ CANCELLATION: Stored item - Product: {$product_id}, Qty: {$quantity}");
        }
        
        // Cancel the order
        $order->update_status('cancelled', 'Order cancelled by customer during signature process');
        subzz_log('SUBZZ CANCELLATION: Order status updated to cancelled');
        
        // Update order metadata
        $order->update_meta_data('_subzz_cancellation_reason', 'customer_cancelled_at_signature');
        $order->update_meta_data('_subzz_cancelled_at', current_time('mysql'));
        $order->save();
        
        // Notify Azure backend about cancellation
        $this->azure_client->update_order_status($reference_id, 'cancelled');
        subzz_log('SUBZZ CANCELLATION: Azure backend notified');
        
        // Clear any existing cart
        WC()->cart->empty_cart();
        subzz_log('SUBZZ CANCELLATION: Cart cleared');
        
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
        subzz_log('SUBZZ CANCELLATION: Session data cleared');
        
        $cart_count = WC()->cart->get_cart_contents_count();
        subzz_log("SUBZZ CANCELLATION SUCCESS: Cart restored with {$cart_count} items");
        
        wp_send_json_success(array(
            'message' => 'Order cancelled successfully',
            'cart_url' => wc_get_cart_url(),
            'cart_items_restored' => count($order_items)
        ));
    }
}
?>