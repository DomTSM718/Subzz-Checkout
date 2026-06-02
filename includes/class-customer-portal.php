<?php
/**
 * Customer Portal Class — "My Subscription" WooCommerce My Account tab
 *
 * Registers a custom My Account endpoint, fetches subscription data server-side
 * via the Azure API client, and renders the portal template.
 *
 * AJAX handlers return rendered HTML (not raw JSON with personal data).
 * Payment token generation is done server-side and returns a redirect URL.
 *
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Subzz_Customer_Portal {

    private $api_client;

    public function __construct() {
        $this->api_client = new Subzz_Azure_API_Client();

        // Register the My Account endpoint
        add_action('init', array($this, 'register_endpoint'));
        add_filter('woocommerce_account_menu_items', array($this, 'build_menu'), 999);
        add_action('woocommerce_account_my-subscription_endpoint', array($this, 'render_content'));

        // Override the default Dashboard content with our custom dashboard
        add_action('init', array($this, 'override_dashboard'));

        // AJAX handlers (logged-in users only)
        add_action('wp_ajax_subzz_load_invoices', array($this, 'ajax_load_invoices'));
        add_action('wp_ajax_subzz_get_invoice_pdf', array($this, 'ajax_get_invoice_pdf'));
        add_action('wp_ajax_subzz_generate_payment_token', array($this, 'ajax_generate_payment_token'));
        add_action('wp_ajax_subzz_banklink_handoff_portal', array($this, 'ajax_banklink_handoff_portal'));
    }

    /**
     * Register the my-subscription endpoint for WooCommerce My Account.
     */
    public function register_endpoint() {
        add_rewrite_endpoint('my-subscription', EP_ROOT | EP_PAGES);
    }

    /**
     * Build a clean My Account menu — hide unused WooCommerce tabs.
     * Keeps: Dashboard, My Subscription, Account Details, Logout.
     * Hides: Orders, Downloads, Addresses, Subscriptions, Wishlist.
     */
    public function build_menu($items) {
        $clean_items = array();

        // Add Dashboard first
        if (isset($items['dashboard'])) {
            $clean_items['dashboard'] = $items['dashboard'];
        }

        // Add My Subscription
        $clean_items['my-subscription'] = 'My Subscription';

        // Add Account Details
        if (isset($items['edit-account'])) {
            $clean_items['edit-account'] = $items['edit-account'];
        }

        // Add Logout last
        if (isset($items['customer-logout'])) {
            $clean_items['customer-logout'] = $items['customer-logout'];
        }

        return $clean_items;
    }

    /**
     * Replace WooCommerce default dashboard action with ours.
     * Must run after WooCommerce has registered its own actions.
     */
    public function override_dashboard() {
        remove_action('woocommerce_account_dashboard', 'woocommerce_account_dashboard');
        add_action('woocommerce_account_dashboard', array($this, 'render_dashboard'));
    }

    /**
     * Render custom Subzz dashboard instead of WooCommerce default.
     * Shows subscription summary card + featured products.
     * Hooked to woocommerce_account_dashboard (only fires on dashboard page).
     */
    public function render_dashboard() {
        $user = wp_get_current_user();
        $email = $user ? $user->user_email : '';
        $subscriptions = $email ? $this->api_client->get_customer_subscriptions($email) : array();

        // Band→Checkout Phase 3 (Lever 3): the customer's governing spending limit + uplift flag,
        // for the Dashboard panel above "Your Subscriptions". Independent of $subscriptions so a
        // band-approved customer with no purchase yet still gets a panel.
        $spending_limit = $email ? $this->api_client->get_spending_limit($email) : false;

        // Get featured products via WC_Product_Query
        $featured_products = $this->get_featured_products(4);

        // Load the dashboard template
        $template_path = plugin_dir_path(dirname(__FILE__)) . 'templates/dashboard.php';
        if (file_exists($template_path)) {
            include $template_path;
        }
    }

    /**
     * Get featured products using WooCommerce product query.
     * Falls back to latest products if none are marked as featured.
     *
     * @param int $limit Number of products to return
     * @return array Array of product data
     */
    private function get_featured_products($limit = 4) {
        $products = array();

        // Try featured products first
        $args = array(
            'status'   => 'publish',
            'featured' => true,
            'limit'    => $limit,
            'orderby'  => 'date',
            'order'    => 'DESC',
        );

        $query = new \WC_Product_Query($args);
        $results = $query->get_products();

        // Fallback to latest products if no featured
        if (empty($results)) {
            $args['featured'] = false;
            $query = new \WC_Product_Query($args);
            $results = $query->get_products();
        }

        foreach ($results as $product) {
            $products[] = array(
                'id'        => $product->get_id(),
                'name'      => $product->get_name(),
                'price'     => $product->get_price(),
                'image'     => wp_get_attachment_url($product->get_image_id()),
                'permalink' => $product->get_permalink(),
            );
        }

        return $products;
    }

    /**
     * Render the My Subscription tab content.
     * Fetches all data server-side via API client, passes to template.
     */
    public function render_content() {
        $user = wp_get_current_user();
        if (!$user || !$user->user_email) {
            echo '<p>Please log in to view your subscription.</p>';
            return;
        }

        $email = $user->user_email;
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'overview';

        // Fetch ALL subscriptions
        $subscriptions = $this->api_client->get_customer_subscriptions($email);

        // Determine which subscription to show detail for
        $selected_id = isset($_GET['sid']) ? sanitize_text_field($_GET['sid']) : '';
        $subscription = false;

        if (!empty($subscriptions)) {
            if ($selected_id) {
                // Find the selected subscription by ID
                foreach ($subscriptions as $sub) {
                    if ($sub['subscriptionId'] === $selected_id) {
                        $subscription = $sub;
                        break;
                    }
                }
            }
            // Default to the first (most recent) subscription
            if (!$subscription) {
                $subscription = $subscriptions[0];
            }
        }

        $invoices = $this->api_client->get_customer_invoices($email, 1, 10);
        $contract = $this->api_client->get_contract_download_url($email);

        // Load the template
        $template_path = plugin_dir_path(dirname(__FILE__)) . 'templates/my-subscription.php';
        if (file_exists($template_path)) {
            include $template_path;
        } else {
            echo '<p>Subscription portal template not found.</p>';
            subzz_log('SUBZZ PORTAL: Template not found at ' . $template_path);
        }
    }

    /**
     * AJAX: Load invoice page (returns HTML partial).
     */
    public function ajax_load_invoices() {
        check_ajax_referer('subzz_portal_nonce', 'nonce');

        $user = wp_get_current_user();
        if (!$user || !$user->user_email) {
            wp_send_json_error('Not logged in');
        }

        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $invoices = $this->api_client->get_customer_invoices($user->user_email, $page, 10);

        if (!$invoices) {
            wp_send_json_error('Failed to load invoices');
        }

        // Render HTML partial
        ob_start();
        $template_path = plugin_dir_path(dirname(__FILE__)) . 'templates/partials/invoice-table-rows.php';
        if (file_exists($template_path)) {
            include $template_path;
        }
        $html = ob_get_clean();

        wp_send_json_success(array(
            'html' => $html,
            'page' => $invoices['page'],
            'totalPages' => $invoices['totalPages'],
            'totalCount' => $invoices['totalCount']
        ));
    }

    /**
     * AJAX: Get invoice PDF download URL (server-side SAS token generation).
     */
    public function ajax_get_invoice_pdf() {
        check_ajax_referer('subzz_portal_nonce', 'nonce');

        $user = wp_get_current_user();
        if (!$user || !$user->user_email) {
            wp_send_json_error('Not logged in');
        }

        $invoice_id = isset($_POST['invoice_id']) ? sanitize_text_field($_POST['invoice_id']) : '';
        if (empty($invoice_id)) {
            wp_send_json_error('Invoice ID required');
        }

        $result = $this->api_client->get_invoice_pdf_url($invoice_id, $user->user_email);

        if (!$result || empty($result['downloadUrl'])) {
            wp_send_json_error('Failed to generate PDF download URL');
        }

        wp_send_json_success(array('downloadUrl' => $result['downloadUrl']));
    }

    /**
     * AJAX: Generate payment update token and return redirect URL.
     * Token generation is done server-side — client never sees the token directly.
     */
    public function ajax_generate_payment_token() {
        check_ajax_referer('subzz_portal_nonce', 'nonce');

        $user = wp_get_current_user();
        if (!$user || !$user->user_email) {
            wp_send_json_error('Not logged in');
        }

        // Call the existing generate-token-for-user endpoint via Azure API
        $endpoint = defined('SUBZZ_AZURE_API_URL')
            ? SUBZZ_AZURE_API_URL
            : 'http://localhost:5000/api';
        $endpoint .= '/payment-update/generate-token-for-user';

        $response = wp_remote_post($endpoint, array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-Subzz-API-Key' => defined('SUBZZ_AZURE_API_KEY') ? SUBZZ_AZURE_API_KEY : '',
            ),
            'body' => wp_json_encode(array('email' => $user->user_email))
        ));

        if (is_wp_error($response)) {
            subzz_log('SUBZZ PORTAL: Payment token generation failed - ' . $response->get_error_message());
            wp_send_json_error('Failed to generate payment token');
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($response_code !== 200 || !$body || empty($body['token'])) {
            subzz_log('SUBZZ PORTAL: Payment token generation failed HTTP ' . $response_code);
            wp_send_json_error('Failed to generate payment token');
        }

        $payment_update_url = home_url('/payment-update/?token=' . urlencode($body['token']));

        wp_send_json_success(array('redirectUrl' => $payment_update_url));
    }

    /**
     * AJAX: mint a bank-link hand-off code for the logged-in band customer and return a redirect
     * URL into the signup SPA's bank-link flow — the PORTAL "Link bank to unlock more" entry point
     * (Band→Checkout Phase 3 / Lever 3). Mirrors the checkout 2d handler
     * (class-payment-handler.php::ajax_banklink_handoff) but uses the portal nonce, a portal-specific
     * analytics eventType, and returns the customer to their My-Account Dashboard.
     *
     * SECURITY (2a review HARD requirements):
     *  - email is sourced from wp_get_current_user() SERVER-SIDE ONLY — never from the request.
     *  - logged-in only (registered wp_ajax_ without _nopriv); bail defensively.
     *  - return URL is built server-side to the My-Account Dashboard (this site); the SPA also
     *    allowlists shop origins before honouring it.
     *  - nonce-protected (subzz_portal_nonce, same as the other portal AJAX handlers).
     */
    public function ajax_banklink_handoff_portal() {
        check_ajax_referer('subzz_portal_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error('Please log in to continue.');
        }

        // SECURITY: email strictly from the authenticated WC user — never request-supplied.
        $user = wp_get_current_user();
        $email = $user ? $user->user_email : '';
        if (empty($email)) {
            wp_send_json_error('Unable to determine your account email.');
        }

        $handoff = $this->api_client->issue_banklink_handoff($email);

        if (!$handoff || empty($handoff['code'])) {
            subzz_log('SUBZZ PORTAL BANKLINK: mint failed for ' . $email);
            wp_send_json_error('We could not start the bank-linking step right now. Please try again in a moment.');
        }

        // Analytics-First: distinct eventType so portal-lever uptake is separable from checkout-lever (2d).
        $this->api_client->log_payment_event(array(
            'eventType'       => 'banklink_handoff_minted_portal',
            'customerEmail'   => $email,
            'responseMessage' => 'Bank-link handoff code minted for band uplift (portal)',
            'source'          => 'wordpress_portal'
        ));

        // Resolve the signup app origin. Prod sets SUBZZ_SIGNUP_APP_URL in wp-config; the fallback is
        // the STAGING SWA, never prod — a missing constant must not silently route a staging code to prod.
        $signup_origin = (defined('SUBZZ_SIGNUP_APP_URL') && !empty(SUBZZ_SIGNUP_APP_URL))
            ? SUBZZ_SIGNUP_APP_URL
            : 'https://polite-smoke-0f5cf7603.6.azurestaticapps.net';
        if (!defined('SUBZZ_SIGNUP_APP_URL')) {
            subzz_log('SUBZZ PORTAL BANKLINK: SUBZZ_SIGNUP_APP_URL not defined — using staging SWA fallback');
        }

        // Return the customer to their My-Account Dashboard on completion (on this site;
        // origin is on the SPA's shop-origin allowlist).
        $return_url = wc_get_account_endpoint_url('dashboard');

        $redirect_url = trailingslashit($signup_origin) . 'link-bank?handoff=' . rawurlencode($handoff['code'])
            . '&return=' . rawurlencode($return_url);

        subzz_log('SUBZZ PORTAL BANKLINK: minted — redirecting into signup bank-link flow (return=' . $return_url . ')');

        wp_send_json_success(array('redirectUrl' => $redirect_url));
    }
}
