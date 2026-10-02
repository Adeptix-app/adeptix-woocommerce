# Adeptix Payment Gateway for WooCommerce

Accept direct on-chain crypto payments (USDT/USDC) and hosted card/bank/wallet checkouts through
[Adeptix](https://adeptix.app), from one WooCommerce plugin.

Full API reference: **https://docs.adeptix.app**

## What this adds

Two independent WooCommerce payment gateways, each toggled on/off separately under
**WooCommerce → Settings → Payments**:

- **Adeptix** — hosted checkout (card, bank transfer, PayPal-style wallets) via whichever provider
  you configure (e.g. `stripe`). Redirects the customer to a hosted checkout page; the order is
  marked paid automatically when Adeptix's webhook confirms payment.
- **Adeptix Crypto** — direct on-chain USDT/USDC payment on BSC, Polygon, or Tron. Shows the
  customer a deposit address and exact amount on the order-received page; the order is marked paid
  automatically once the deposit is confirmed on-chain.

## Install

1. Upload this plugin's folder to `wp-content/plugins/adeptix-woocommerce`, or zip it and upload
   via **Plugins → Add New → Upload Plugin**.
2. Activate it.
3. Go to **WooCommerce → Settings → Payments** and configure each gateway you want to use:
   - **Adeptix API key** — from your Adeptix dashboard's API Keys page.
   - **Webhook secret** — from your Adeptix dashboard's Settings page. Each gateway's settings
     screen shows you the exact webhook URL to register there (`wc-api/adeptix` and
     `wc-api/adeptix_crypto` respectively).
   - Gateway-specific fields: the provider id (Adeptix gateway) or chain/token (Adeptix Crypto
     gateway).

## How it works

Both gateways build on the [official Adeptix PHP SDK](https://github.com/Adeptix-app/adeptix-php)
(bundled in `vendor/`, not a separate install step) — see `includes/class-wc-gateway-adeptix.php`
and `includes/class-wc-gateway-adeptix-crypto.php`. Order matching uses the WooCommerce order ID as
the `order_ref` sent to Adeptix, so a webhook or status check can always find its way back to the
right order (`includes/trait-adeptix-gateway-common.php`).

## Requirements

- PHP 8.0+
- WordPress with WooCommerce 8.0+ active

## Verified

Activated and exercised against a real local WordPress + WooCommerce + MariaDB install: both
gateways register correctly, `process_payment()` makes a real request to the production Adeptix API
and correctly surfaces an error as a WooCommerce checkout notice, and the webhook endpoint correctly
verifies a real HMAC-signed payload (accepts valid, rejects invalid with 401) and marks a real
`WC_Order` paid via `payment_complete()`.

Not yet published to the WordPress.org plugin directory — this is the source, not a packaged release.

## License

MIT
