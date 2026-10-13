<?php
/**
 * Avvance Webhooks Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class Avvance_Webhooks {
    
    public static function init() {
        add_action('woocommerce_api_avvance_webhook', [__CLASS__, 'handle_webhook']);
    }
    
    /**
     * Handle incoming webhook
     */
    public static function handle_webhook() {
        avvance_log('Webhook received');
        
        // Verify Basic Auth
        if (!self::verify_auth()) {
            avvance_log('Webhook authentication failed', 'error');
            status_header(401);
            exit;
        }
        
        // Get raw payload
        $raw_payload = file_get_contents('php://input');
        $payload = json_decode($raw_payload, true);
        
        if (!$payload) {
            avvance_log('Invalid webhook payload', 'error');
            status_header(400);
            exit;
        }
        
        avvance_log('Webhook payload: ' . print_r($payload, true));
        
        // Validate required fields
        if (!isset($payload['eventName']) || !isset($payload['eventDetails'])) {
            avvance_log('Missing required webhook fields', 'error');
            status_header(400);
            exit;
        }
        
        // Only process LOAN_STATUS_DETAILS events
        if ($payload['eventName'] !== 'LOAN_STATUS_DETAILS') {
            avvance_log('Ignoring non-loan-status event: ' . $payload['eventName']);
            status_header(200);
            exit;
        }
        
        // Process the webhook
        $result = self::process_loan_status($payload);
        
        if (is_wp_error($result)) {
            avvance_log('Webhook processing failed: ' . $result->get_error_message(), 'error');
            status_header(500);
            exit;
        }
        
        avvance_log('Webhook processed successfully');
        status_header(200);
        exit;
    }
    
    /**
     * Verify Basic Auth
     */
    private static function verify_auth() {
        $gateway = avvance_get_gateway();
        if (!$gateway) {
            return false;
        }
        
        $expected_username = $gateway->get_option('webhook_username');
        $expected_password = $gateway->get_option('webhook_password');
        
        if (empty($expected_username) || empty($expected_password)) {
            return false;
        }
        
        // Check for Authorization header
        $auth_header = null;
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth_header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                $auth_header = $headers['Authorization'];
            }
        }
        
        if (!$auth_header) {
            return false;
        }
        
        // Parse Basic Auth
        if (strpos($auth_header, 'Basic ') !== 0) {
            return false;
        }
        
        $credentials = base64_decode(substr($auth_header, 6));
        list($username, $password) = explode(':', $credentials, 2);
        
        return hash_equals($expected_username, $username) && hash_equals($expected_password, $password);
    }
    
    /**
     * Process loan status webhook
     */
    private static function process_loan_status($payload) {
        $event_details = $payload['eventDetails'];
        
        // Get identifiers
        $application_guid = $event_details['applicationGUID'] ?? '';
        $partner_session_id = $event_details['partnerSessionId'] ?? '';
        $status = $event_details['loanStatus']['status'] ?? '';
        
        if (empty($application_guid) && empty($partner_session_id)) {
            return new WP_Error('missing_identifiers', 'Missing applicationGUID or partnerSessionId');
        }
        
        // Find the order
        $order = self::find_order($application_guid, $partner_session_id);
        
        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found for webhook');
        }
        
        // Check for duplicate webhook (idempotency)
        $last_status = $order->get_meta('_avvance_last_webhook_status');
        if ($last_status === $status) {
            avvance_log('Duplicate webhook status, skipping: ' . $status);
            return true;
        }
        
        // Store webhook history
        $history = $order->get_meta('_avvance_webhook_history') ?: [];
        $history[] = [
            'status' => $status,
            'timestamp' => current_time('mysql'),
            'payload' => $event_details
        ];
        $order->update_meta_data('_avvance_webhook_history', $history);
        $order->update_meta_data('_avvance_last_webhook_status', $status);
        
        // Process based on status
        switch ($status) {
            case 'APPLICATION_STARTED':
                $order->add_order_note(__('Customer started Avvance application', 'avvance-for-woocommerce'));
                break;
                
            case 'APPLICATION_APPROVED':
                $order->add_order_note(__('Avvance application approved - awaiting customer to complete purchase', 'avvance-for-woocommerce'));
                break;
                
            case 'APPLICATION_PENDING_REQUIRE_CUSTOMER_ACTION':
                $order->add_order_note(__('Avvance application requires customer action (e.g., credit freeze removal)', 'avvance-for-woocommerce'));
                break;
                
            case 'INVOICE_PAYMENT_TRANSACTION_AUTHORIZED':
                // Payment successful!
                $payment_transaction_id = $event_details['paymentTransactionId'] ?? '';
                $approval_code = $event_details['approvalCode'] ?? '';
                
                $order->update_meta_data('_avvance_payment_transaction_id', $payment_transaction_id);
                $order->update_meta_data('_avvance_approval_code', $approval_code);
                
                if (isset($event_details['loanSummary'])) {
                    $order->update_meta_data('_avvance_loan_summary', $event_details['loanSummary']);
                }
                
                $order->payment_complete($payment_transaction_id);
                $order->add_order_note(sprintf(
                    __('Avvance payment authorized. Transaction ID: %s, Approval Code: %s', 'avvance-for-woocommerce'),
                    $payment_transaction_id,
                    $approval_code
                ));
                
                // Clear session
                if (WC()->session) {
                    WC()->session->__unset('avvance_pending_order_id');
                }
                
                avvance_log('Order #' . $order->get_id() . ' payment completed via webhook');
                break;
                
            case 'INVOICE_PAYMENT_TRANSACTION_SETTLED':
                $order->add_order_note(__('Avvance payment settled (merchant settlement processed)', 'avvance-for-woocommerce'));
                break;
                
            case 'APPLICATION_DENIED_REQUEST_ALTERNATE_PAYMENT':
            case 'SYSTEM_ERROR_REQUEST_ALTERNATE_PAYMENT':
                if (!$order->is_paid()) {
                    $order->update_status('cancelled', sprintf(
                        __('Avvance application %s - customer should use alternate payment', 'avvance-for-woocommerce'),
                        $status === 'APPLICATION_DENIED_REQUEST_ALTERNATE_PAYMENT' ? 'declined' : 'encountered system error'
                    ));
                }
                
                // Clear session
                if (WC()->session) {
                    WC()->session->__unset('avvance_pending_order_id');
                }
                break;
                
            case 'APPLICATION_LINK_EXPIRED':
                if (!$order->is_paid()) {
                    $order->add_order_note(__('Avvance application link expired (30 days)', 'avvance-for-woocommerce'));
                }
                break;
                
            default:
                $order->add_order_note(sprintf(__('Avvance status update: %s', 'avvance-for-woocommerce'), avvance_get_status_message($status)));
        }
        
        $order->save();
        
        return true;
    }
    
    /**
     * Find order by application GUID or partner session ID
     */
    private static function find_order($application_guid, $partner_session_id) {
        // Try application GUID first
        if (!empty($application_guid)) {
            $orders = wc_get_orders([
                'limit' => 1,
                'meta_key' => '_avvance_application_guid',
                'meta_value' => $application_guid,
                'return' => 'objects'
            ]);
            
            if (!empty($orders)) {
                return $orders[0];
            }
        }
        
        // Try partner session ID
        if (!empty($partner_session_id)) {
            $orders = wc_get_orders([
                'limit' => 1,
                'meta_key' => '_avvance_partner_session_id',
                'meta_value' => $partner_session_id,
                'return' => 'objects'
            ]);
            
            if (!empty($orders)) {
                return $orders[0];
            }
        }
        
        return null;
    }
}
