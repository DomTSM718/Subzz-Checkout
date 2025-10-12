<?php
/**
 * Azure API Client Class - Enhanced with Complete Data Flow Logging
 * Handles communication with local Azure backend with comprehensive request/response tracking
 * 
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
        // Local development setup matching your backend
        $this->azure_base_url = 'http://localhost:5000/api';
        $this->api_timeout = 30;
        
        error_log('=== SUBZZ AZURE CLIENT: Initialized with URL: ' . $this->azure_base_url . ' ===');
    }
    
    /**
     * Test Azure backend connection - ENHANCED CONNECTION LOGGING
     */
    public function test_connection() {
        error_log('SUBZZ AZURE TEST: Testing backend connection to ' . $this->azure_base_url);
        
        $test_endpoint = $this->azure_base_url . '/contract/generate';
        
        // Test with minimal request to check if server is running
        $response = wp_remote_post($test_endpoint, array(
            'timeout' => 5,
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode(array()) // Empty body to test connection only
        ));
        
        if (is_wp_error($response)) {
            error_log('SUBZZ AZURE ERROR: Connection failed - ' . $response->get_error_message());
            error_log('SUBZZ AZURE ERROR: Is your Azure backend running on localhost:5000?');
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_message = wp_remote_retrieve_response_message($response);
        
        error_log('SUBZZ AZURE TEST: Response code: ' . $response_code . ' (' . $response_message . ')');
        
        // Any server response (200, 400, 500) means backend is running
        $connection_ok = in_array($response_code, [200, 400, 500]);
        
        if ($connection_ok) {
            error_log('SUBZZ AZURE SUCCESS: Backend connection confirmed - server is running');
        } else {
            error_log('SUBZZ AZURE ERROR: Unexpected response code - backend may not be running properly');
        }
        
        return $connection_ok;
    }
    
    /**
     * Store order data - COMPREHENSIVE REQUEST/RESPONSE LOGGING
     */
    public function store_order_data($order_data) {
        error_log('=== SUBZZ AZURE API: STORE ORDER DATA REQUEST ===');
        
        // Log request details
        $endpoint = $this->azure_base_url . '/order/store';
        error_log('SUBZZ AZURE REQUEST: POST ' . $endpoint);
        error_log('SUBZZ AZURE REQUEST: Timeout: ' . $this->api_timeout . ' seconds');
        
        // Log order data structure being sent
        error_log('SUBZZ AZURE REQUEST DATA: Order structure keys: ' . implode(', ', array_keys($order_data)));
        error_log('SUBZZ AZURE REQUEST DATA: WooCommerce Order ID: ' . ($order_data['woocommerce_order_id'] ?? 'missing'));
        error_log('SUBZZ AZURE REQUEST DATA: Customer email: ' . ($order_data['customer_email'] ?? 'missing'));
        error_log('SUBZZ AZURE REQUEST DATA: Customer name: ' . ($order_data['customer_data']['first_name'] ?? '') . ' ' . ($order_data['customer_data']['last_name'] ?? ''));
        error_log('SUBZZ AZURE REQUEST DATA: Order total: ' . ($order_data['order_totals']['currency'] ?? '') . ' ' . ($order_data['order_totals']['total'] ?? ''));
        error_log('SUBZZ AZURE REQUEST DATA: Items count: ' . (isset($order_data['order_items']) ? count($order_data['order_items']) : 0));
        
        // Count subscription vs regular items
        $subscription_items = 0;
        if (isset($order_data['order_items']) && is_array($order_data['order_items'])) {
            foreach ($order_data['order_items'] as $item) {
                if (isset($item['is_subscription']) && $item['is_subscription']) {
                    $subscription_items++;
                    error_log('SUBZZ AZURE REQUEST DATA: Subscription item - ' . $item['name'] . ' (ID: ' . $item['product_id'] . ')');
                }
            }
        }
        error_log('SUBZZ AZURE REQUEST DATA: Subscription items: ' . $subscription_items);
        
        // Log complete payload size
        $json_payload = wp_json_encode($order_data);
        error_log('SUBZZ AZURE REQUEST DATA: JSON payload size: ' . strlen($json_payload) . ' bytes');
        
        // Make API request
        $start_time = microtime(true);
        error_log('SUBZZ AZURE API: Sending request to Azure backend...');
        
        $response = wp_remote_post($endpoint, array(
            'timeout' => $this->api_timeout,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => $json_payload
        ));
        
        $end_time = microtime(true);
        $request_duration = round(($end_time - $start_time) * 1000, 2); // Convert to milliseconds
        
        error_log('SUBZZ AZURE API: Request completed in ' . $request_duration . 'ms');
        
        // Handle request errors
        if (is_wp_error($response)) {
            error_log('SUBZZ AZURE ERROR: HTTP request failed');
            error_log('SUBZZ AZURE ERROR: Error code: ' . $response->get_error_code());
            error_log('SUBZZ AZURE ERROR: Error message: ' . $response->get_error_message());
            error_log('SUBZZ AZURE ERROR: Is Azure backend running on localhost:5000?');
            return false;
        }
        
        // Log response details
        $response_code = wp_remote_retrieve_response_code($response);
        $response_message = wp_remote_retrieve_response_message($response);
        $response_body = wp_remote_retrieve_body($response);
        
        error_log('SUBZZ AZURE RESPONSE: HTTP ' . $response_code . ' (' . $response_message . ')');
        error_log('SUBZZ AZURE RESPONSE: Body length: ' . strlen($response_body) . ' bytes');
        error_log('SUBZZ AZURE RESPONSE: Body content: ' . $response_body);
        
        // Process successful response
        if ($response_code === 200) {
            $response_data = json_decode($response_body, true);
            
            if ($response_data === null) {
                error_log('SUBZZ AZURE ERROR: Response JSON decode failed - ' . json_last_error_msg());
                return false;
            }
            
            error_log('SUBZZ AZURE RESPONSE: Decoded data keys: ' . implode(', ', array_keys($response_data)));
            
            $reference_id = $response_data['referenceId'] ?? false;
            
            if ($reference_id) {
                error_log('SUBZZ AZURE SUCCESS: Order stored successfully');
                error_log('SUBZZ AZURE SUCCESS: Reference ID: ' . $reference_id);
                error_log('SUBZZ AZURE SUCCESS: Total processing time: ' . $request_duration . 'ms');
                return $reference_id;
            } else {
                error_log('SUBZZ AZURE ERROR: Response missing referenceId field');
                return false;
            }
        } else {
            error_log('SUBZZ AZURE ERROR: Store order failed with HTTP ' . $response_code);
            error_log('SUBZZ AZURE ERROR: Response body: ' . $response_body);
            return false;
        }
    }
    
    /**
     * Retrieve order data - COMPREHENSIVE REQUEST/RESPONSE LOGGING
     */
    public function retrieve_order_data($reference_id) {
        error_log('=== SUBZZ AZURE API: RETRIEVE ORDER DATA REQUEST ===');
        
        // Log request details
        $endpoint = $this->azure_base_url . '/order/' . urlencode($reference_id);
        error_log('SUBZZ AZURE REQUEST: GET ' . $endpoint);
        error_log('SUBZZ AZURE REQUEST: Reference ID: ' . $reference_id);
        
        // Make API request
        $start_time = microtime(true);
        error_log('SUBZZ AZURE API: Retrieving order data from Azure backend...');
        
        $response = wp_remote_get($endpoint, array(
            'timeout' => $this->api_timeout
        ));
        
        $end_time = microtime(true);
        $request_duration = round(($end_time - $start_time) * 1000, 2);
        
        error_log('SUBZZ AZURE API: Request completed in ' . $request_duration . 'ms');
        
        // Handle request errors
        if (is_wp_error($response)) {
            error_log('SUBZZ AZURE ERROR: HTTP request failed');
            error_log('SUBZZ AZURE ERROR: Error message: ' . $response->get_error_message());
            return false;
        }
        
        // Log response details
        $response_code = wp_remote_retrieve_response_code($response);
        $response_message = wp_remote_retrieve_response_message($response);
        $response_body = wp_remote_retrieve_body($response);
        
        error_log('SUBZZ AZURE RESPONSE: HTTP ' . $response_code . ' (' . $response_message . ')');
        error_log('SUBZZ AZURE RESPONSE: Body length: ' . strlen($response_body) . ' bytes');
        
        // Process successful response
        if ($response_code === 200) {
            $order_data = json_decode($response_body, true);
            
            if ($order_data === null) {
                error_log('SUBZZ AZURE ERROR: Response JSON decode failed - ' . json_last_error_msg());
                return false;
            }
            
            // Log retrieved order data structure
            error_log('SUBZZ AZURE RESPONSE: Order data keys: ' . implode(', ', array_keys($order_data)));
            error_log('SUBZZ AZURE RESPONSE: WooCommerce Order ID: ' . ($order_data['woocommerce_order_id'] ?? 'missing'));
            error_log('SUBZZ AZURE RESPONSE: Customer email: ' . ($order_data['customer_email'] ?? 'missing'));
            error_log('SUBZZ AZURE RESPONSE: Customer name: ' . ($order_data['customer_data']['first_name'] ?? '') . ' ' . ($order_data['customer_data']['last_name'] ?? ''));
            error_log('SUBZZ AZURE RESPONSE: Order total: ' . ($order_data['order_totals']['currency'] ?? '') . ' ' . ($order_data['order_totals']['total'] ?? ''));
            
            // Log subscription items in response
            if (isset($order_data['order_items']) && is_array($order_data['order_items'])) {
                $subscription_count = 0;
                foreach ($order_data['order_items'] as $item) {
                    if (isset($item['is_subscription']) && $item['is_subscription']) {
                        $subscription_count++;
                        error_log('SUBZZ AZURE RESPONSE: Subscription item - ' . $item['name'] . ' (Price: ' . $item['price'] . ')');
                    }
                }
                error_log('SUBZZ AZURE RESPONSE: Total subscription items: ' . $subscription_count);
            }
            
            error_log('SUBZZ AZURE SUCCESS: Order data retrieved successfully');
            error_log('SUBZZ AZURE SUCCESS: Total processing time: ' . $request_duration . 'ms');
            return $order_data;
        } else {
            error_log('SUBZZ AZURE ERROR: Retrieve order failed with HTTP ' . $response_code);
            error_log('SUBZZ AZURE ERROR: Response body: ' . $response_body);
            return false;
        }
    }
    
    /**
     * Generate contract - ENHANCED TO RETURN FULL RESPONSE WITH VARIANT INFO
     * VARIANT ENHANCEMENT: Returns complete response object including contractHtml and variant_info
     * ORDER REFERENCE ENHANCEMENT: Accepts reference_id to enable backend database retrieval
     */
    public function generate_contract($customer_email, $order_data, $reference_id = null) {
        error_log('=== SUBZZ AZURE API: GENERATE CONTRACT REQUEST ===');
        
        // Log request details
        $endpoint = $this->azure_base_url . '/contract/generate';
        error_log('SUBZZ AZURE REQUEST: POST ' . $endpoint);
        error_log('SUBZZ AZURE REQUEST: Customer email: ' . $customer_email);
        error_log('SUBZZ AZURE REQUEST: Order data keys: ' . implode(', ', array_keys($order_data)));
        
        // Log if reference ID is being used
        if (!empty($reference_id)) {
            error_log('SUBZZ AZURE REQUEST: Order Reference ID provided: ' . $reference_id);
            error_log('SUBZZ AZURE REQUEST: Backend will retrieve order data from database');
        }
        
        // Log order data that will be used in contract
        if (isset($order_data['customer_data'])) {
            $customer_name = $order_data['customer_data']['first_name'] . ' ' . $order_data['customer_data']['last_name'];
            error_log('SUBZZ AZURE REQUEST: Contract for customer: ' . $customer_name);
        }
        
        if (isset($order_data['order_items'])) {
            $subscription_items = array_filter($order_data['order_items'], function($item) { 
                return isset($item['is_subscription']) && $item['is_subscription']; 
            });
            error_log('SUBZZ AZURE REQUEST: Subscription items for contract: ' . count($subscription_items));
        }
        
        // Extract billing address fields from order data if available
        $billing_address = null;
        $city = null;
        $province = null;
        $postal_code = null;
        
        // Try to find billing address in the order data structure
        // Check both possible locations where WooCommerce might place it
        if (isset($order_data['billing_address'])) {
            // Direct billing_address field
            $billing = $order_data['billing_address'];
        } elseif (isset($order_data['customer_data']['billing_address'])) {
            // Nested under customer_data
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
            
            error_log('SUBZZ AZURE REQUEST: Billing address found - Street: ' . $billing_address);
            error_log('SUBZZ AZURE REQUEST: City: ' . $city . ', Province: ' . $province . ', Postal: ' . $postal_code);
        } else {
            error_log('SUBZZ AZURE REQUEST: No billing address found in order data');
        }
        
        // Prepare request payload
        $payload = array(
            'customerEmail' => $customer_email,
            'orderData' => $order_data
        );
        
        // Add reference ID if available (for backend to retrieve stored order data)
        if (!empty($reference_id)) {
            $payload['orderReferenceId'] = $reference_id;
            error_log('SUBZZ AZURE REQUEST: Including orderReferenceId for database retrieval: ' . $reference_id);
        }
        
        // Add individual address fields if found
        if ($billing_address) {
            $payload['billingAddress'] = $billing_address;
            $payload['city'] = $city;
            $payload['province'] = $province;
            $payload['postalCode'] = $postal_code;
            error_log('SUBZZ AZURE REQUEST: Including separate address fields in payload');
        }
        
        $json_payload = wp_json_encode($payload);
        error_log('SUBZZ AZURE REQUEST: Payload size: ' . strlen($json_payload) . ' bytes');
        error_log('SUBZZ AZURE REQUEST: Payload keys being sent: ' . implode(', ', array_keys($payload)));
        
        // Make API request
        $start_time = microtime(true);
        error_log('SUBZZ AZURE API: Generating contract via Azure backend...');
        
        $response = wp_remote_post($endpoint, array(
            'timeout' => $this->api_timeout,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => $json_payload
        ));
        
        $end_time = microtime(true);
        $request_duration = round(($end_time - $start_time) * 1000, 2);
        
        error_log('SUBZZ AZURE API: Request completed in ' . $request_duration . 'ms');
        
        // Handle request errors
        if (is_wp_error($response)) {
            error_log('SUBZZ AZURE ERROR: HTTP request failed');
            error_log('SUBZZ AZURE ERROR: Error message: ' . $response->get_error_message());
            return false;
        }
        
        // Log response details
        $response_code = wp_remote_retrieve_response_code($response);
        $response_message = wp_remote_retrieve_response_message($response);
        $response_body = wp_remote_retrieve_body($response);
        
        error_log('SUBZZ AZURE RESPONSE: HTTP ' . $response_code . ' (' . $response_message . ')');
        error_log('SUBZZ AZURE RESPONSE: Body length: ' . strlen($response_body) . ' bytes');
        
        // Process successful response
        if ($response_code === 200) {
            $response_data = json_decode($response_body, true);
            
            if ($response_data === null) {
                error_log('SUBZZ AZURE ERROR: Response JSON decode failed - ' . json_last_error_msg());
                return false;
            }
            
            error_log('SUBZZ AZURE RESPONSE: Decoded data keys: ' . implode(', ', array_keys($response_data)));
            
            // VARIANT ENHANCEMENT: Return full response object instead of just HTML
            if (isset($response_data['contractHtml'])) {
                error_log('SUBZZ AZURE SUCCESS: Contract generated successfully');
                error_log('SUBZZ AZURE SUCCESS: Contract HTML length: ' . strlen($response_data['contractHtml']) . ' characters');
                
                // Log variant info if present
                if (isset($response_data['variant_info'])) {
                    error_log('SUBZZ AZURE SUCCESS: Variant info received');
                    error_log('SUBZZ AZURE SUCCESS: Variant duration: ' . ($response_data['variant_info']['subscription_duration_months'] ?? 'not set'));
                    error_log('SUBZZ AZURE SUCCESS: Monthly amount: ' . ($response_data['variant_info']['monthly_amount'] ?? 'not set'));
                    error_log('SUBZZ AZURE SUCCESS: Total contract value: ' . ($response_data['variant_info']['total_contract_value'] ?? 'not set'));
                }
                
                // Log other response fields
                if (isset($response_data['agreementReference'])) {
                    error_log('SUBZZ AZURE SUCCESS: Agreement reference: ' . $response_data['agreementReference']);
                }
                
                error_log('SUBZZ AZURE SUCCESS: Total processing time: ' . $request_duration . 'ms');
                
                // RETURN FULL RESPONSE OBJECT FOR VARIANT DATA CAPTURE
                return $response_data;
            } else {
                error_log('SUBZZ AZURE ERROR: Response missing contractHtml field');
                return false;
            }
        } else {
            error_log('SUBZZ AZURE ERROR: Generate contract failed with HTTP ' . $response_code);
            error_log('SUBZZ AZURE ERROR: Response body: ' . $response_body);
            return false;
        }
    }
    
    /**
     * Store signature - ENHANCED FOR VARIANT SUPPORT
     * Will be further enhanced to accept variant and legal compliance fields
     */
    public function store_signature($customer_email, $signature_data, $order_reference_id, $additional_data = array()) {
        error_log('=== SUBZZ AZURE API: STORE SIGNATURE REQUEST ===');
        
        // Log request details
        $endpoint = $this->azure_base_url . '/contract/sign';
        error_log('SUBZZ AZURE REQUEST: POST ' . $endpoint);
        error_log('SUBZZ AZURE REQUEST: Customer email: ' . $customer_email);
        error_log('SUBZZ AZURE REQUEST: Order reference ID: ' . $order_reference_id);
        error_log('SUBZZ AZURE REQUEST: Signature data length: ' . strlen($signature_data) . ' characters');
        
        // Log additional data if provided (for variant support)
        if (!empty($additional_data)) {
            error_log('SUBZZ AZURE REQUEST: Additional data keys: ' . implode(', ', array_keys($additional_data)));
        }
        
        // Prepare metadata
        $metadata = array(
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'timestamp' => current_time('mysql'),
            'wordpress_site' => home_url(),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        );
        
        error_log('SUBZZ AZURE REQUEST: Client IP: ' . $metadata['ip_address']);
        error_log('SUBZZ AZURE REQUEST: User agent: ' . substr($metadata['user_agent'], 0, 100) . '...');
        error_log('SUBZZ AZURE REQUEST: Timestamp: ' . $metadata['timestamp']);
        
        // Prepare request payload
        $payload = array(
            'customerEmail' => $customer_email,
            'signatureData' => $signature_data,
            'orderReferenceId' => $order_reference_id,
            'metadata' => $metadata
        );
        
        // Add additional data to payload (for variant and legal compliance fields)
        if (!empty($additional_data)) {
            $payload = array_merge($payload, $additional_data);
        }
        
        $json_payload = wp_json_encode($payload);
        error_log('SUBZZ AZURE REQUEST: Payload size: ' . strlen($json_payload) . ' bytes');
        
        // Make API request
        $start_time = microtime(true);
        error_log('SUBZZ AZURE API: Storing signature via Azure backend...');
        
        $response = wp_remote_post($endpoint, array(
            'timeout' => $this->api_timeout,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => $json_payload
        ));
        
        $end_time = microtime(true);
        $request_duration = round(($end_time - $start_time) * 1000, 2);
        
        error_log('SUBZZ AZURE API: Request completed in ' . $request_duration . 'ms');
        
        // Handle request errors
        if (is_wp_error($response)) {
            error_log('SUBZZ AZURE ERROR: HTTP request failed');
            error_log('SUBZZ AZURE ERROR: Error message: ' . $response->get_error_message());
            return false;
        }
        
        // Log response details
        $response_code = wp_remote_retrieve_response_code($response);
        $response_message = wp_remote_retrieve_response_message($response);
        $response_body = wp_remote_retrieve_body($response);
        
        error_log('SUBZZ AZURE RESPONSE: HTTP ' . $response_code . ' (' . $response_message . ')');
        error_log('SUBZZ AZURE RESPONSE: Body length: ' . strlen($response_body) . ' bytes');
        error_log('SUBZZ AZURE RESPONSE: Body content: ' . $response_body);
        
        // Process successful response
        if ($response_code === 200) {
            $response_data = json_decode($response_body, true);
            
            if ($response_data === null) {
                error_log('SUBZZ AZURE ERROR: Response JSON decode failed - ' . json_last_error_msg());
                return false;
            }
            
            error_log('SUBZZ AZURE RESPONSE: Decoded data keys: ' . implode(', ', array_keys($response_data)));
            
            $signature_id = $response_data['signatureId'] ?? true;
            
            error_log('SUBZZ AZURE SUCCESS: Signature stored successfully');
            if ($signature_id && $signature_id !== true) {
                error_log('SUBZZ AZURE SUCCESS: Signature ID: ' . $signature_id);
            }
            error_log('SUBZZ AZURE SUCCESS: Total processing time: ' . $request_duration . 'ms');
            
            return $signature_id;
        } else {
            error_log('SUBZZ AZURE ERROR: Store signature failed with HTTP ' . $response_code);
            error_log('SUBZZ AZURE ERROR: Response body: ' . $response_body);
            return false;
        }
    }
    
    /**
     * Consume order data - ENHANCED LOGGING
     */
    public function consume_order_data($reference_id) {
        error_log('=== SUBZZ AZURE API: CONSUME ORDER DATA REQUEST ===');
        
        // Log request details
        $endpoint = $this->azure_base_url . '/order/' . urlencode($reference_id) . '/consume';
        error_log('SUBZZ AZURE REQUEST: POST ' . $endpoint);
        error_log('SUBZZ AZURE REQUEST: Reference ID: ' . $reference_id);
        
        // Make API request
        $start_time = microtime(true);
        error_log('SUBZZ AZURE API: Consuming order data via Azure backend...');
        
        $response = wp_remote_post($endpoint, array(
            'timeout' => $this->api_timeout,
            'headers' => array(
                'Content-Type' => 'application/json',
            )
        ));
        
        $end_time = microtime(true);
        $request_duration = round(($end_time - $start_time) * 1000, 2);
        
        error_log('SUBZZ AZURE API: Request completed in ' . $request_duration . 'ms');
        
        // Handle request errors
        if (is_wp_error($response)) {
            error_log('SUBZZ AZURE ERROR: HTTP request failed');
            error_log('SUBZZ AZURE ERROR: Error message: ' . $response->get_error_message());
            return false;
        }
        
        // Log response details
        $response_code = wp_remote_retrieve_response_code($response);
        $response_message = wp_remote_retrieve_response_message($response);
        $response_body = wp_remote_retrieve_body($response);
        
        error_log('SUBZZ AZURE RESPONSE: HTTP ' . $response_code . ' (' . $response_message . ')');
        error_log('SUBZZ AZURE RESPONSE: Body content: ' . $response_body);
        
        $success = ($response_code === 200);
        
        if ($success) {
            error_log('SUBZZ AZURE SUCCESS: Order data consumed successfully');
            error_log('SUBZZ AZURE SUCCESS: Total processing time: ' . $request_duration . 'ms');
        } else {
            error_log('SUBZZ AZURE ERROR: Consume order failed with HTTP ' . $response_code);
        }
        
        return $success;
    }
    
    /**
     * Update order status - FIXED for camelCase response validation
     */
    public function update_order_status($reference_id, $new_status, $reason = null) {
        error_log('=== SUBZZ AZURE API: UPDATE ORDER STATUS REQUEST ===');
        
        // Log request details
        $endpoint = $this->azure_base_url . '/order/' . urlencode($reference_id) . '/update-status';
        error_log('SUBZZ AZURE REQUEST: POST ' . $endpoint);
        error_log('SUBZZ AZURE REQUEST: Reference ID: ' . $reference_id);
        error_log('SUBZZ AZURE REQUEST: New Status: ' . $new_status);
        error_log('SUBZZ AZURE REQUEST: Reason: ' . ($reason ?? 'No reason provided'));
        
        // Prepare request payload with optional reason
        $payload = array(
            'newStatus' => $new_status,
            'reason' => $reason ?? 'Status update via API'
        );
        
        $json_payload = wp_json_encode($payload);
        error_log('SUBZZ AZURE REQUEST: Payload: ' . $json_payload);
        
        // Make API request
        $start_time = microtime(true);
        error_log('SUBZZ AZURE API: Updating order status via Azure backend...');
        
        $response = wp_remote_post($endpoint, array(
            'timeout' => $this->api_timeout,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => $json_payload
        ));
        
        $end_time = microtime(true);
        $request_duration = round(($end_time - $start_time) * 1000, 2);
        
        error_log('SUBZZ AZURE API: Request completed in ' . $request_duration . 'ms');
        
        // Handle request errors
        if (is_wp_error($response)) {
            error_log('SUBZZ AZURE ERROR: HTTP request failed');
            error_log('SUBZZ AZURE ERROR: Error message: ' . $response->get_error_message());
            return false;
        }
        
        // Log response details
        $response_code = wp_remote_retrieve_response_code($response);
        $response_message = wp_remote_retrieve_response_message($response);
        $response_body = wp_remote_retrieve_body($response);
        
        error_log('SUBZZ AZURE RESPONSE: HTTP ' . $response_code . ' (' . $response_message . ')');
        error_log('SUBZZ AZURE RESPONSE: Body content: ' . $response_body);
        
        // Process response
        if ($response_code === 200) {
            $response_data = json_decode($response_body, true);
            
            if ($response_data === null) {
                error_log('SUBZZ AZURE ERROR: Response JSON decode failed - ' . json_last_error_msg());
                return false;
            }
            
            error_log('SUBZZ AZURE RESPONSE: Decoded data keys: ' . implode(', ', array_keys($response_data)));
            
            // FIXED: Check for lowercase 'success' to match Azure's camelCase response
            if (isset($response_data['success']) && $response_data['success'] === true) {
                error_log('SUBZZ AZURE SUCCESS: Order status updated successfully');
                error_log('SUBZZ AZURE SUCCESS: Previous status: ' . ($response_data['previousStatus'] ?? 'unknown'));
                error_log('SUBZZ AZURE SUCCESS: Current status: ' . ($response_data['currentStatus'] ?? $new_status));
                error_log('SUBZZ AZURE SUCCESS: Message: ' . ($response_data['message'] ?? 'No message'));
                error_log('SUBZZ AZURE SUCCESS: Total processing time: ' . $request_duration . 'ms');
                return true;
            } else {
                error_log('SUBZZ AZURE ERROR: Order status update failed - Success flag not true or missing');
                error_log('SUBZZ AZURE ERROR: Response success value: ' . json_encode($response_data['success'] ?? 'MISSING'));
                
                // ENHANCED: Log all response fields for debugging
                foreach ($response_data as $key => $value) {
                    error_log('SUBZZ AZURE RESPONSE FIELD: ' . $key . ' = ' . json_encode($value));
                }
                
                return false;
            }
        } else if ($response_code === 400) {
            // Handle BadRequest (invalid state transition)
            $error_data = json_decode($response_body, true);
            
            error_log('SUBZZ AZURE ERROR: Update order status failed with HTTP 400');
            error_log('SUBZZ AZURE ERROR: Response body: ' . $response_body);
            
            if ($error_data && isset($error_data['error'])) {
                error_log('SUBZZ AZURE ERROR: ' . $error_data['error']);
                if (isset($error_data['currentStatus'])) {
                    error_log('SUBZZ AZURE ERROR: Current status: ' . $error_data['currentStatus']);
                }
            }
            
            return false;
        } else {
            error_log('SUBZZ AZURE ERROR: Update order status failed with HTTP ' . $response_code);
            error_log('SUBZZ AZURE ERROR: Response body: ' . $response_body);
            
            // ENHANCED: Try to decode error response for better debugging
            $error_data = json_decode($response_body, true);
            if ($error_data && isset($error_data['error'])) {
                error_log('SUBZZ AZURE ERROR: Structured error message: ' . $error_data['error']);
            }
            
            return false;
        }
    }
}
?>