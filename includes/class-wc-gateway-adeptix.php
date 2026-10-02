<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/trait-adeptix-gateway-common.php';

use Adeptix\Exceptions\AdeptixApiException;
use Adeptix\Webhooks;

/**
 * Merchant Payments API - hosted card/bank/wallet checkout. See https://docs.adeptix.app/merchant-payments
 */
class WC_Gateway_Adeptix extends WC_Payment_Gateway
{
    use Adeptix_Gateway_Common;

    public function __construct()
    {
        $this->id = 'adeptix';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = __('Adeptix', 'adeptix-woocommerce');
        $this->method_description = __('Hosted card, bank, and wallet checkout via Adeptix.', 'adeptix-woocommerce');
        $this->supports = ['products'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_api_' . $this->id, [$this, 'handle_webhook']);
    }

    public function init_form_fields(): void
    {
        $this->form_fields = array_merge($this->adeptix_common_form_fields(), [
            'provider' => [
                'title' => __('Provider ID', 'adeptix-woocommerce'),
                'type' => 'text',
                'description' => __('An enabled provider id from your Adeptix dashboard\'s Providers page, e.g. "stripe".', 'adeptix-woocommerce'),
                'default' => 'stripe',
                'desc_tip' => true,
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
            $payment = $this->adeptix_client()->payments()->create([
                'amount' => (string) $order->get_total(),
                'currency' => $order->get_currency(),
                'email' => $order->get_billing_email(),
                'provider' => (string) $this->get_option('provider'),
                'order_ref' => (string) $order->get_id(),
            ]);
        } catch (AdeptixApiException $e) {
            wc_add_notice(
                sprintf(
                    /* translators: %s: error message from the Adeptix API */
                    __('Adeptix checkout could not be started: %s', 'adeptix-woocommerce'),
                    $e->getMessage()
                ),
                'error'
            );
            return ['result' => 'fail'];
        }

        $order->update_meta_data('_adeptix_transaction_id', $payment['transaction_id']);
        $order->set_status('on-hold', __('Awaiting Adeptix checkout completion.', 'adeptix-woocommerce'));
        $order->save();

        return [
            'result' => 'success',
            'redirect' => $payment['payment_url'],
        ];
    }

    /**
     * WooCommerce routes requests to https://yourstore.com/wc-api/adeptix here (registered via
     * the woocommerce_api_{id} action in the constructor). See https://docs.adeptix.app/webhooks
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
        if (!is_array($event) || ($event['event'] ?? null) !== 'payment.paid') {
            status_header(200); // acknowledge, nothing to do
            exit;
        }

        // A live store has no notion of "test mode" of its own - a webhook carrying test_mode is
        // never meant for it. Skipping it here (rather than trusting the event name alone) is what
        // stops a sandbox API key's test payment from marking a real order paid when its order_ref
        // happens to match one, since both rails deliver to this same URL regardless of mode.
        if (!empty($event['test_mode'])) {
            status_header(200);
            exit;
        }

        $order = $this->adeptix_find_order_by_ref($event['order_ref'] ?? null);
        if ($order === null) {
            status_header(200); // unknown order - already deleted, or not ours; ack anyway
            exit;
        }

        if (!$order->is_paid()) {
            $order->payment_complete((string) ($event['transaction_id'] ?? ''));
        }

        status_header(200);
        exit;
    }
}
