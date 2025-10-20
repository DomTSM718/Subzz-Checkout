<?php
/**
 * Plugin Name: Subzz Subscription Payments
 * Description: Custom subscription payment processing with contract signatures and Azure backend integration
 * Version: 1.4.0 (HYBRID Architecture Update)
 * Author: Subzz Team
 * 
 * HYBRID ARCHITECTURE UPDATE (Oct 19, 2025):
 * - Added billing-date-handler.js loading
 * - Updated signature-handler.js dependencies
 * - Separated billing date selection from signature logic
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin activation hook
register_activation_hook(__FILE__, 'subzz_activate_plugin');

function subzz_activate_plugin() {
    error_log('SUBZZ DEBUG: Plugin activation started');
    
    // Create custom rewrite rules for contract signature page
    add_rewrite_rule(
        '^contract-signature/?$',
        'index.php?subzz_contract_page=1',
        'top'
    );
    
    // Add rewrite rule for payment page
    add_rewrite_rule(
        '^subscription-payment/?$',
        'index.php?subzz_payment_page=1',
        'top'
    );
    
    // Flush rewrite rules to make new URLs work
    flush_rewrite_rules();
    
    error_log('SUBZZ DEBUG: Plugin activated - rewrite rules flushed');
}

// Plugin deactivation hook
register_deactivation_hook(__FILE__, 'subzz_deactivate_plugin');

function subzz_deactivate_plugin() {
    error_log('SUBZZ DEBUG: Plugin deactivation - flushing rewrite rules');
    flush_rewrite_rules();
}

// Initialize plugin
add_action('plugins_loaded', 'subzz_init_plugin');

function subzz_init_plugin() {
    error_log('SUBZZ DEBUG: Plugin initialization started');
    
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'subzz_woocommerce_missing_notice');
        error_log('SUBZZ ERROR: WooCommerce not found - plugin cannot initialize');
        return;
    }
    
    // Include required files
    require_once plugin_dir_path(__FILE__) . 'includes/class-azure-api-client.php';
    require_once plugin_dir_path(__FILE__) . 'includes/class-payment-handler.php';
    require_once plugin_dir_path(__FILE__) . 'includes/class-contract-integration.php';
    
    // Initialize classes
    new Subzz_Payment_Handler();
    new Subzz_Contract_Integration();
    
    // Test Azure connection on initialization
    error_log('SUBZZ DEBUG: Testing Azure backend connection...');
    $azure_client = new Subzz_Azure_API_Client();
    $connection_test = $azure_client->test_connection();
    error_log('SUBZZ DEBUG: Azure connection test result: ' . ($connection_test ? 'SUCCESS' : 'FAILED'));
    
    if (!$connection_test) {
        error_log('SUBZZ WARNING: Azure backend connection failed - signature workflow may not work');
    }
    
    error_log('SUBZZ DEBUG: Plugin initialized successfully with Azure integration');
}

function subzz_woocommerce_missing_notice() {
    echo '<div class="notice notice-error"><p><strong>Subzz Subscription Payments:</strong> WooCommerce is required and must be active.</p></div>';
}

// Add custom query vars and rewrite rules - CRITICAL FOR SIGNATURE AND PAYMENT PAGES
add_action('init', 'subzz_add_rewrite_rules');
function subzz_add_rewrite_rules() {
    // Add rewrite rule for contract signature page
    add_rewrite_rule(
        '^contract-signature/?$',
        'index.php?subzz_contract_page=1',
        'top'
    );
    
    // Add rewrite rule for payment page
    add_rewrite_rule(
        '^subscription-payment/?$',
        'index.php?subzz_payment_page=1',
        'top'
    );
    
    error_log('SUBZZ DEBUG: Rewrite rules added for contract-signature and subscription-payment URLs');
}

add_filter('query_vars', 'subzz_custom_query_vars');
function subzz_custom_query_vars($vars) {
    $vars[] = 'subzz_contract_page';
    $vars[] = 'subzz_payment_page';
    return $vars;
}

// Force rewrite rules refresh for contract signature and payment pages
add_action('init', 'subzz_ensure_rewrite_rules', 999);
function subzz_ensure_rewrite_rules() {
    // Check if our rules exist
    $rules = get_option('rewrite_rules');
    if (!isset($rules['^contract-signature/?$']) || !isset($rules['^subscription-payment/?$'])) {
        error_log('SUBZZ DEBUG: Rewrite rules missing - flushing...');
        flush_rewrite_rules(false);
    }
}

// Handle subscription payment page routing
add_action('template_redirect', 'subzz_handle_payment_page');
function subzz_handle_payment_page() {
    global $wp;
    
    // Check if this is the subscription payment URL
    if (!isset($wp->request) || $wp->request !== 'subscription-payment') {
        return;
    }
    
    error_log('=== SUBZZ PAYMENT PAGE: Request received ===');
    
    // Load the payment page template
    $template_path = plugin_dir_path(__FILE__) . 'templates/subscription-payment.php';
    
    if (file_exists($template_path)) {
        include $template_path;
        exit;
    } else {
        error_log('SUBZZ PAYMENT PAGE ERROR: Template file not found at ' . $template_path);
        wp_die('Payment page template not found. Please contact support.');
    }
}

// UPDATED: Force load signature assets EARLY - before wp_die() can interrupt
add_action('template_redirect', 'subzz_force_load_signature_assets_early', 1);
function subzz_force_load_signature_assets_early() {
    global $wp;
    
    // Check multiple ways to detect contract signature page
    $is_contract_page = false;
    
    // Method 1: Check URL request
    if (isset($wp->request) && $wp->request === 'contract-signature') {
        $is_contract_page = true;
        error_log('SUBZZ DEBUG: Contract page detected via URL request (early)');
    }
    
    // Method 2: Check query variable
    if (get_query_var('subzz_contract_page')) {
        $is_contract_page = true;
        error_log('SUBZZ DEBUG: Contract page detected via query variable (early)');
    }
    
    // Method 3: Check if current URL contains contract-signature
    $current_url = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($current_url, 'contract-signature') !== false) {
        $is_contract_page = true;
        error_log('SUBZZ DEBUG: Contract page detected via URL analysis (early)');
    }
    
    if (!$is_contract_page) {
        return; // Not the signature page
    }
    
    error_log('SUBZZ DEBUG: Loading signature assets EARLY on contract signature page (HYBRID architecture)');
    
    // Force enqueue the assets immediately
    add_action('wp_enqueue_scripts', 'subzz_enqueue_signature_assets_now', 1);
    
    // Also load them right now for wp_die() scenarios
    subzz_enqueue_signature_assets_now();
}

// ========================================================================
// HYBRID ARCHITECTURE UPDATE: Asset loading function
// Updated to load billing-date-handler.js before signature-handler.js
// ========================================================================
function subzz_enqueue_signature_assets_now() {
    error_log('SUBZZ DEBUG: Actually enqueuing signature assets now (HYBRID architecture)');
    
    // Check if signature-pad.min.js file exists
    $signature_pad_path = plugin_dir_path(__FILE__) . 'assets/signature-pad.min.js';
    if (!file_exists($signature_pad_path)) {
        error_log('SUBZZ WARNING: signature-pad.min.js not found at: ' . $signature_pad_path);
    }
    
    // ========================================================================
    // STEP 1: Load signature pad library
    // ========================================================================
    if (file_exists($signature_pad_path)) {
        wp_enqueue_script(
            'signature-pad', 
            plugin_dir_url(__FILE__) . 'assets/signature-pad.min.js', 
            array(), 
            '4.1.7', 
            true
        );
        error_log('SUBZZ DEBUG: Loading signature-pad from local file');
    } else {
        // Fallback to CDN
        wp_enqueue_script(
            'signature-pad', 
            'https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js', 
            array(), 
            '4.1.7', 
            true
        );
        error_log('SUBZZ DEBUG: Using CDN for signature-pad.min.js');
    }
    
    // ========================================================================
    // STEP 2: Load billing date handler (HYBRID ARCHITECTURE - NEW)
    // This must load BEFORE signature-handler.js
    // ========================================================================
    wp_enqueue_script(
        'subzz-billing-date', 
        plugin_dir_url(__FILE__) . 'assets/billing-date-handler.js', 
        array('jquery'),  // Only depends on jQuery
        '1.0.0', 
        true
    );
    error_log('SUBZZ DEBUG: Loading billing-date-handler.js (HYBRID architecture - Step 1)');
    
    // ========================================================================
    // STEP 3: Load signature handler (depends on billing date handler)
    // HYBRID ARCHITECTURE UPDATE: Added 'subzz-billing-date' dependency
    // ========================================================================
    wp_enqueue_script(
        'subzz-signature', 
        plugin_dir_url(__FILE__) . 'assets/signature-handler.js', 
        array('signature-pad', 'jquery', 'subzz-billing-date'),  // Added billing-date dependency
        '1.0.2',  // Version bump to indicate HYBRID architecture update
        true
    );
    error_log('SUBZZ DEBUG: Loading signature-handler.js with billing-date dependency (HYBRID architecture - Steps 2 & 3)');
    
    // ========================================================================
    // STEP 4: Load CSS
    // ========================================================================
    wp_enqueue_style(
        'subzz-contract', 
        plugin_dir_url(__FILE__) . 'assets/contract-styles.css', 
        array(), 
        '1.0.1'
    );
    
    error_log('SUBZZ DEBUG: All signature page assets enqueued successfully (HYBRID architecture)');
    error_log('SUBZZ DEBUG: Load order: signature-pad → billing-date-handler → signature-handler → CSS');
    
    // Debug file paths
    error_log('SUBZZ DEBUG: CSS file URL: ' . plugin_dir_url(__FILE__) . 'assets/contract-styles.css');
    error_log('SUBZZ DEBUG: Billing date JS URL: ' . plugin_dir_url(__FILE__) . 'assets/billing-date-handler.js');
    error_log('SUBZZ DEBUG: Signature JS URL: ' . plugin_dir_url(__FILE__) . 'assets/signature-handler.js');
}

// Handle checkout return with signature completion - NEW FUNCTIONALITY
add_action('template_redirect', 'subzz_handle_signature_return');
function subzz_handle_signature_return() {
    // Check if this is a return from signature page
    if (!isset($_GET['subzz_signature_complete']) || !is_checkout()) {
        return;
    }
    
    $reference_id = sanitize_text_field($_GET['reference_id'] ?? '');
    $signature_confirmed = sanitize_text_field($_GET['signature_confirmed'] ?? '');
    
    if ($reference_id && $signature_confirmed === 'yes') {
        error_log('SUBZZ DEBUG: Customer returned from signature page - Reference ID: ' . $reference_id);
        
        // Add success notice to checkout
        wc_add_notice(
            'Contract signed successfully! Please complete your payment below to activate your subscription.',
            'success'
        );
        
        // Store signature completion in session for potential LekkaPay integration
        if (!session_id()) {
            session_start();
        }
        $_SESSION['subzz_signature_completed'] = true;
        $_SESSION['subzz_reference_id'] = $reference_id;
        
        error_log('SUBZZ DEBUG: Signature completion confirmed - ready for payment processing');
    }
}

// ENHANCED: Add custom checkout fields for comprehensive data collection
add_action('woocommerce_after_checkout_billing_form', 'add_subzz_checkout_fields');
function add_subzz_checkout_fields($checkout) {
    echo '<div id="subzz_additional_fields"><h3>' . __('Additional Information') . '</h3>';

    // ID Number field for South African customers
    woocommerce_form_field('billing_id_number', array(
        'type' => 'text',
        'class' => array('form-row-wide'),
        'label' => __('South African ID Number'),
        'placeholder' => __('13-digit ID number (optional)'),
        'required' => false, // Optional - can be filled during contract signing
        'custom_attributes' => array(
            'pattern' => '[0-9]{13}',
            'maxlength' => '13'
        )
    ), $checkout->get_value('billing_id_number'));

    echo '</div>';

    // Add some styling to make it look professional
    echo '<style>
        #subzz_additional_fields {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }
        #subzz_additional_fields h3 {
            margin-bottom: 15px;
            color: #333;
            font-size: 18px;
        }
        #billing_id_number_field {
            margin-bottom: 15px;
        }
        #billing_id_number_field label {
            font-weight: 500;
        }
        #billing_id_number {
            font-family: monospace;
            letter-spacing: 1px;
        }
    </style>';
}

// ENHANCED: Save custom checkout fields
add_action('woocommerce_checkout_update_order_meta', 'save_subzz_checkout_fields');
function save_subzz_checkout_fields($order_id) {
    if (!empty($_POST['billing_id_number'])) {
        update_post_meta($order_id, '_billing_id_number', sanitize_text_field($_POST['billing_id_number']));
        error_log('SUBZZ CHECKOUT: Saved ID number for order ' . $order_id);
    }
}

// ENHANCED: Display custom fields in admin order page
add_action('woocommerce_admin_order_data_after_billing_address', 'display_subzz_admin_order_meta', 10, 1);
function display_subzz_admin_order_meta($order) {
    $id_number = $order->get_meta('_billing_id_number');
    if ($id_number) {
        echo '<p><strong>' . __('ID Number') . ':</strong> ' . esc_html($id_number) . '</p>';
    }
}

// ENHANCED: Extract comprehensive customer data from WooCommerce order
function extract_customer_data_from_wc_order($order) {
    error_log('SUBZZ CONTRACT: Extracting customer data from WooCommerce order');
    
    $customer_data = array(
        'email' => $order->get_billing_email(),
        'first_name' => $order->get_billing_first_name(),
        'last_name' => $order->get_billing_last_name(),
        'phone_number' => $order->get_billing_phone(),
        'id_number' => '', // Will be filled from meta or during signature
        
        // Billing address
        'billing_address' => trim($order->get_billing_address_1() . (!empty($order->get_billing_address_2()) ? ', ' . $order->get_billing_address_2() : '')),
        'city' => $order->get_billing_city(),
        'province' => $order->get_billing_state(),
        'postal_code' => $order->get_billing_postcode(),
        
        // Delivery address (formatted)
        'delivery_address' => format_delivery_address($order),
        
        // Additional fields for contract
        'country' => $order->get_billing_country() ?: 'South Africa',
        'company' => $order->get_billing_company() ?: ''
    );

    // Try to get ID number from custom checkout fields if available
    $id_number_meta = $order->get_meta('_billing_id_number');
    if (!empty($id_number_meta)) {
        $customer_data['id_number'] = $id_number_meta;
        error_log('SUBZZ CONTRACT: Found ID number in order meta: ' . $id_number_meta);
    }

    error_log('SUBZZ CONTRACT: Customer data extracted - Name: ' . $customer_data['first_name'] . ' ' . $customer_data['last_name']);
    error_log('SUBZZ CONTRACT: Customer email: ' . $customer_data['email']);
    error_log('SUBZZ CONTRACT: Customer phone: ' . $customer_data['phone_number']);

    return $customer_data;
}

// ENHANCED: Format delivery address for contract
function format_delivery_address($order) {
    $address_parts = array();
    
    // Use shipping address if different from billing
    if ($order->get_shipping_address_1()) {
        $address_parts[] = $order->get_shipping_address_1();
        if ($order->get_shipping_address_2()) {
            $address_parts[] = $order->get_shipping_address_2();
        }
        $address_parts[] = $order->get_shipping_city();
        $address_parts[] = $order->get_shipping_state();
        $address_parts[] = $order->get_shipping_postcode();
    } else {
        // Fallback to billing address
        $address_parts[] = $order->get_billing_address_1();
        if ($order->get_billing_address_2()) {
            $address_parts[] = $order->get_billing_address_2();
        }
        $address_parts[] = $order->get_billing_city();
        $address_parts[] = $order->get_billing_state();
        $address_parts[] = $order->get_billing_postcode();
    }
    
    // Filter out empty parts and join
    $address_parts = array_filter($address_parts);
    return implode(', ', $address_parts);
}

// Debug function to check if our URLs are working
add_action('wp', 'subzz_debug_url_handling');
function subzz_debug_url_handling() {
    global $wp;
    
    if (isset($wp->request) && $wp->request === 'contract-signature') {
        error_log('SUBZZ DEBUG: Contract signature URL matched - WordPress routing working');
    }
    
    if (isset($wp->request) && $wp->request === 'subscription-payment') {
        error_log('SUBZZ DEBUG: Subscription payment URL matched - WordPress routing working');
    }
    
    if (get_query_var('subzz_contract_page')) {
        error_log('SUBZZ DEBUG: Contract page query var detected - rewrite rules working');
    }
    
    if (get_query_var('subzz_payment_page')) {
        error_log('SUBZZ DEBUG: Payment page query var detected - rewrite rules working');
    }
}

// Add admin menu for testing and configuration
add_action('admin_menu', 'subzz_add_admin_menu');
function subzz_add_admin_menu() {
    add_options_page(
        'Subzz Subscription Settings',
        'Subzz Subscriptions',
        'manage_options',
        'subzz-settings',
        'subzz_settings_page'
    );
}

function subzz_settings_page() {
    if (isset($_POST['test_azure'])) {
        $azure_client = new Subzz_Azure_API_Client();
        $connection_test = $azure_client->test_connection();
        echo '<div class="notice notice-' . ($connection_test ? 'success' : 'error') . '"><p>';
        echo 'Azure connection test: ' . ($connection_test ? 'SUCCESS' : 'FAILED');
        echo '</p></div>';
    }
    
    if (isset($_POST['flush_rules'])) {
        flush_rewrite_rules();
        echo '<div class="notice notice-success"><p>Rewrite rules flushed successfully!</p></div>';
    }
    
    if (isset($_POST['test_signature_page'])) {
        // Check if signature page assets exist
        $plugin_path = plugin_dir_path(__FILE__);
        $css_exists = file_exists($plugin_path . 'assets/contract-styles.css');
        $js_billing_exists = file_exists($plugin_path . 'assets/billing-date-handler.js');
        $js_signature_exists = file_exists($plugin_path . 'assets/signature-handler.js');
        $signature_pad_exists = file_exists($plugin_path . 'assets/signature-pad.min.js');
        
        echo '<div class="notice notice-info"><p>';
        echo '<strong>Signature Page Asset Check (HYBRID Architecture):</strong><br>';
        echo 'contract-styles.css: ' . ($css_exists ? '✅ Found' : '❌ Missing') . '<br>';
        echo 'billing-date-handler.js: ' . ($js_billing_exists ? '✅ Found' : '❌ Missing') . '<br>';
        echo 'signature-handler.js: ' . ($js_signature_exists ? '✅ Found' : '❌ Missing') . '<br>';
        echo 'signature-pad.min.js: ' . ($signature_pad_exists ? '✅ Found' : '⚠️ Missing (will use CDN)') . '<br>';
        echo '</p></div>';
    }
    
    if (isset($_POST['test_payment_page'])) {
        // Check if payment page template exists
        $template_exists = file_exists(plugin_dir_path(__FILE__) . 'templates/subscription-payment.php');
        
        echo '<div class="notice notice-info"><p>';
        echo '<strong>Payment Page Check:</strong><br>';
        echo 'subscription-payment.php template: ' . ($template_exists ? '✅ Found' : '❌ Missing') . '<br>';
        echo '</p></div>';
    }
    
    ?>
    <div class="wrap">
        <h1>Subzz Subscription Settings</h1>
        
        <h2>HYBRID Architecture Status</h2>
        <div style="background: #e7f3ff; border-left: 4px solid #007bff; padding: 15px; margin: 20px 0;">
            <p><strong>✅ HYBRID Architecture Active</strong></p>
            <p>Version 1.4.0 with separated billing date selection and signature handling.</p>
            <ul style="margin-left: 20px;">
                <li>✅ billing-date-handler.js - Step 1: Billing date selection</li>
                <li>✅ signature-handler.js - Steps 2 & 3: Legal compliance and signature</li>
                <li>✅ Event-driven architecture with proper load order</li>
            </ul>
        </div>
        
        <h2>Page Testing</h2>
        <form method="post">
            <p>Test if pages and assets are properly set up:</p>
            <input type="submit" name="test_signature_page" value="Check Signature Page Assets" class="button-primary">
            <input type="submit" name="test_payment_page" value="Check Payment Page Template" class="button-primary">
        </form>
        
        <p>Contract signature page URL: <a href="<?php echo home_url('/contract-signature/'); ?>" target="_blank"><?php echo home_url('/contract-signature/'); ?></a></p>
        <p>Payment page URL: <a href="<?php echo home_url('/subscription-payment/'); ?>" target="_blank"><?php echo home_url('/subscription-payment/'); ?></a></p>
        <p><small>Note: Pages should show "Missing contract token" or "Missing required parameters" error with proper styling if working correctly</small></p>
        
        <h2>URL Testing & Fixes</h2>
        <form method="post">
            <p>If your pages show "404 Not Found", click this button:</p>
            <input type="submit" name="flush_rules" value="Fix Page URLs" class="button-primary">
        </form>
        
        <h2>Azure Backend Connection</h2>
        <form method="post">
            <p>Test connection to Azure backend (localhost:5000)</p>
            <input type="submit" name="test_azure" value="Test Azure Connection" class="button-secondary">
        </form>
        
        <h2>Plugin Status</h2>
        <ul>
            <li>WooCommerce: <?php echo class_exists('WooCommerce') ? '✅ Active' : '❌ Not Found'; ?></li>
            <li>Payment Handler: <?php echo class_exists('Subzz_Payment_Handler') ? '✅ Loaded' : '❌ Not Loaded'; ?></li>
            <li>Contract Integration: <?php echo class_exists('Subzz_Contract_Integration') ? '✅ Loaded' : '❌ Not Loaded'; ?></li>
            <li>Azure API Client: <?php echo class_exists('Subzz_Azure_API_Client') ? '✅ Loaded' : '❌ Not Loaded'; ?></li>
        </ul>
        
        <h2>Enhanced Features Status</h2>
        <ul>
            <li>✅ Custom Checkout Fields: Active (ID Number collection)</li>
            <li>✅ Enhanced Customer Data: Active (Name, Address, Phone extraction)</li>
            <li>✅ Contract Generation: Enhanced with checkout data</li>
            <li>✅ Signature Processing: Enhanced with customer email lookup</li>
            <li>✅ HYBRID Architecture: Active (Separated billing date and signature logic)</li>
        </ul>
        
        <?php
        // Show current rewrite rules for debugging
        $rules = get_option('rewrite_rules');
        $signature_rule_active = isset($rules['^contract-signature/?$']);
        $payment_rule_active = isset($rules['^subscription-payment/?$']);
        
        if ($signature_rule_active) {
            echo '<p style="color: green;">✅ Contract signature URL rule is active</p>';
        } else {
            echo '<p style="color: red;">❌ Contract signature URL rule is missing - click "Fix Page URLs" above</p>';
        }
        
        if ($payment_rule_active) {
            echo '<p style="color: green;">✅ Payment page URL rule is active</p>';
        } else {
            echo '<p style="color: red;">❌ Payment page URL rule is missing - click "Fix Page URLs" above</p>';
        }
        
        // Show plugin directory information
        echo '<h2>Plugin Directory Information</h2>';
        echo '<p><strong>Plugin Directory:</strong> ' . plugin_dir_path(__FILE__) . '</p>';
        echo '<p><strong>Plugin URL:</strong> ' . plugin_dir_url(__FILE__) . '</p>';
        echo '<p><strong>Assets Directory:</strong> ' . plugin_dir_path(__FILE__) . 'assets/</p>';
        echo '<p><strong>Templates Directory:</strong> ' . plugin_dir_path(__FILE__) . 'templates/</p>';
        echo '<p><strong>Version:</strong> 1.4.0 (HYBRID Architecture - Enhanced with separated billing date selection)</p>';
        ?>
    </div>
    <?php
}

// Create signature-pad.min.js if it doesn't exist
add_action('admin_init', 'subzz_create_missing_assets');
function subzz_create_missing_assets() {
    $plugin_path = plugin_dir_path(__FILE__);
    $assets_dir = $plugin_path . 'assets/';
    $signature_pad_file = $assets_dir . 'signature-pad.min.js';
    
    // Create assets directory if it doesn't exist
    if (!file_exists($assets_dir)) {
        wp_mkdir_p($assets_dir);
        error_log('SUBZZ DEBUG: Created assets directory: ' . $assets_dir);
    }
    
    // Download signature-pad.min.js if it doesn't exist
    if (!file_exists($signature_pad_file)) {
        $signature_pad_url = 'https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js';
        $signature_pad_content = wp_remote_get($signature_pad_url);
        
        if (!is_wp_error($signature_pad_content)) {
            $body = wp_remote_retrieve_body($signature_pad_content);
            if (!empty($body)) {
                file_put_contents($signature_pad_file, $body);
                error_log('SUBZZ DEBUG: Downloaded signature-pad.min.js to: ' . $signature_pad_file);
            }
        } else {
            error_log('SUBZZ WARNING: Failed to download signature-pad.min.js: ' . $signature_pad_content->get_error_message());
        }
    }
}
?>