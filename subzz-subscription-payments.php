<?php
/**
 * Plugin Name: Subzz Subscription Payments
 * Description: Custom subscription payment processing with contract signatures and Azure backend integration
 * Version: 2.0.0 (Customer Portal + Payment Update)
 * Author: Subzz Team
 * 
 * LEKKAPAY INTEGRATION UPDATE (Oct 29, 2025):
 * - Added payment success page routing (/payment-success/)
 * - Added payment cancelled page routing (/payment-cancelled/)
 * - Complete LekkaPay return URL support
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

    // Register all rewrite rules before flushing
    subzz_add_rewrite_rules();
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
    require_once plugin_dir_path(__FILE__) . 'includes/class-customer-portal.php';

    // Initialize classes
    new Subzz_Payment_Handler();
    new Subzz_Contract_Integration();
    new Subzz_Customer_Portal();

    error_log('SUBZZ DEBUG: Plugin initialized successfully with Azure integration');
}

function subzz_woocommerce_missing_notice() {
    echo '<div class="notice notice-error"><p><strong>Subzz Subscription Payments:</strong> WooCommerce is required and must be active.</p></div>';
}

// Add custom query vars and rewrite rules - CRITICAL FOR ALL PAGES
add_action('init', 'subzz_add_rewrite_rules');
function subzz_add_rewrite_rules() {
    add_rewrite_rule('^contract-signature/?$', 'index.php?subzz_contract_page=1', 'top');
    add_rewrite_rule('^subscription-payment/?$', 'index.php?subzz_payment_page=1', 'top');
    add_rewrite_rule('^payment-success/?$', 'index.php?subzz_payment_success=1', 'top');
    add_rewrite_rule('^payment-cancelled/?$', 'index.php?subzz_payment_cancelled=1', 'top');
    add_rewrite_rule('^checkout-subscription/?$', 'index.php?subzz_checkout_subscription=1', 'top');
    add_rewrite_rule('^payment-update/?$', 'index.php?subzz_payment_update=1', 'top');
}

add_filter('query_vars', 'subzz_custom_query_vars');
function subzz_custom_query_vars($vars) {
    $vars[] = 'subzz_contract_page';
    $vars[] = 'subzz_payment_page';
    $vars[] = 'subzz_payment_success';
    $vars[] = 'subzz_payment_cancelled';
    $vars[] = 'subzz_checkout_subscription';
    $vars[] = 'subzz_payment_update';
    return $vars;
}

// Force rewrite rules refresh for all custom pages
add_action('init', 'subzz_ensure_rewrite_rules', 999);
function subzz_ensure_rewrite_rules() {
    $rules = get_option('rewrite_rules');
    if (!isset($rules['^contract-signature/?$']) ||
        !isset($rules['^subscription-payment/?$']) ||
        !isset($rules['^payment-success/?$']) ||
        !isset($rules['^payment-cancelled/?$']) ||
        !isset($rules['^checkout-subscription/?$']) ||
        !isset($rules['^payment-update/?$'])) {
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

// Handle payment success page routing (LekkaPay return URL)
add_action('template_redirect', 'subzz_handle_success_page');
function subzz_handle_success_page() {
    global $wp;
    
    // Check if this is the payment success URL
    if (!isset($wp->request) || $wp->request !== 'payment-success') {
        return;
    }
    
    error_log('=== SUBZZ PAYMENT SUCCESS PAGE: Request received ===');
    
    // Log any parameters received from LekkaPay
    if (!empty($_GET)) {
        error_log('SUBZZ PAYMENT SUCCESS: URL parameters: ' . print_r($_GET, true));
    }
    
    // Load the payment success template
    $template_path = plugin_dir_path(__FILE__) . 'templates/payment-success.php';
    
    if (file_exists($template_path)) {
        include $template_path;
        exit;
    } else {
        error_log('SUBZZ PAYMENT SUCCESS ERROR: Template file not found at ' . $template_path);
        wp_die('Payment success page template not found. Please contact support.');
    }
}

// Handle payment cancelled page routing (LekkaPay cancel URL)
add_action('template_redirect', 'subzz_handle_cancelled_page');
function subzz_handle_cancelled_page() {
    global $wp;
    
    // Check if this is the payment cancelled URL
    if (!isset($wp->request) || $wp->request !== 'payment-cancelled') {
        return;
    }
    
    error_log('=== SUBZZ PAYMENT CANCELLED PAGE: Request received ===');
    
    // Log any parameters received from LekkaPay
    if (!empty($_GET)) {
        error_log('SUBZZ PAYMENT CANCELLED: URL parameters: ' . print_r($_GET, true));
    }
    
    // Load the payment cancelled template
    $template_path = plugin_dir_path(__FILE__) . 'templates/payment-cancelled.php';
    
    if (file_exists($template_path)) {
        include $template_path;
        exit;
    } else {
        error_log('SUBZZ PAYMENT CANCELLED ERROR: Template file not found at ' . $template_path);
        wp_die('Payment cancelled page template not found. Please contact support.');
    }
}

// Handle checkout subscription page routing
add_action('template_redirect', 'subzz_handle_checkout_subscription_page');
function subzz_handle_checkout_subscription_page() {
    global $wp;

    if (!isset($wp->request) || $wp->request !== 'checkout-subscription') {
        return;
    }

    error_log('=== SUBZZ CHECKOUT SUBSCRIPTION: Request received ===');

    // Require login
    if (!is_user_logged_in()) {
        error_log('SUBZZ CHECKOUT SUBSCRIPTION: User not logged in - redirecting to login');
        wp_redirect(wp_login_url(home_url('/checkout-subscription/')));
        exit;
    }

    // Require WooCommerce cart with subscription product
    if (!class_exists('WooCommerce') || !WC()->cart || WC()->cart->is_empty()) {
        error_log('SUBZZ CHECKOUT SUBSCRIPTION: Cart empty - redirecting to cart');
        wp_redirect(wc_get_cart_url());
        exit;
    }

    // Check for subscription product in cart
    $has_subscription = false;
    foreach (WC()->cart->get_cart() as $cart_item) {
        $subscription_enabled = get_post_meta($cart_item['product_id'], '_subzz_subscription_enabled', true);
        if ($subscription_enabled === 'yes') {
            $has_subscription = true;
            break;
        }
    }

    if (!$has_subscription) {
        error_log('SUBZZ CHECKOUT SUBSCRIPTION: No subscription product in cart - redirecting to normal checkout');
        wp_redirect(wc_get_checkout_url());
        exit;
    }

    // Enqueue checkout subscription assets
    add_action('wp_enqueue_scripts', 'subzz_enqueue_checkout_subscription_assets', 1);

    $template_path = plugin_dir_path(__FILE__) . 'templates/checkout-subscription.php';
    if (file_exists($template_path)) {
        include $template_path;
        exit;
    } else {
        error_log('SUBZZ CHECKOUT SUBSCRIPTION ERROR: Template not found');
        wp_die('Checkout page template not found. Please contact support.');
    }
}

// Handle payment update page routing (standalone page, no login required)
add_action('template_redirect', 'subzz_handle_payment_update_page');
function subzz_handle_payment_update_page() {
    global $wp;

    if (!isset($wp->request) || $wp->request !== 'payment-update') {
        return;
    }

    error_log('=== SUBZZ PAYMENT UPDATE: Request received ===');

    // Enqueue payment update assets
    add_action('wp_enqueue_scripts', 'subzz_enqueue_payment_update_assets', 1);

    $template_path = plugin_dir_path(__FILE__) . 'templates/payment-update.php';
    if (file_exists($template_path)) {
        include $template_path;
        exit;
    } else {
        error_log('SUBZZ PAYMENT UPDATE ERROR: Template not found');
        wp_die('Payment update page template not found. Please contact support.');
    }
}

function subzz_enqueue_payment_update_assets() {
    $plugin_url = plugin_dir_url(__FILE__);

    wp_enqueue_style(
        'subzz-customer-portal',
        $plugin_url . 'assets/css/customer-portal.css',
        array(),
        '2.0.0'
    );

    wp_enqueue_script(
        'subzz-payment-update',
        $plugin_url . 'assets/js/payment-update.js',
        array('jquery'),
        '2.0.0',
        true
    );

    wp_localize_script('subzz-payment-update', 'subzzPaymentUpdate', array(
        'apiUrl' => defined('SUBZZ_AZURE_API_URL') ? SUBZZ_AZURE_API_URL : 'http://localhost:5000/api',
    ));
}

// Enqueue portal assets on My Account pages
add_action('wp_enqueue_scripts', 'subzz_enqueue_portal_assets');
function subzz_enqueue_portal_assets() {
    // Only load on My Account pages
    if (!is_account_page()) {
        return;
    }

    $plugin_url = plugin_dir_url(__FILE__);

    wp_enqueue_style(
        'subzz-customer-portal',
        $plugin_url . 'assets/css/customer-portal.css',
        array(),
        '2.0.0'
    );

    wp_enqueue_script(
        'subzz-customer-portal',
        $plugin_url . 'assets/js/customer-portal.js',
        array('jquery'),
        '2.0.0',
        true
    );

    wp_localize_script('subzz-customer-portal', 'subzzPortal', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('subzz_portal_nonce'),
        'paymentUpdateUrl' => home_url('/payment-update/'),
    ));
}

function subzz_enqueue_checkout_subscription_assets() {
    $plugin_url = plugin_dir_url(dirname(__FILE__) . '/../subzz-subscription-payments.php');

    wp_enqueue_style(
        'subzz-checkout-plans',
        $plugin_url . 'assets/css/checkout-plans.css',
        array(),
        '2.0.0'
    );

    wp_enqueue_script(
        'subzz-checkout-plans',
        $plugin_url . 'assets/js/checkout-plans.js',
        array('jquery'),
        '2.0.0',
        true
    );
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
    
    function subzz_enqueue_signature_assets_now() {
        error_log('SUBZZ DEBUG: Enqueuing signature assets NOW (HYBRID: billing-date-handler.js + signature-handler.js)');
        
        $plugin_url = plugin_dir_url(__FILE__);
        
        // Enqueue contract styles
        wp_enqueue_style(
            'subzz-contract-styles',
            $plugin_url . 'assets/contract-styles.css',
            array(),
            '1.4.0'
        );
        error_log('SUBZZ DEBUG: contract-styles.css enqueued');
        
        // Step 1: Billing date selection (HYBRID architecture)
        wp_enqueue_script(
            'subzz-billing-date-handler',
            $plugin_url . 'assets/billing-date-handler.js',
            array('jquery'),
            '1.4.0',
            true
        );
        error_log('SUBZZ DEBUG: billing-date-handler.js enqueued (HYBRID Step 1)');
        
        // Step 2 & 3: Signature handling (depends on billing date completion)
        wp_enqueue_script(
            'subzz-signature-handler',
            $plugin_url . 'assets/signature-handler.js',
            array('jquery', 'subzz-billing-date-handler'), // CRITICAL: Depends on billing handler
            '1.4.0',
            true
        );
        error_log('SUBZZ DEBUG: signature-handler.js enqueued (HYBRID Steps 2 & 3, depends on billing handler)');
        
        // Signature Pad library
        $signature_pad_path = plugin_dir_path(__FILE__) . 'assets/signature-pad.min.js';
        if (file_exists($signature_pad_path)) {
            wp_enqueue_script(
                'signature-pad',
                $plugin_url . 'assets/signature-pad.min.js',
                array(),
                '4.1.7',
                true
            );
            error_log('SUBZZ DEBUG: signature-pad.min.js enqueued from local file');
        } else {
            wp_enqueue_script(
                'signature-pad',
                'https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js',
                array(),
                '4.1.7',
                true
            );
            error_log('SUBZZ DEBUG: signature-pad.min.js enqueued from CDN (local file not found)');
        }
        
        // Localize script with AJAX URL
        wp_localize_script('subzz-signature-handler', 'subzzAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('subzz_signature_nonce')
        ));
        error_log('SUBZZ DEBUG: AJAX configuration localized for signature handler');
    }
}

// NOTE: Contract signature page is handled by Subzz_Contract_Integration class
// See includes/class-contract-integration.php -> handle_contract_signature_page()

// Debug URL handling
add_action('template_redirect', 'subzz_debug_url_handling');
function subzz_debug_url_handling() {
    global $wp;
    
    if (isset($wp->request) && $wp->request === 'contract-signature') {
        error_log('SUBZZ DEBUG: Contract signature URL matched - WordPress routing working');
    }
    
    if (isset($wp->request) && $wp->request === 'subscription-payment') {
        error_log('SUBZZ DEBUG: Subscription payment URL matched - WordPress routing working');
    }
    
    if (isset($wp->request) && $wp->request === 'payment-success') {
        error_log('SUBZZ DEBUG: Payment success URL matched - WordPress routing working');
    }
    
    if (isset($wp->request) && $wp->request === 'payment-cancelled') {
        error_log('SUBZZ DEBUG: Payment cancelled URL matched - WordPress routing working');
    }
    
    if (get_query_var('subzz_contract_page')) {
        error_log('SUBZZ DEBUG: Contract page query var detected - rewrite rules working');
    }
    
    if (get_query_var('subzz_payment_page')) {
        error_log('SUBZZ DEBUG: Payment page query var detected - rewrite rules working');
    }
    
    if (get_query_var('subzz_payment_success')) {
        error_log('SUBZZ DEBUG: Payment success query var detected - rewrite rules working');
    }
    
    if (get_query_var('subzz_payment_cancelled')) {
        error_log('SUBZZ DEBUG: Payment cancelled query var detected - rewrite rules working');
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
    
    if (isset($_POST['test_lekkapay_pages'])) {
        // Check if LekkaPay return page templates exist
        $plugin_path = plugin_dir_path(__FILE__);
        $success_exists = file_exists($plugin_path . 'templates/payment-success.php');
        $cancelled_exists = file_exists($plugin_path . 'templates/payment-cancelled.php');
        
        echo '<div class="notice notice-info"><p>';
        echo '<strong>LekkaPay Return Pages Check:</strong><br>';
        echo 'payment-success.php template: ' . ($success_exists ? '✅ Found' : '❌ Missing') . '<br>';
        echo 'payment-cancelled.php template: ' . ($cancelled_exists ? '✅ Found' : '❌ Missing') . '<br>';
        echo '</p></div>';
    }
    
    ?>
    <div class="wrap">
        <h1>Subzz Subscription Settings</h1>
        
        <h2>LekkaPay Integration Status</h2>
        <div style="background: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0;">
            <p><strong>✅ LekkaPay Integration Complete</strong></p>
            <p>Version 1.5.0 with full payment success/cancel page routing.</p>
            <ul style="margin-left: 20px;">
                <li>✅ Payment session creation via Azure API</li>
                <li>✅ Redirect to LekkaPay hosted checkout</li>
                <li>✅ Success page routing (/payment-success/)</li>
                <li>✅ Cancel page routing (/payment-cancelled/)</li>
                <li>✅ Retry payment functionality</li>
            </ul>
        </div>
        
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
            <input type="submit" name="test_lekkapay_pages" value="Check LekkaPay Return Pages" class="button-primary">
        </form>
        
        <h3>Page URLs (Click to Test)</h3>
        <ul style="margin-left: 20px;">
            <li>Contract signature page: <a href="<?php echo home_url('/contract-signature/'); ?>" target="_blank"><?php echo home_url('/contract-signature/'); ?></a></li>
            <li>Payment page: <a href="<?php echo home_url('/subscription-payment/'); ?>" target="_blank"><?php echo home_url('/subscription-payment/'); ?></a></li>
            <li><strong>Payment success page: <a href="<?php echo home_url('/payment-success/'); ?>" target="_blank"><?php echo home_url('/payment-success/'); ?></a></strong> (NEW)</li>
            <li><strong>Payment cancelled page: <a href="<?php echo home_url('/payment-cancelled/'); ?>" target="_blank"><?php echo home_url('/payment-cancelled/'); ?></a></strong> (NEW)</li>
        </ul>
        <p><small>Note: Signature/payment pages should show appropriate error messages with styling if working correctly. Success/cancel pages should display properly formatted pages.</small></p>
        
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
            <li>✅ LekkaPay Integration: Complete (Payment processing with return URLs)</li>
        </ul>
        
        <?php
        // Show current rewrite rules for debugging
        $rules = get_option('rewrite_rules');
        $signature_rule_active = isset($rules['^contract-signature/?$']);
        $payment_rule_active = isset($rules['^subscription-payment/?$']);
        $success_rule_active = isset($rules['^payment-success/?$']);
        $cancelled_rule_active = isset($rules['^payment-cancelled/?$']);
        
        echo '<h2>Rewrite Rules Status</h2>';
        echo '<ul>';
        
        if ($signature_rule_active) {
            echo '<li style="color: green;">✅ Contract signature URL rule is active</li>';
        } else {
            echo '<li style="color: red;">❌ Contract signature URL rule is missing - click "Fix Page URLs" above</li>';
        }
        
        if ($payment_rule_active) {
            echo '<li style="color: green;">✅ Payment page URL rule is active</li>';
        } else {
            echo '<li style="color: red;">❌ Payment page URL rule is missing - click "Fix Page URLs" above</li>';
        }
        
        if ($success_rule_active) {
            echo '<li style="color: green;">✅ Payment success URL rule is active</li>';
        } else {
            echo '<li style="color: red;">❌ Payment success URL rule is missing - click "Fix Page URLs" above</li>';
        }
        
        if ($cancelled_rule_active) {
            echo '<li style="color: green;">✅ Payment cancelled URL rule is active</li>';
        } else {
            echo '<li style="color: red;">❌ Payment cancelled URL rule is missing - click "Fix Page URLs" above</li>';
        }
        
        echo '</ul>';
        
        // Show plugin directory information
        echo '<h2>Plugin Directory Information</h2>';
        echo '<p><strong>Plugin Directory:</strong> ' . plugin_dir_path(__FILE__) . '</p>';
        echo '<p><strong>Plugin URL:</strong> ' . plugin_dir_url(__FILE__) . '</p>';
        echo '<p><strong>Assets Directory:</strong> ' . plugin_dir_path(__FILE__) . 'assets/</p>';
        echo '<p><strong>Templates Directory:</strong> ' . plugin_dir_path(__FILE__) . 'templates/</p>';
        echo '<p><strong>Version:</strong> 1.5.0 (LekkaPay Integration Complete with HYBRID Architecture)</p>';
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