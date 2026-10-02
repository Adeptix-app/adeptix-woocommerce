<?php

if (!defined('ABSPATH')) {
    exit;
}

use Adeptix\AdeptixClient;

/**
 * Shared by both gateway classes: the API key / webhook secret settings fields, a helper to build
 * a configured AdeptixClient, and the webhook-endpoint wiring (WooCommerce's `wc-api/<gateway id>`
 * convention - see https://docs.adeptix.app/webhooks for the payload/signature this verifies).
 */
trait Adeptix_Gateway_Common
{
    protected function adeptix_common_form_fields(): array
    {
        return [
            'enabled' => [
                'title' => __('Enable/Disable', 'adeptix-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable this payment method', 'adeptix-woocommerce'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'adeptix-woocommerce'),
                'type' => 'text',
                'description' => __('Shown to the customer during checkout.', 'adeptix-woocommerce'),
                'default' => $this->method_title,
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'adeptix-woocommerce'),
                'type' => 'textarea',
                'description' => __('Shown to the customer during checkout.', 'adeptix-woocommerce'),
                'default' => '',
                'desc_tip' => true,
            ],
            'api_key' => [
                'title' => __('Adeptix API key', 'adeptix-woocommerce'),
                'type' => 'password',
                'description' => __('From your Adeptix dashboard\'s API Keys page.', 'adeptix-woocommerce'),
                'default' => '',
                'desc_tip' => true,
            ],
            'webhook_secret' => [
                'title' => __('Webhook secret', 'adeptix-woocommerce'),
                'type' => 'password',
                'description' => sprintf(
                    /* translators: %s: webhook URL to register in the Adeptix dashboard */
                    __('From your Adeptix dashboard\'s Settings page. Register this URL there as your webhook URL: %s', 'adeptix-woocommerce'),
                    '<code>' . esc_html($this->adeptix_webhook_url()) . '</code>'
                ),
                'default' => '',
                'desc_tip' => false,
            ],
        ];
    }

    protected function adeptix_webhook_url(): string
    {
        return WC()->api_request_url($this->id);
    }

    protected function adeptix_client(): AdeptixClient
    {
        return new AdeptixClient((string) $this->get_option('api_key'));
    }

    /**
     * Finds the order a webhook payload refers to, by the order_ref this plugin sends as the
     * order_ref/order_ref field when creating the payment/payment request (WooCommerce's own order
     * ID) - never trust the transaction/payment_request id alone as an order lookup key, since
     * that's assigned by Adeptix, not by this store.
     */
    protected function adeptix_find_order_by_ref(?string $orderRef): ?WC_Order
    {
        if ($orderRef === null || $orderRef === '') {
            return null;
        }
        $order = wc_get_order((int) $orderRef);
        return $order instanceof WC_Order ? $order : null;
    }

    protected function adeptix_read_raw_webhook_body(): string
    {
        return (string) file_get_contents('php://input');
    }
}
