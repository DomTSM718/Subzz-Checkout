<?php
/**
 * Subzz Processing-Email Suppression (v2.6.2, 2026-09-11)
 *
 * WooCommerce emails the customer "Your order is being processed" whenever an order moves to
 * 'processing'. From API #471 the Subzz API moves every paid Subzz order there (before it, paid
 * orders sat at 'pending' and read "Pending payment" on the customer's own account). Dom decided
 * customers must NOT get that extra email: they already receive the Subzz contract email at
 * payment and the Subzz shipment email when the retailer ships.
 *
 * So for Subzz orders only, the email is switched off. Every other order keeps WooCommerce's
 * setting. This also stops the processing email that retailer "mark shipped" used to trigger —
 * the Subzz shipment email covers that moment.
 *
 * A Subzz order is one the plugin created: Subzz_Payment_Handler stamps
 * _subzz_subscription_enabled = 'yes' straight after wc_create_order, before any payment.
 *
 * ⚠️ The API-side switch (WooCommerceOrderStatusSync__Enabled) must only be turned on in an
 * environment once THIS version is live there, or that environment's customers get the email.
 *
 * @package Subzz_Subscription_Payments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Subzz_Processing_Email_Suppression {

    /** Meta stamped on every plugin-created order at creation. */
    const SUBZZ_ORDER_META = '_subzz_subscription_enabled';

    public function __construct() {
        // WC_Email::is_enabled(): apply_filters('woocommerce_email_enabled_' . $id, $enabled, $object, $email)
        add_filter('woocommerce_email_enabled_customer_processing_order', array(__CLASS__, 'filter_enabled'), 10, 2);
    }

    /**
     * @param bool  $enabled Whether WooCommerce would send the email.
     * @param mixed $order   The WC_Order being emailed; null (or another object) on settings screens.
     * @return bool
     */
    public static function filter_enabled($enabled, $order) {
        if (!$enabled) {
            return $enabled;
        }
        // Settings screens call is_enabled() with no order — leave the shop setting untouched there.
        if (!is_object($order) || !method_exists($order, 'get_meta')) {
            return $enabled;
        }
        return $order->get_meta(self::SUBZZ_ORDER_META) === 'yes' ? false : $enabled;
    }
}
