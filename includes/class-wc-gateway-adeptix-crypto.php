<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/trait-adeptix-gateway-common.php';

use Adeptix\Exceptions\AdeptixApiException;
use Adeptix\Webhooks;

/**
 * Crypto Payments API - unique, exact-amount on-chain deposits. See https://docs.adeptix.app/crypto-payments
 */
class WC_Gateway_Adeptix_Crypto extends WC_Payment_Gateway
{
    use Adeptix_Gateway_Common;

    public function __construct()
    {
        $this->id = 'adeptix_crypto';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = __('Adeptix Crypto', 'adeptix-woocommerce');
        $this->method_description = __('Direct on-chain USDT/USDC payment via Adeptix.', 'adeptix-woocommerce');
        $this->supports = ['products'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_api_' . $this->id, [$this, 'handle_webhook']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'render_payment_instructions']);
    }

    public function init_form_fields(): void
    {
        $this->form_fields = array_merge($this->adeptix_common_form_fields(), [
            'chain' => [
                'title' => __('Chain', 'adeptix-woocommerce'),
                'type' => 'select',
                'options' => ['bsc' => 'BSC', 'polygon' => 'Polygon', 'tron' => 'Tron'],
                'default' => 'polygon',
            ],
            'token' => [
                'title' => __('Token', 'adeptix-woocommerce'),
                'type' => 'select',
                'options' => ['USDT' => 'USDT', 'USDC' => 'USDC'],
                'default' => 'USDC',
            ],
        ]);
    }

    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            wc_add_notice(__('Adeptix: could not load this order.', 'adeptix-woocommerce'), 'error');
            return ['result' => 'fail'];
        }

        try {
            $request = $this->adeptix_client()->crypto()->createPaymentRequest([
                'chain' => (string) $this->get_option('chain'),
                'token' => (string) $this->get_option('token'),
                // WooCommerce order totals are in the store's fiat currency, not the crypto asset -
                // a real store needs its own fiat->crypto conversion upstream of this call. Passed
                // through as-is here since that conversion is store-specific, not this plugin's job.
                'amount' => (string) $order->get_total(),
                'order_ref' => (string) $order->get_id(),
                'customer_email' => $order->get_billing_email(),
            ]);
        } catch (AdeptixApiException $e) {
            wc_add_notice(
                sprintf(
                    /* translators: %s: error message from the Adeptix API */
                    __('Adeptix crypto payment could not be started: %s', 'adeptix-woocommerce'),
                    $e->getMessage()
                ),
                'error'
            );
            return ['result' => 'fail'];
        }

        $order->update_meta_data('_adeptix_payment_request_id', $request['payment_request_id']);
        $order->update_meta_data('_adeptix_pay_to_address', $request['pay_to_address']);
        $order->update_meta_data('_adeptix_amount', $request['amount']);
        $order->update_meta_data('_adeptix_chain', $request['chain']);
        $order->update_meta_data('_adeptix_token', $request['token']);
        $order->update_meta_data('_adeptix_expires_at', $request['expires_at']);
        $order->set_status('on-hold', __('Awaiting crypto deposit.', 'adeptix-woocommerce'));
        $order->save();

        return [
            'result' => 'success',
            'redirect' => $this->get_return_url($order),
        ];
    }

    /** Shown on the order-received ("thank you") page - the customer's only chance to see the deposit address. */
    public function render_payment_instructions(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order || $order->get_payment_method() !== $this->id) {
            return;
        }

        $address = $order->get_meta('_adeptix_pay_to_address');
        $amount = $order->get_meta('_adeptix_amount');
        $token = $order->get_meta('_adeptix_token');
        $chain = $order->get_meta('_adeptix_chain');
        $expiresAt = $order->get_meta('_adeptix_expires_at');

        if ($address === '') {
            return;
        }

        echo '<section class="adeptix-crypto-instructions">';
        echo '<h2>' . esc_html__('Send your payment', 'adeptix-woocommerce') . '</h2>';
        echo '<p>' . sprintf(
            /* translators: 1: amount, 2: token, 3: chain */
            esc_html__('Send exactly %1$s %2$s on %3$s to:', 'adeptix-woocommerce'),
            esc_html($amount),
            esc_html($token),
            esc_html($chain)
        ) . '</p>';
        echo '<p><code>' . esc_html($address) . '</code></p>';
        echo '<p>' . sprintf(
            /* translators: %s: expiry timestamp */
            esc_html__('This address expires at %s. Your order will update automatically once the deposit is confirmed.', 'adeptix-woocommerce'),
            esc_html($expiresAt)
        ) . '</p>';
        echo '</section>';
    }

    /**
     * WooCommerce routes requests to https://yourstore.com/wc-api/adeptix_crypto here. See
     * https://docs.adeptix.app/webhooks
     */
    public function handle_webhook(): void
    {
        $rawBody = $this->adeptix_read_raw_webhook_body();
        $signature = $_SERVER['HTTP_X_ADEPTIX_SIGNATURE'] ?? null;
        $secret = (string) $this->get_option('webhook_secret');

        if (!Webhooks::verifySignature($rawBody, $signature, $secret)) {
            status_header(401);
            exit;
        }

        $event = json_decode($rawBody, true);
        if (!is_array($event) || ($event['event'] ?? null) !== 'crypto_payment.matched') {
            status_header(200);
            exit;
        }

        // See class-wc-gateway-adeptix.php's handle_webhook for why test_mode is checked here too.
        if (!empty($event['test_mode'])) {
            status_header(200);
            exit;
        }

        $order = $this->adeptix_find_order_by_ref($event['order_ref'] ?? null);
        if ($order === null) {
            status_header(200);
            exit;
        }

        if (!$order->is_paid()) {
            $order->payment_complete((string) ($event['tx_hash'] ?? ''));
        }

        status_header(200);
        exit;
    }
}
