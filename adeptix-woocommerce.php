<?php
/**
 * Plugin Name: Adeptix Payment Gateway for WooCommerce
 * Plugin URI: https://docs.adeptix.app
 * Description: Accept direct on-chain crypto payments (USDT/USDC) and hosted card/bank/wallet checkouts through Adeptix.
 * Version: 0.1.0
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * Author: Adeptix
 * Author URI: https://adeptix.app
 * License: MIT
 * Text Domain: adeptix-woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ADEPTIX_WC_PLUGIN_FILE', __FILE__);
define('ADEPTIX_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ADEPTIX_WC_VERSION', '0.1.0');

add_action('plugins_loaded', 'adeptix_wc_init', 11);

function adeptix_wc_init(): void
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'adeptix_wc_missing_woocommerce_notice');
        return;
    }

    require_once ADEPTIX_WC_PLUGIN_DIR . 'vendor/autoload.php';
    require_once ADEPTIX_WC_PLUGIN_DIR . 'includes/class-wc-gateway-adeptix.php';
    require_once ADEPTIX_WC_PLUGIN_DIR . 'includes/class-wc-gateway-adeptix-crypto.php';

    add_filter('woocommerce_payment_gateways', function (array $gateways): array {
        $gateways[] = 'WC_Gateway_Adeptix';
        $gateways[] = 'WC_Gateway_Adeptix_Crypto';
        return $gateways;
    });
}

function adeptix_wc_missing_woocommerce_notice(): void
{
    echo '<div class="notice notice-error"><p>'
        . esc_html__('Adeptix Payment Gateway requires WooCommerce to be installed and active.', 'adeptix-woocommerce')
        . '</p></div>';
}
