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
        add_filter('woocommerce_account_menu_items', array($this, 'add_menu_item'));
        add_action('woocommerce_account_my-subscription_endpoint', array($this, 'render_content'));

        // AJAX handlers (logged-in users only)
        add_action('wp_ajax_subzz_load_invoices', array($this, 'ajax_load_invoices'));
        add_action('wp_ajax_subzz_get_invoice_pdf', array($this, 'ajax_get_invoice_pdf'));
        add_action('wp_ajax_subzz_generate_payment_token', array($this, 'ajax_generate_payment_token'));
    }

    /**
     * Register the my-subscription endpoint for WooCommerce My Account.
     */
    public function register_endpoint() {
        add_rewrite_endpoint('my-subscription', EP_ROOT | EP_PAGES);
    }

    /**
     * Add "My Subscription" to the My Account menu, after "Orders".
     */
    public function add_menu_item($items) {
        $new_items = array();
        foreach ($items as $key => $label) {
            $new_items[$key] = $label;
            if ($key === 'orders') {
                $new_items['my-subscription'] = 'My Subscription';
            }
        }
        return $new_items;
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

        // Fetch subscription data server-side
        $subscription = $this->api_client->get_customer_subscription($email);
        $invoices = $this->api_client->get_customer_invoices($email, 1, 10);
        $contract = $this->api_client->get_contract_download_url($email);

        // Load the template
        $template_path = plugin_dir_path(dirname(__FILE__)) . 'templates/my-subscription.php';
        if (file_exists($template_path)) {
            include $template_path;
        } else {
            echo '<p>Subscription portal template not found.</p>';
            error_log('SUBZZ PORTAL: Template not found at ' . $template_path);
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
            error_log('SUBZZ PORTAL: Payment token generation failed - ' . $response->get_error_message());
            wp_send_json_error('Failed to generate payment token');
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($response_code !== 200 || !$body || empty($body['token'])) {
            error_log('SUBZZ PORTAL: Payment token generation failed HTTP ' . $response_code);
            wp_send_json_error('Failed to generate payment token');
        }

        $payment_update_url = home_url('/payment-update/?token=' . urlencode($body['token']));

        wp_send_json_success(array('redirectUrl' => $payment_update_url));
    }
}
