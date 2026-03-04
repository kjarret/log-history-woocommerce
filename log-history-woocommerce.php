<?php
/**
 * Plugin Name:       Log History for WooCommerce Products
 * Plugin URI:        https://github.com/windeshausen/log-history-woocommerce
 * Description:       Affiche les logs Simple History directement sur la page de modification d'un produit WooCommerce.
 * Version:           1.0.0
 * Author:            Agacom
 * Text Domain:       lhwc
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 */

defined('ABSPATH') || exit;

define('LHWC_VERSION', '1.0.0');
define('LHWC_PLUGIN_FILE', __FILE__);
define('LHWC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LHWC_PLUGIN_URL', plugin_dir_url(__FILE__));

add_action('plugins_loaded', function () {

    // Dépendance : Simple History.
    $sh_active = class_exists('\Simple_History\Simple_History')
        || class_exists('SimpleHistory');

    if (!$sh_active) {
        add_action('admin_notices', function () {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html__('Log History for WooCommerce nécessite le plugin Simple History.', 'lhwc')
            );
        });
        return;
    }

    // Dépendance : WooCommerce.
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html__('Log History for WooCommerce nécessite WooCommerce.', 'lhwc')
            );
        });
        return;
    }

    require_once LHWC_PLUGIN_DIR . 'includes/class-product-log-metabox.php';
    \LHWC\Product_Log_Metabox::init();

    // Enregistrer le logger ACF si ACF est actif.
    // Le hook simple_history/add_custom_logger est déclenché par Simple History
    // pendant son initialisation, donc on peut s'y accrocher dès ici.
    add_action('simple_history/add_custom_logger', function ($simple_history) {
        if (function_exists('acf_get_field')) {
            require_once LHWC_PLUGIN_DIR . 'includes/class-acf-logger.php';
            $simple_history->register_logger(\LHWC\ACF_Logger::class);
        }
    });
});
