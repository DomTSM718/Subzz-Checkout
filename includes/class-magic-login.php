<?php
/**
 * Magic-link auto-login — WordPress consume endpoint (Piece 2).
 *
 * Receives the one-time token issued by the Subzz API (AcceptBandCap mints it, the
 * signup app redirects the customer to {shop}/wp-json/subzz/v1/magic-login?token=...),
 * verifies it server-to-server against POST /api/auth/magic-login/redeem, and on success
 * logs the matching WooCommerce customer in and sends them to the shop.
 *
 * Security posture (this is an auth surface — it can log a user in):
 *  - The token is verified by OUR API (single-use, expiring, hashed at rest). WP trusts
 *    only a 200 + {email} response; it never decides validity itself.
 *  - Fail-safe: ANY failure (missing/invalid/expired/used token, no matching WP user,
 *    network/HTTP error) silently redirects to the account page. Never an error screen.
 *  - The raw token value is NEVER logged.
 *  - Same X-Subzz-API-Key convention as every other Subzz API call from this plugin.
 *
 * Verified contract (Subzz.KYC API AuthController.cs):
 *   POST /api/auth/magic-login/redeem  body {"token":"..."}  header X-Subzz-API-Key
 *     200 {"email":"..."}  -> valid, single-use now spent
 *     401 {"error":"..."}  -> invalid / expired / already used
 */

if (!defined('ABSPATH')) {
    exit;
}

class Subzz_Magic_Login {

    /** Redeem-call timeout (seconds). Short, with a safe fallback redirect. */
    const REDEEM_TIMEOUT = 5;

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Register GET /wp-json/subzz/v1/magic-login.
     * Public endpoint (no auth) — it IS the login entry point; trust comes from the
     * one-time token verified by our API, not from a WP session.
     */
    public function register_routes() {
        register_rest_route('subzz/v1', '/magic-login', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle'),
            'permission_callback' => '__return_true',
            'args'                => array(
                'token' => array(
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));
    }

    /**
     * Handle the magic-login redirect.
     * Always ends in a redirect + exit; never returns a REST body to the browser.
     */
    public function handle(WP_REST_Request $request) {
        // Auth/redeem endpoint — its response must NEVER be cached. A page cache (LiteSpeed on
        // staging/prod) would otherwise serve a stale redirect for a token URL, and an email
        // link-scanner pre-fetch could consume the single-use token before the customer clicks.
        nocache_headers();
        do_action('litespeed_control_set_nocache', 'subzz magic-login: auth endpoint, never cache');

        $token = (string) $request->get_param('token');

        // 1. No token -> normal account page (fail safe).
        if ($token === '') {
            $this->redirect($this->account_url());
        }

        // 2. Verify the token with our API (server-to-server, API-key authenticated).
        $endpoint  = $this->api_base() . '/auth/magic-login/redeem';
        $args      = array(
            'timeout' => self::REDEEM_TIMEOUT,
            'headers' => array(
                'Content-Type'    => 'application/json',
                'X-Subzz-API-Key' => defined('SUBZZ_AZURE_API_KEY') ? SUBZZ_AZURE_API_KEY : '',
            ),
            'body'    => wp_json_encode(array('token' => $token)),
        );
        // LocalWP cURL has SSL handshake issues — match the plugin-wide local-dev convention.
        // Staging/prod (no WP_LOCAL_DEV) verify TLS normally — HTTPS only.
        if (defined('WP_LOCAL_DEV') && WP_LOCAL_DEV) {
            $args['sslverify'] = false;
        }

        $response = wp_remote_post($endpoint, $args);

        // 3. Transport failure -> fail safe. (No token in the log.)
        if (is_wp_error($response)) {
            subzz_log('SUBZZ MAGIC-LOGIN: redeem transport error - ' . $response->get_error_message());
            $this->redirect($this->account_url());
        }

        $code  = wp_remote_retrieve_response_code($response);
        $body  = json_decode(wp_remote_retrieve_body($response), true);
        $email = (is_array($body) && !empty($body['email'])) ? $body['email'] : '';

        // 4. Non-200 or no email (invalid / expired / already used) -> fail safe.
        if ($code !== 200 || $email === '') {
            subzz_log('SUBZZ MAGIC-LOGIN: redeem rejected HTTP ' . $code);
            $this->redirect($this->account_url());
        }

        // 5. Find the WooCommerce user for that email.
        $user = get_user_by('email', $email);
        if (!$user) {
            subzz_log('SUBZZ MAGIC-LOGIN: no WP user for redeemed email');
            $this->redirect($this->account_url());
        }

        // 5b. Refuse to auto-login privileged accounts. Magic-login is a customer-only
        //     convenience; an email collision with an admin/shop-manager must never yield
        //     an elevated session. Allow ONLY non-privileged shopper roles — any role
        //     outside the allowlist (or no role at all) fails safe to the account page.
        $allowed_roles = array('customer', 'subscriber');
        $user_roles    = (array) $user->roles;
        if (empty($user_roles) || array_diff($user_roles, $allowed_roles)) {
            subzz_log('SUBZZ MAGIC-LOGIN: refused non-shopper role for user ' . $user->ID);
            $this->redirect($this->account_url());
        }

        // 6. Log them in (persistent cookie) and send them shopping.
        //    Mirror a normal wp_signon(): set current user, set the auth cookie,
        //    THEN fire the wp_login action. Firing wp_login is what makes this a
        //    "real" login as far as other plugins are concerned. In particular
        //    LiteSpeed Cache listens for wp_login to set its per-user "vary"
        //    cookie; without it the customer is authenticated (valid session) but
        //    LiteSpeed keeps serving the cached GUEST page — so the header still
        //    shows logged-out until some later cache-varying request. Normal
        //    password login fires wp_login and renders correctly; this path did
        //    not, which is why the magic-login header looked logged-out.
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);
        do_action('wp_login', $user->user_login, $user);
        subzz_log('SUBZZ MAGIC-LOGIN: success for user ' . $user->ID);

        $this->redirect($this->destination_url());
    }

    /** Base API URL (matches the rest of the plugin; trailing /api included). */
    private function api_base() {
        return subzz_api_base_url(); // H8 (2026-08-26): no localhost fallback
    }

    /** Account / login page — the universal fail-safe destination. */
    private function account_url() {
        if (function_exists('wc_get_page_permalink')) {
            $url = wc_get_page_permalink('myaccount');
            if (!empty($url)) {
                return $url;
            }
        }
        return home_url('/my-account/');
    }

    /** Post-login destination: cart if one is pending, otherwise the shop. */
    private function destination_url() {
        if (function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) {
            return wc_get_cart_url();
        }
        if (function_exists('wc_get_page_permalink')) {
            $url = wc_get_page_permalink('shop');
            if (!empty($url)) {
                return $url;
            }
        }
        return home_url('/shop/');
    }

    /** Internal-only redirect, then stop. */
    private function redirect($url) {
        wp_safe_redirect($url);
        exit;
    }
}
