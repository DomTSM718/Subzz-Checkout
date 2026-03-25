<?php
/**
 * Azure API Client Class - Enhanced with Billing Day Support
 * Handles communication with local Azure backend with comprehensive request/response tracking
 * 
 * BILLING DATE ENHANCEMENT (Oct 19, 2025): Added billing_day parameter to generate_contract
 * PHASE 5A ENHANCEMENT: Added compatibility for mock payment gateway status updates
 * VARIANT ENHANCEMENT: Modified generate_contract to return full response object
 * ORDER REFERENCE ENHANCEMENT: Now passes orderReferenceId for database order retrieval
 */

if (!defined('ABSPATH')) {
    exit;
}

class Subzz_Azure_API_Client {
    
    private $azure_base_url;
    private $api_timeout;
    
    public function __construct() {
        // Configurable Azure URL — set SUBZZ_AZURE_API_URL in wp-config.php for production
        $this->azure_base_url = defined('SUBZZ_AZURE_API_URL')
            ? SUBZZ_AZURE_API_URL
            : 'http://localhost:5000/api';
        $this->api_timeout = 30;

        subzz_log('=== SUBZZ AZURE CLIENT: Initialized with URL: ' . $this->azure_base_url . ' ===');
    }

    /**
     * Get default headers for Azure API requests.
     * Includes Content-Type and API key authentication (CHK-002 fix).
     * API key is read from SUBZZ_AZURE_API_KEY constant defined in wp-config.php.
     */
    private function get_default_headers() {
        $headers = array(
            'Content-Type' => 'application/json',
        );

        if (defined('SUBZZ_AZURE_API_KEY') && !empty(SUBZZ_AZURE_API_KEY)) {
            $headers['X-Subzz-API-Key'] = SUBZZ_AZURE_API_KEY;
        } else {
            subzz_log('SUBZZ SECURITY WARNING: SUBZZ_AZURE_API_KEY not defined in wp-config.php');
        }

        return $headers;
    }

    /**
     * Get default request args for wp_remote_get/post.
     * Includes timeout, headers, and SSL bypass for LocalWP.
     */
    private function get_default_request_args($extra = array()) {
        $args = array(
            'timeout' => $this->api_timeout,
            'headers' => $this->get_default_headers(),
        );
        // LocalWP cURL has SSL handshake issues — disable verification for local dev
        if (defined('WP_LOCAL_DEV') && WP_LOCAL_DEV) {
            $args['sslverify'] = false;
        }
        return array_merge($args, $extra);
    }
    
    /**
     * Test Azure backend connection - ENHANCED CONNECTION LOGGING
     */
    public function test_connection() {
        subzz_log('SUBZZ AZURE TEST: Testing backend connection to ' . $this->azure_base_url);
        
        $test_endpoint = $this->azure_base_url . '/contract/generate';
        
        // Test with minimal request to check if server is running
        $response = wp_remote_post($test_endpoint, $this->get_default_request_args(array(
            'timeout' => 5,
            'body' => wp_json_encode(array()) // Empty body to test connection only
        )));
        
        if (is_wp_error($response)) {
            subzz_log('SUBZZ AZURE ERROR: Connection failed - ' . $response->get_error_message());
            subzz_log('SUBZZ AZURE ERROR: Is your Azure backend running on localhost:5000?');
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_message = wp_remote_retrieve_response_message($response);
        
        subzz_log('SUBZZ AZURE TEST: Response code: ' . $response_code . ' (' . $response_message . ')');
        
        // Any server response (200, 400, 500) means backend is running
        $connection_ok = in_array($response_code, [200, 400, 500]);
        
        if ($connection_ok) {
            subzz_log('SUBZZ AZURE SUCCESS: Backend connection confirmed - server is running');
        } else {
            subzz_log('SUBZZ AZURE ERROR: Unexpected response code - backend may not be running properly');
        }
        
        return $connection_ok;
    }
    
    /**
     * Get customer affordability data.
     * Returns maxAffordableSubscription, isVerified, etc.
     *
     * @param string $email Customer email
     * @return array|false Affordability data or false on failure
     */
    public function get_affordability($email) {
        subzz_log('=== SUBZZ AZURE API: GET AFFORDABILITY REQUEST ===');

        $endpoint = $this->azure_base_url . '/customer/lead/affordability?' . http_build_query(array('email' => $email));
        subzz_log('SUBZZ AZURE REQUEST: GET ' . $endpoint);

        $response = wp_remote_get($endpoint, $this->get_default_request_args());

        if (is_wp_error($response)) {
            subzz_log('SUBZZ AZURE ERROR: Affordability request failed - ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        subzz_log('SUBZZ AZURE RESPONSE: HTTP ' . $response_code);

        if ($response_code === 200) {
            $data = json_decode($response_body, true);
            if ($data === null) {
                subzz_log('SUBZZ AZURE ERROR: Affordability JSON decode failed');
                return false;
            }
            subzz_log('SUBZZ AZURE SUCCESS: Affordability data retrieved');
            return $data['data'] ?? $data;
        }

        subzz_log('SUBZZ AZURE ERROR: Affordability failed with HTTP ' . $response_code . ' - ' . $response_body);
        return false;
    }

    /**
     * Get plan cards for a product price and customer.
     * Returns array of plan card options (12/18/24 month).
     *
     * @param string $email Customer email
     * @param float $price Product price incl VAT
     * @return array|false Plan cards data or false on failure
     */
    public function get_plan_cards($email, $price) {
        subzz_log('=== SUBZZ AZURE API: GET PLAN CARDS REQUEST ===');

        $endpoint = $this->azure_base_url . '/customer/lead/affordability/plan-cards?' . http_build_query(array(
            'email' => $email,
            'productPriceInclVat' => $price
        ));
        subzz_log('SUBZZ AZURE REQUEST: GET ' . $endpoint);

        $response = wp_remote_get($endpoint, $this->get_default_request_args());

        if (is_wp_error($response)) {
            subzz_log('SUBZZ AZURE ERROR: Plan cards request failed - ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        subzz_log('SUBZZ AZURE RESPONSE: HTTP ' . $response_code);

        if ($response_code === 200) {
            $data = json_decode($response_body, true);
            if ($data === null) {
                subzz_log('SUBZZ AZURE ERROR: Plan cards JSON decode failed');
                return false;
            }
            $inner = $data['data'] ?? $data;
            subzz_log('SUBZZ AZURE SUCCESS: Plan cards retrieved - ' . count($inner['planCards']['cards'] ?? []) . ' cards');
            return $inner;
        }

        subzz_log('SUBZZ AZURE ERROR: Plan cards failed with HTTP ' . $response_code . ' - ' . $response_body);
        return false;
    }

    /**
     * Store order data - COMPREHENSIVE REQUEST/RESPONSE LOGGING
     */
    public function store_order_data($order_data) {
        subzz_log('=== SUBZZ AZURE API: STORE ORDER DATA REQUEST ===');
        
        // Log request details
        $endpoint = $this->azure_base_url . '/order/store';
        subzz_log('SUBZZ AZURE REQUEST: POST ' . $endpoint);
        subzz_log('SUBZZ AZURE REQUEST: Timeout: ' . $this->api_timeout . ' seconds');
        
        // Log order data structure being sent
        subzz_log('SUBZZ AZURE REQUEST DATA: Order structure keys: ' . implode(', ', array_keys($order_data)));
        subzz_log('SUBZZ AZURE REQUEST DATA: WooCommerce Order ID: ' . ($order_data['woocommerce_order_id'] ?? 'missing'));
        subzz_log('SUBZZ AZURE REQUEST DATA: Customer email: ' . ($order_data['customer_email'] ?? 'missing'));
        subzz_log('SUBZZ AZURE REQUEST DATA: Customer name: ' . ($order_data['customer_data']['first_name'] ?? '') . ' ' . ($order_data['customer_data']['last_name'] ?? ''));
        subzz_log('SUBZZ AZURE REQUEST DATA: Order total: ' . ($order_data['order_totals']['currency'] ?? '') . ' ' . ($order_data['order_totals']['total'] ?? ''));
        subzz_log('SUBZZ AZURE REQUEST DATA: Items count: ' . (isset($order_data['order_items']) ? count($order_data['order_items']) : 0));
        
        // Count subscription vs regular items
        $subscription_items = 0;
        if (isset($order_data['order_items']) && is_array($order_data['order_items'])) {
            foreach ($order_data['order_items'] as $item) {
                if (isset($item['is_subscription']) && $item['is_subscription']) {
                    $subscription_items++;
                    subzz_log('SUBZZ AZURE REQUEST DATA: Subscription item - ' . $item['name'] . ' (ID: ' . $item['product_id'] . ')');
                }
            }
        }
        subzz_log('SUBZZ AZURE REQUEST DATA: Subscription items: ' . $subscription_items);
        
        // Log complete payload size
        $json_payload = wp_json_encode($order_data);
        subzz_log('SUBZZ AZURE REQUEST DATA: JSON payload size: ' . strlen($json_payload) . ' bytes');
        
        // Make API request
        $start_time = microtime(true);
        subzz_log('SUBZZ AZURE API: Sending request to Azure backend...');
        
        $response = wp_remote_post($endpoint, $this->get_default_request_args(array(
            'body' => $json_payload
        )));

        $end_time = microtime(true);
        $request_duration = round(($end_time - $start_time) * 1000, 2); // Convert to milliseconds

        subzz_log('SUBZZ AZURE API: Request completed in ' . $request_duration . 'ms');

        // Handle request errors
        if (is_wp_error($response)) {
            subzz_log('SUBZZ AZURE ERROR: HTTP request failed');
            subzz_log('SUBZZ AZURE ERROR: Error code: ' . $response->get_error_code());
            subzz_log('SUBZZ AZURE ERROR: Error message: ' . $response->get_error_message());
            subzz_log('SUBZZ AZURE ERROR: Is Azure backend running on localhost:5000?');
            return false;
        }
        
        // Log response details
        $response_code = wp_remote_retrieve_response_code($response);
        $response_message = wp_remote_retrieve_response_message($response);
        $response_body = wp_remote_retrieve_body($response);
        
        subzz_log('SUBZZ AZURE RESPONSE: HTTP ' . $response_code . ' (' . $response_message . ')');
        subzz_log('SUBZZ AZURE RESPONSE: Body length: ' . strlen($response_body) . ' bytes');
        subzz_log('SUBZZ AZURE RESPONSE: Body content: ' . $response_body);
        
        // Process successful response
        if ($response_code === 200) {
            $response_data = json_decode($response_body, true);
            
            if ($response_data === null) {
                subzz_log('SUBZZ AZURE ERROR: Response JSON decode failed - ' . json_last_error_msg());
                return false;
            }
            
            subzz_log('SUBZZ AZURE RESPONSE: Decoded data keys: ' . implode(', ', array_keys($response_data)));
            
            $reference_id = $response_data['referenceId'] ?? false;
            
            if ($reference_id) {
                subzz_log('SUBZZ AZURE SUCCESS: Order stored successfully');
                subzz_log('SUBZZ AZURE SUCCESS: Reference ID: ' . $reference_id);
                subzz_log('SUBZZ AZURE SUCCESS: Total processing time: ' . $request_duration . 'ms');
                return $reference_id;
            } else {
                subzz_log('SUBZZ AZURE ERROR: Response missing referenceId field');
                return false;
            }
        } else {
            subzz_log('SUBZZ AZURE ERROR: Store order failed with HTTP ' . $response_code);
            subzz_log('SUBZZ AZURE ERROR: Response body: ' . $response_body);
            return false;
        }
    }
    
    /**
     * Retrieve order data - COMPREHENSIVE REQUEST/RESPONSE LOGGING
     */
    public function retrieve_order_data($reference_id) {
        subzz_log('=== SUBZZ AZURE API: RETRIEVE ORDER DATA REQUEST ===');
        
        // Log request details
        $endpoint = $this->azure_base_url . '/order/' . urlencode($reference_id);
        subzz_log('SUBZZ AZURE REQUEST: GET ' . $endpoint);
        subzz_log('SUBZZ AZURE REQUEST: Reference ID: ' . $reference_id);
        
        // Make API request
        $start_time = microtime(true);
        subzz_log('SUBZZ AZURE API: Retrieving order data from Azure backend...');
        
        $response = wp_remote_get($endpoint, $this->get_default_request_args());

        $end_time = microtime(true);
        $request_duration = round(($end_time - $start_time) * 1000, 2);

        subzz_log('SUBZZ AZURE API: Request completed in ' . $request_duration . 'ms');

        // Handle request errors
        if (is_wp_error($response)) {
            subzz_log('SUBZZ AZURE ERROR: HTTP request failed');
            subzz_log('SUBZZ AZURE ERROR: Error message: ' . $response->get_error_message());
            return false;
        }
        
        // Log response details
        $response_code = wp_remote_retrieve_response_code($response);
        $response_message = wp_remote_retrieve_response_message($response);
        $response_body = wp_remote_retrieve_body($response);
        
        subzz_log('SUBZZ AZURE RESPONSE: HTTP ' . $response_code . ' (' . $response_message . ')');
        subzz_log('SUBZZ AZURE RESPONSE: Body length: ' . strlen($response_body) . ' bytes');
        
        // Process successful response
        if ($response_code === 200) {
            $order_data = json_decode($response_body, true);
            
            if ($order_data === null) {
                subzz_log('SUBZZ AZURE ERROR: Response JSON decode failed - ' . json_last_error_msg());
                return false;
            }
            
            // Log retrieved order data structure
            subzz_log('SUBZZ AZURE RESPONSE: Order data keys: ' . implode(', ', array_keys($order_data)));
            subzz_log('SUBZZ AZURE RESPONSE: WooCommerce Order ID: ' . ($order_data['woocommerce_order_id'] ?? 'missing'));
            subzz_log('SUBZZ AZURE RESPONSE: Customer email: ' . ($order_data['customer_email'] ?? 'missing'));
            subzz_log('SUBZZ AZURE RESPONSE: Customer name: ' . ($order_data['customer_data']['first_name'] ?? '') . ' ' . ($order_data['customer_data']['last_name'] ?? ''));
            subzz_log('SUBZZ AZURE RESPONSE: Order total: ' . ($order_data['order_totals']['currency'] ?? '') . ' ' . ($order_data['order_totals']['total'] ?? ''));
            
            // Log subscription items in response
            if (isset($order_data['order_items']) && is_array($order_data['order_items'])) {
                $subscription_count = 0;
                foreach ($order_data['order_items'] as $item) {
                    if (isset($item['is_subscription']) && $item['is_subscription']) {
                        $subscription_count++;
                        subzz_log('SUBZZ AZURE RESPONSE: Subscription item - ' . $item['name'] . ' (Price: ' . $item['price'] . ')');
                    }
                }
                subzz_log('SUBZZ AZURE RESPONSE: Total subscription items: ' . $subscription_count);
            }
            
            subzz_log('SUBZZ AZURE SUCCESS: Order data retrieved successfully');
            subzz_log('SUBZZ AZURE SUCCESS: Total processing time: ' . $request_duration . 'ms');
            return $order_data;
        } else {
            subzz_log('SUBZZ AZURE ERROR: Retrieve order failed with HTTP ' . $response_code);
            subzz_log('SUBZZ AZURE ERROR: Response body: ' . $response_body);
            return false;
        }
    }
    
    /**
     * Generate contract - ENHANCED WITH BILLING DAY SUPPORT
     * 
     * BILLING DATE ENHANCEMENT (Oct 19, 2025): Added $billing_day parameter
     * VARIANT ENHANCEMENT: Returns complete response object including contractHtml and variant_info
     * ORDER REFERENCE ENHANCEMENT: Accepts reference_id to enable backend database retrieval
     * 
     * @param string $customer_email Customer email address
     * @param array $order_data Order data array
     * @param string|null $reference_id Order reference ID for database retrieval
     * @param int|null $billing_day Billing day of month (1, 8, 15, or 22)
     * @return array|false Full response array with contractHtml and billing info, or false on failure
     */
    public function generate_contract($customer_email, $order_data, $reference_id = null, $billing_day = null) {
        subzz_log('=== SUBZZ AZURE API: GENERATE CONTRACT REQUEST ===');
        
        // Log request details
        $endpoint = $this->azure_base_url . '/contract/generate';
        subzz_log('SUBZZ AZURE REQUEST: POST ' . $endpoint);
        subzz_log('SUBZZ AZURE REQUEST: Customer email: ' . $customer_email);
        subzz_log('SUBZZ AZURE REQUEST: Order data keys: ' . implode(', ', array_keys($order_data)));
        
        // BILLING DATE ENHANCEMENT: Log billing day if provided
        if ($billing_day !== null) {
            subzz_log('SUBZZ AZURE REQUEST: Billing day provided: ' . $billing_day);
            
            // Validate billing day
            if (!in_array($billing_day, [1, 8, 15, 22])) {
                subzz_log('SUBZZ AZURE ERROR: Invalid billing day: ' . $billing_day . ' (must be 1, 8, 15, or 22)');
                return false;
            }
        }
        
        // Log if reference ID is being used
        if (!empty($reference_id)) {
            subzz_log('SUBZZ AZURE REQUEST: Order Reference ID provided: ' . $reference_id);
            subzz_log('SUBZZ AZURE REQUEST: Backend will retrieve order data from database');
        }
        
        // Log order data that will be used in contract
        if (isset($order_data['customer_data'])) {
            $customer_name = $order_data['customer_data']['first_name'] . ' ' . $order_data['customer_data']['last_name'];
            subzz_log('SUBZZ AZURE REQUEST: Contract for customer: ' . $customer_name);
        }
        
        if (isset($order_data['order_items'])) {
            $subscription_items = array_filter($order_data['order_items'], function($item) { 
                return isset($item['is_subscription']) && $item['is_subscription']; 
            });
            subzz_log('SUBZZ AZURE REQUEST: Subscription items for contract: ' . count($subscription_items));
        }
        
        // Extract billing address fields from order data if available
        $billing_address = null;
        $city = null;
        $province = null;
        $postal_code = null;
        
        // Try to find billing address in the order data structure
        if (isset($order_data['billing_address'])) {
            $billing = $order_data['billing_address'];
        } elseif (isset($order_data['customer_data']['billing_address'])) {
            $billing = $order_data['customer_data']['billing_address'];
        } else {
            $billing = null;
        }
        
        if ($billing) {
            // Extract individual address components
            $billing_address = isset($billing['address_1']) ? $billing['address_1'] : '';
            if (!empty($billing['address_2'])) {
                $billing_address .= ', ' . $billing['address_2'];
            }
            $city = isset($billing['city']) ? $billing['city'] : '';
            $province = isset($billing['state']) ? $billing['state'] : '';
            $postal_code = isset($billing['postcode']) ? $billing['postcode'] : '';
            
            subzz_log('SUBZZ AZURE REQUEST: Billing address found - Street: ' . $billing_address);
            subzz_log('SUBZZ AZURE REQUEST: City: ' . $city . ', Province: ' . $province . ', Postal: ' . $postal_code);
        } else {
            subzz_log('SUBZZ AZURE REQUEST: No billing address found in order data');
        }
        
        // Prepare request payload
        $payload = array(
            'customerEmail' => $customer_email,
            'orderData' => $order_data
        );
        
        // Add reference ID if available (for backend to retrieve stored order data)
        if (!empty($reference_id)) {
            $payload['orderReferenceId'] = $reference_id;
            subzz_log('SUBZZ AZURE REQUEST: Including orderReferenceId for database retrieval: ' . $reference_id);
        }
        
        // BILLING DATE ENHANCEMENT: Add billing day to payload
        if ($billing_day !== null) {
            $payload['billing_day_of_month'] = $billing_day;
            subzz_log('SUBZZ AZURE REQUEST: Including billing_day_of_month in payload: ' . $billing_day);
        }
        
        // Add individual address fields if found
        if ($billing_address) {
            $payload['billingAddress'] = $billing_address;
            $payload['city'] = $city;
            $payload['province'] = $province;
            $payload['postalCode'] = $postal_code;
            subzz_log('SUBZZ AZURE REQUEST: Including separate address fields in payload');
        }
        
        $json_payload = wp_json_encode($payload);
        subzz_log('SUBZZ AZURE REQUEST: Payload size: ' . strlen($json_payload) . ' bytes');
        subzz_log('SUBZZ AZURE REQUEST: Payload keys being sent: ' . implode(', ', array_keys($payload)));
        
        // Make API request
        $start_time = microtime(true);
        subzz_log('SUBZZ AZURE API: Generating contract via Azure backend...');

        $response = wp_remote_post($endpoint, $this->get_default_request_args(array(
            'body' => $json_payload
        )));
        
        $end_time = microtime(true);
        $request_duration = round(($end_time - $start_time) * 1000, 2);
        
        subzz_log('SUBZZ AZURE API: Request completed in ' . $request_duration . 'ms');
        
        // Handle request errors
        if (is_wp_error($response)) {
            subzz_log('SUBZZ AZURE ERROR: HTTP request failed');
            subzz_log('SUBZZ AZURE ERROR: Error message: ' . $response->get_error_message());
            return false;
        }
        
        // Log response details
        $response_code = wp_remote_retrieve_response_code($response);
        $response_message = wp_remote_retrieve_response_message($response);
        $response_body = wp_remote_retrieve_body($response);
        
        subzz_log('SUBZZ AZURE RESPONSE: HTTP ' . $response_code . ' (' . $response_message . ')');
        subzz_log('SUBZZ AZURE RESPONSE: Body length: ' . strlen($response_body) . ' bytes');
        
        // Process successful response
        if ($response_code === 200) {
            $response_data = json_decode($response_body, true);
            
            if ($response_data === null) {
                subzz_log('SUBZZ AZURE ERROR: Response JSON decode failed - ' . json_last_error_msg());
                return false;
            }
            
            subzz_log('SUBZZ AZURE RESPONSE: Decoded data keys: ' . implode(', ', array_keys($response_data)));
            
            // Return full response object
            if (isset($response_data['contractHtml'])) {
                subzz_log('SUBZZ AZURE SUCCESS: Contract generated successfully');
                subzz_log('SUBZZ AZURE SUCCESS: Contract HTML length: ' . strlen($response_data['contractHtml']) . ' characters');
                
                // BILLING DATE ENHANCEMENT: Log billing date information from response
                if (isset($response_data['billing_day_of_month'])) {
                    subzz_log('SUBZZ AZURE SUCCESS: Billing day of month: ' . $response_data['billing_day_of_month']);
                }
                if (isset($response_data['billing_day_formatted'])) {
                    subzz_log('SUBZZ AZURE SUCCESS: Billing day formatted: ' . $response_data['billing_day_formatted']);
                }
                if (isset($response_data['next_billing_date'])) {
                    subzz_log('SUBZZ AZURE SUCCESS: Next billing date: ' . $response_data['next_billing_date']);
                }
                if (isset($response_data['days_of_coverage'])) {
                    subzz_log('SUBZZ AZURE SUCCESS: Days of coverage: ' . $response_data['days_of_coverage']);
                }
                
                // Log variant info if present
                if (isset($response_data['variant_info'])) {
                    subzz_log('SUBZZ AZURE SUCCESS: Variant info received');
                    subzz_log('SUBZZ AZURE SUCCESS: Variant duration: ' . ($response_data['variant_info']['subscription_duration_months'] ?? 'not set'));
                    subzz_log('SUBZZ AZURE SUCCESS: Monthly amount: ' . ($response_data['variant_info']['monthly_amount'] ?? 'not set'));
                    subzz_log('SUBZZ AZURE SUCCESS: Total contract value: ' . ($response_data['variant_info']['total_contract_value'] ?? 'not set'));
                }
                
                // Log other response fields
                if (isset($response_data['agreementReference'])) {
                    subzz_log('SUBZZ AZURE SUCCESS: Agreement reference: ' . $response_data['agreementReference']);
                }
                
                subzz_log('SUBZZ AZURE SUCCESS: Total processing time: ' . $request_duration . 'ms');
                
                // RETURN FULL RESPONSE OBJECT
                return $response_data;
            } else {
                subzz_log('SUBZZ AZURE ERROR: Response missing contractHtml field');
                return false;
            }
        } else {
            subzz_log('SUBZZ AZURE ERROR: Generate contract failed with HTTP ' . $response_code);
            subzz_log('SUBZZ AZURE ERROR: Response body: ' . $response_body);
            return false;
        }
    }
    
    /**
     * Store signature - ENHANCED FOR BILLING DAY SUPPORT
     */
    public function store_signature($customer_email, $signature_data, $order_reference_id, $additional_data = array()) {
        subzz_log('=== SUBZZ AZURE API: STORE SIGNATURE REQUEST ===');
        
        // Log request details
        $endpoint = $this->azure_base_url . '/contract/sign';
        subzz_log('SUBZZ AZURE REQUEST: POST ' . $endpoint);
        subzz_log('SUBZZ AZURE REQUEST: Customer email: ' . $customer_email);
        subzz_log('SUBZZ AZURE REQUEST: Order reference ID: ' . $order_reference_id);
        subzz_log('SUBZZ AZURE REQUEST: Signature data length: ' . strlen($signature_data) . ' characters');
        
        // Log additional data if provided
        if (!empty($additional_data)) {
            subzz_log('SUBZZ AZURE REQUEST: Additional data keys: ' . implode(', ', array_keys($additional_data)));
            
            // BILLING DATE ENHANCEMENT: Log billing day if present
            if (isset($additional_data['billing_day_of_month'])) {
                subzz_log('SUBZZ AZURE REQUEST: Billing day of month: ' . $additional_data['billing_day_of_month']);
            }
        }
        
        // Prepare metadata
        $metadata = array(
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'timestamp' => current_time('mysql'),
            'wordpress_site' => home_url(),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        );
        
        subzz_log('SUBZZ AZURE REQUEST: Client IP: ' . $metadata['ip_address']);
        subzz_log('SUBZZ AZURE REQUEST: User agent: ' . substr($metadata['user_agent'], 0, 100) . '...');
        subzz_log('SUBZZ AZURE REQUEST: Timestamp: ' . $metadata['timestamp']);
        
        // Prepare request payload
        $payload = array(
            'customerEmail' => $customer_email,
            'signatureDataBase64' => $signature_data,
            'orderReferenceId' => $order_reference_id,
            'metadata' => $metadata
        );
        
        // Add additional data to payload (includes billing_day_of_month and legal compliance fields)
        if (!empty($additional_data)) {
            $payload = array_merge($payload, $additional_data);
        }
        
        $json_payload = wp_json_encode($payload);
        subzz_log('SUBZZ AZURE REQUEST: Payload size: ' . strlen($json_payload) . ' bytes');
        
        // Make API request
        $start_time = microtime(true);
        subzz_log('SUBZZ AZURE API: Storing signature via Azure backend...');

        $response = wp_remote_post($endpoint, $this->get_default_request_args(array(
            'body' => $json_payload
        )));
        
        $end_time = microtime(true);
        $request_duration = round(($end_time - $start_time) * 1000, 2);
        
        subzz_log('SUBZZ AZURE API: Request completed in ' . $request_duration . 'ms');
        
        // Handle request errors
        if (is_wp_error($response)) {
            subzz_log('SUBZZ AZURE ERROR: HTTP request failed');
            subzz_log('SUBZZ AZURE ERROR: Error message: ' . $response->get_error_message());
            return false;
        }
        
        // Log response details
        $response_code = wp_remote_retrieve_response_code($response);
        $response_message = wp_remote_retrieve_response_message($response);
        $response_body = wp_remote_retrieve_body($response);
        
        subzz_log('SUBZZ AZURE RESPONSE: HTTP ' . $response_code . ' (' . $response_message . ')');
        subzz_log('SUBZZ AZURE RESPONSE: Body length: ' . strlen($response_body) . ' bytes');
        subzz_log('SUBZZ AZURE RESPONSE: Body content: ' . $response_body);
        
        // Process successful response
        if ($response_code === 200) {
            $response_data = json_decode($response_body, true);
            
            if ($response_data === null) {
                subzz_log('SUBZZ AZURE ERROR: Response JSON decode failed - ' . json_last_error_msg());
                return false;
            }
            
            subzz_log('SUBZZ AZURE RESPONSE: Decoded data keys: ' . implode(', ', array_keys($response_data)));
            
            $signature_id = $response_data['signatureId'] ?? true;
            
            subzz_log('SUBZZ AZURE SUCCESS: Signature stored successfully');
            if ($signature_id && $signature_id !== true) {
                subzz_log('SUBZZ AZURE SUCCESS: Signature ID: ' . $signature_id);
            }
            subzz_log('SUBZZ AZURE SUCCESS: Total processing time: ' . $request_duration . 'ms');
            
            return $signature_id;
        } else {
            subzz_log('SUBZZ AZURE ERROR: Store signature failed with HTTP ' . $response_code);
            subzz_log('SUBZZ AZURE ERROR: Response body: ' . $response_body);
            return false;
        }
    }
    
    /**
     * Consume order data - ENHANCED LOGGING
     */
    public function consume_order_data($reference_id) {
        subzz_log('=== SUBZZ AZURE API: CONSUME ORDER DATA REQUEST ===');
        
        // Log request details
        $endpoint = $this->azure_base_url . '/order/' . urlencode($reference_id) . '/consume';
        subzz_log('SUBZZ AZURE REQUEST: POST ' . $endpoint);
        subzz_log('SUBZZ AZURE REQUEST: Reference ID: ' . $reference_id);
        
        // Make API request
        $start_time = microtime(true);
        subzz_log('SUBZZ AZURE API: Consuming order data via Azure backend...');

        $response = wp_remote_post($endpoint, $this->get_default_request_args());
        
        $end_time = microtime(true);
        $request_duration = round(($end_time - $start_time) * 1000, 2);
        
        subzz_log('SUBZZ AZURE API: Request completed in ' . $request_duration . 'ms');
        
        // Handle request errors
        if (is_wp_error($response)) {
            subzz_log('SUBZZ AZURE ERROR: HTTP request failed');
            subzz_log('SUBZZ AZURE ERROR: Error message: ' . $response->get_error_message());
            return false;
        }
        
        // Log response details
        $response_code = wp_remote_retrieve_response_code($response);
        $response_message = wp_remote_retrieve_response_message($response);
        $response_body = wp_remote_retrieve_body($response);
        
        subzz_log('SUBZZ AZURE RESPONSE: HTTP ' . $response_code . ' (' . $response_message . ')');
        subzz_log('SUBZZ AZURE RESPONSE: Body content: ' . $response_body);
        
        $success = ($response_code === 200);
        
        if ($success) {
            subzz_log('SUBZZ AZURE SUCCESS: Order data consumed successfully');
            subzz_log('SUBZZ AZURE SUCCESS: Total processing time: ' . $request_duration . 'ms');
        } else {
            subzz_log('SUBZZ AZURE ERROR: Consume order failed with HTTP ' . $response_code);
        }
        
        return $success;
    }
    
    // ==========================================
    // CUSTOMER PORTAL METHODS (v2.0.0)
    // ==========================================

    /**
     * Get customer subscription overview for the portal.
     *
     * @param string $email Customer email
     * @return array|false Subscription data or false on failure
     */
    public function get_customer_subscription($email) {
        $endpoint = $this->azure_base_url . '/portal/subscription?' . http_build_query(array('email' => $email));

        $response = wp_remote_get($endpoint, $this->get_default_request_args());

        if (is_wp_error($response)) {
            subzz_log('SUBZZ PORTAL: Subscription request failed - ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code === 200) {
            $data = json_decode($response_body, true);
            return ($data && isset($data['success']) && $data['success']) ? $data['data'] : false;
        }

        if ($response_code === 404) {
            return false; // No subscription found — not an error
        }

        subzz_log('SUBZZ PORTAL: Subscription failed HTTP ' . $response_code . ' - ' . $response_body);
        return false;
    }

    /**
     * Get ALL subscriptions for a customer (active, suspended, completed).
     * Returns array of subscription overviews, or empty array if none found.
     *
     * @param string $email Customer email
     * @return array Array of subscription data (may be empty)
     */
    public function get_customer_subscriptions($email) {
        $endpoint = $this->azure_base_url . '/portal/subscriptions?' . http_build_query(array('email' => $email));

        $response = wp_remote_get($endpoint, $this->get_default_request_args());

        if (is_wp_error($response)) {
            subzz_log('SUBZZ PORTAL: Subscriptions request failed - ' . $response->get_error_message());
            return array();
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code === 200) {
            $data = json_decode($response_body, true);
            return ($data && isset($data['success']) && $data['success']) ? $data['data'] : array();
        }

        subzz_log('SUBZZ PORTAL: Subscriptions failed HTTP ' . $response_code);
        return array();
    }

    /**
     * Log a payment event to Azure API for tracking/debugging.
     * Fire-and-forget — failures are logged but don't block the page.
     *
     * @param array $data Event data (eventType, responseCode, responseMessage, etc.)
     */
    public function log_payment_event($data) {
        $endpoint = $this->azure_base_url . '/payment/log-event';

        $response = wp_remote_post($endpoint, $this->get_default_request_args(array(
            'timeout' => 5,
            'body'    => wp_json_encode($data)
        )));

        if (is_wp_error($response)) {
            subzz_log('SUBZZ PAYMENT LOG: Failed to log event - ' . $response->get_error_message());
            return;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            subzz_log('SUBZZ PAYMENT LOG: Log event failed HTTP ' . $response_code);
        }
    }

    /**
     * Get paginated invoice list for a customer.
     *
     * @param string $email Customer email
     * @param int $page Page number (default 1)
     * @param int $page_size Items per page (default 10)
     * @return array|false Invoice list data or false on failure
     */
    public function get_customer_invoices($email, $page = 1, $page_size = 10) {
        $endpoint = $this->azure_base_url . '/portal/invoices?' . http_build_query(array(
            'email' => $email,
            'page' => $page,
            'pageSize' => $page_size
        ));

        $response = wp_remote_get($endpoint, $this->get_default_request_args());

        if (is_wp_error($response)) {
            subzz_log('SUBZZ PORTAL: Invoices request failed - ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code === 200) {
            $data = json_decode($response_body, true);
            return ($data && isset($data['success']) && $data['success']) ? $data['data'] : false;
        }

        subzz_log('SUBZZ PORTAL: Invoices failed HTTP ' . $response_code);
        return false;
    }

    /**
     * Get invoice PDF download URL (SAS token).
     *
     * @param string $invoice_id Invoice GUID
     * @param string $email Customer email (for ownership validation)
     * @return array|false Download data with URL or false on failure
     */
    public function get_invoice_pdf_url($invoice_id, $email) {
        $endpoint = $this->azure_base_url . '/portal/invoices/' . urlencode($invoice_id) . '/pdf?'
            . http_build_query(array('email' => $email));

        $response = wp_remote_get($endpoint, $this->get_default_request_args());

        if (is_wp_error($response)) {
            subzz_log('SUBZZ PORTAL: Invoice PDF request failed - ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code === 200) {
            $data = json_decode($response_body, true);
            return ($data && isset($data['success']) && $data['success']) ? $data['data'] : false;
        }

        subzz_log('SUBZZ PORTAL: Invoice PDF failed HTTP ' . $response_code);
        return false;
    }

    /**
     * Get contract PDF download URL (SAS token).
     *
     * @param string $email Customer email
     * @return array|false Download data with URL or false on failure
     */
    public function get_contract_download_url($email) {
        $endpoint = $this->azure_base_url . '/portal/contract?' . http_build_query(array('email' => $email));

        $response = wp_remote_get($endpoint, $this->get_default_request_args());

        if (is_wp_error($response)) {
            subzz_log('SUBZZ PORTAL: Contract download request failed - ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code === 200) {
            $data = json_decode($response_body, true);
            return ($data && isset($data['success']) && $data['success']) ? $data['data'] : false;
        }

        if ($response_code === 404) {
            return false; // No contract found
        }

        subzz_log('SUBZZ PORTAL: Contract download failed HTTP ' . $response_code);
        return false;
    }

    /**
     * Update order status - FIXED for camelCase response validation
     */
    public function update_order_status($reference_id, $new_status, $reason = null) {
        subzz_log('=== SUBZZ AZURE API: UPDATE ORDER STATUS REQUEST ===');
        
        // Log request details
        $endpoint = $this->azure_base_url . '/order/' . urlencode($reference_id) . '/update-status';
        subzz_log('SUBZZ AZURE REQUEST: POST ' . $endpoint);
        subzz_log('SUBZZ AZURE REQUEST: Reference ID: ' . $reference_id);
        subzz_log('SUBZZ AZURE REQUEST: New Status: ' . $new_status);
        subzz_log('SUBZZ AZURE REQUEST: Reason: ' . ($reason ?? 'No reason provided'));
        
        // Prepare request payload with optional reason
        $payload = array(
            'newStatus' => $new_status,
            'reason' => $reason ?? 'Status update via API'
        );
        
        $json_payload = wp_json_encode($payload);
        subzz_log('SUBZZ AZURE REQUEST: Payload: ' . $json_payload);
        
        // Make API request
        $start_time = microtime(true);
        subzz_log('SUBZZ AZURE API: Updating order status via Azure backend...');

        $response = wp_remote_post($endpoint, $this->get_default_request_args(array(
            'body' => $json_payload
        )));
        
        $end_time = microtime(true);
        $request_duration = round(($end_time - $start_time) * 1000, 2);
        
        subzz_log('SUBZZ AZURE API: Request completed in ' . $request_duration . 'ms');
        
        // Handle request errors
        if (is_wp_error($response)) {
            subzz_log('SUBZZ AZURE ERROR: HTTP request failed');
            subzz_log('SUBZZ AZURE ERROR: Error message: ' . $response->get_error_message());
            return false;
        }
        
        // Log response details
        $response_code = wp_remote_retrieve_response_code($response);
        $response_message = wp_remote_retrieve_response_message($response);
        $response_body = wp_remote_retrieve_body($response);
        
        subzz_log('SUBZZ AZURE RESPONSE: HTTP ' . $response_code . ' (' . $response_message . ')');
        subzz_log('SUBZZ AZURE RESPONSE: Body content: ' . $response_body);
        
        // Process response
        if ($response_code === 200) {
            $response_data = json_decode($response_body, true);
            
            if ($response_data === null) {
                subzz_log('SUBZZ AZURE ERROR: Response JSON decode failed - ' . json_last_error_msg());
                return false;
            }
            
            subzz_log('SUBZZ AZURE RESPONSE: Decoded data keys: ' . implode(', ', array_keys($response_data)));
            
            if (isset($response_data['success']) && $response_data['success'] === true) {
                subzz_log('SUBZZ AZURE SUCCESS: Order status updated successfully');
                subzz_log('SUBZZ AZURE SUCCESS: Previous status: ' . ($response_data['previousStatus'] ?? 'unknown'));
                subzz_log('SUBZZ AZURE SUCCESS: Current status: ' . ($response_data['currentStatus'] ?? $new_status));
                subzz_log('SUBZZ AZURE SUCCESS: Message: ' . ($response_data['message'] ?? 'No message'));
                subzz_log('SUBZZ AZURE SUCCESS: Total processing time: ' . $request_duration . 'ms');
                return true;
            } else {
                subzz_log('SUBZZ AZURE ERROR: Order status update failed - Success flag not true or missing');
                subzz_log('SUBZZ AZURE ERROR: Response success value: ' . json_encode($response_data['success'] ?? 'MISSING'));
                
                // Log all response fields for debugging
                foreach ($response_data as $key => $value) {
                    subzz_log('SUBZZ AZURE RESPONSE FIELD: ' . $key . ' = ' . json_encode($value));
                }
                
                return false;
            }
        } else if ($response_code === 400) {
            // Handle BadRequest (invalid state transition)
            $error_data = json_decode($response_body, true);
            
            subzz_log('SUBZZ AZURE ERROR: Update order status failed with HTTP 400');
            subzz_log('SUBZZ AZURE ERROR: Response body: ' . $response_body);
            
            if ($error_data && isset($error_data['error'])) {
                subzz_log('SUBZZ AZURE ERROR: ' . $error_data['error']);
                if (isset($error_data['currentStatus'])) {
                    subzz_log('SUBZZ AZURE ERROR: Current status: ' . $error_data['currentStatus']);
                }
            }
            
            return false;
        } else {
            subzz_log('SUBZZ AZURE ERROR: Update order status failed with HTTP ' . $response_code);
            subzz_log('SUBZZ AZURE ERROR: Response body: ' . $response_body);
            
            // Try to decode error response for better debugging
            $error_data = json_decode($response_body, true);
            if ($error_data && isset($error_data['error'])) {
                subzz_log('SUBZZ AZURE ERROR: Structured error message: ' . $error_data['error']);
            }
            
            return false;
        }
    }
    /**
     * Create LekkaPay payment session - COMPREHENSIVE LOGGING
     */
    public function create_lekkapay_session($session_data) {
        subzz_log('=== SUBZZ AZURE API: CREATE LEKKAPAY SESSION REQUEST ===');
        
        // Log request details
        $endpoint = $this->azure_base_url . '/payment/create-session';
        subzz_log('SUBZZ AZURE REQUEST: POST ' . $endpoint);
        subzz_log('SUBZZ AZURE REQUEST: Timeout: ' . $this->api_timeout . ' seconds');
        
        // Validate required fields
        $required_fields = ['orderReferenceId', 'customerEmail', 'customerName', 'amount'];
        foreach ($required_fields as $field) {
            if (!isset($session_data[$field])) {
                subzz_log('SUBZZ AZURE ERROR: Missing required field: ' . $field);
                return false;
            }
        }
        
        // Log session data
        subzz_log('SUBZZ AZURE REQUEST DATA: Order Reference: ' . $session_data['orderReferenceId']);
        subzz_log('SUBZZ AZURE REQUEST DATA: Customer Email: ' . $session_data['customerEmail']);
        subzz_log('SUBZZ AZURE REQUEST DATA: Customer Name: ' . $session_data['customerName']);
        subzz_log('SUBZZ AZURE REQUEST DATA: Amount: ' . ($session_data['currency'] ?? 'ZAR') . ' ' . $session_data['amount']);
        
        // Prepare payload following Azure API structure
        // LekkaPay requires HTTPS for return/cancel URLs — force upgrade from http://
        $return_url = set_url_scheme(home_url('/payment-success/'), 'https');
        $cancel_url = set_url_scheme(home_url('/payment-cancelled/'), 'https');
        $webhook_url = $this->azure_base_url . '/webhook/payment';

        subzz_log('SUBZZ LEKKAPAY: Return URL: ' . $return_url);
        subzz_log('SUBZZ LEKKAPAY: Cancel URL: ' . $cancel_url);

        $payload = array(
            'orderReferenceId' => $session_data['orderReferenceId'],
            'customerEmail' => $session_data['customerEmail'],
            'customerName' => $session_data['customerName'],
            'amount' => floatval($session_data['amount']),
            'currency' => $session_data['currency'] ?? 'ZAR',
            'returnUrl' => $return_url,
            'cancelUrl' => $cancel_url,
            'webhookUrl' => $webhook_url
        );
        
        // Add optional signature ID if provided
        if (isset($session_data['signatureId'])) {
            $payload['signatureId'] = $session_data['signatureId'];
            subzz_log('SUBZZ AZURE REQUEST DATA: Signature ID: ' . $session_data['signatureId']);
        }
        
        $json_payload = wp_json_encode($payload);
        subzz_log('SUBZZ AZURE REQUEST DATA: JSON payload size: ' . strlen($json_payload) . ' bytes');
        
        // Make API request
        $start_time = microtime(true);
        subzz_log('SUBZZ AZURE API: Sending LekkaPay session creation request...');

        $response = wp_remote_post($endpoint, $this->get_default_request_args(array(
            'body' => $json_payload
        )));
        
        $end_time = microtime(true);
        $request_duration = round(($end_time - $start_time) * 1000, 2);
        
        subzz_log('SUBZZ AZURE API: Request completed in ' . $request_duration . 'ms');
        
        // Handle request errors
        if (is_wp_error($response)) {
            subzz_log('SUBZZ AZURE ERROR: HTTP request failed');
            subzz_log('SUBZZ AZURE ERROR: Error code: ' . $response->get_error_code());
            subzz_log('SUBZZ AZURE ERROR: Error message: ' . $response->get_error_message());
            subzz_log('SUBZZ AZURE ERROR: Is Azure backend running on localhost:5000?');
            return false;
        }
        
        // Log response details
        $response_code = wp_remote_retrieve_response_code($response);
        $response_message = wp_remote_retrieve_response_message($response);
        $response_body = wp_remote_retrieve_body($response);
        
        subzz_log('SUBZZ AZURE RESPONSE: HTTP ' . $response_code . ' (' . $response_message . ')');
        subzz_log('SUBZZ AZURE RESPONSE: Body length: ' . strlen($response_body) . ' bytes');
        subzz_log('SUBZZ AZURE RESPONSE: Body content: ' . $response_body);
        
        // Process successful response
        if ($response_code === 200) {
            $response_data = json_decode($response_body, true);
            
            if ($response_data === null) {
                subzz_log('SUBZZ AZURE ERROR: Response JSON decode failed - ' . json_last_error_msg());
                return false;
            }
            
            subzz_log('SUBZZ AZURE RESPONSE: Decoded data keys: ' . implode(', ', array_keys($response_data)));
            
            // Validate response structure
            if (!isset($response_data['sessionId']) || !isset($response_data['checkoutUrl'])) {
                subzz_log('SUBZZ AZURE ERROR: Response missing required fields (sessionId or checkoutUrl)');
                return false;
            }
            
            subzz_log('SUBZZ AZURE SUCCESS: LekkaPay session created successfully');
            subzz_log('SUBZZ AZURE SUCCESS: Session ID: ' . $response_data['sessionId']);
            subzz_log('SUBZZ AZURE SUCCESS: Checkout URL: ' . $response_data['checkoutUrl']);
            subzz_log('SUBZZ AZURE SUCCESS: Total processing time: ' . $request_duration . 'ms');
            
            return $response_data;
        } else {
            subzz_log('SUBZZ AZURE ERROR: Create LekkaPay session failed with HTTP ' . $response_code);
            subzz_log('SUBZZ AZURE ERROR: Response body: ' . $response_body);
            return false;
        }
    }
}
?>