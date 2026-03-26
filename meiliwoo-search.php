<?php
/**
 * Plugin Name: MeiliWoo Search
 * Plugin URI:  https://github.com/jdeer0618/melliwoosesrch
 * Description: High-performance, self-hostable Meilisearch-powered search for WordPress and WooCommerce. Drop-in replacement for MySQL search with zero theme changes.
 * Version:     1.0.0
 * Requires at least: 6.8
 * Requires PHP: 8.1
 * Author:      MeiliWoo
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: meiliwoo-search
 * Domain Path: /languages
 * WC requires at least: 10.0.0
 * WC tested up to:     10.6.1
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// ── Constants ──────────────────────────────────────────────────────────────
define('MEILIWOO_VERSION',  '1.0.0');
define('MEILIWOO_FILE',     __FILE__);
define('MEILIWOO_DIR',      plugin_dir_path(__FILE__));
define('MEILIWOO_URL',      plugin_dir_url(__FILE__));
define('MEILIWOO_BASENAME', plugin_basename(__FILE__));

const MEILIWOO_MIN_PHP = '8.1';
const MEILIWOO_MIN_WP  = '6.8';

// ── Requirement checks ─────────────────────────────────────────────────────
function meiliwoo_check_requirements(): bool {
    if ( version_compare( PHP_VERSION, MEILIWOO_MIN_PHP, '<' ) ) {
        add_action( 'admin_notices', static function () {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html( sprintf(
                    /* translators: 1: required PHP version 2: current PHP version */
                    __( 'MeiliWoo Search requires PHP %1$s or higher. You are running PHP %2$s.', 'meiliwoo-search' ),
                    MEILIWOO_MIN_PHP,
                    PHP_VERSION
                ) )
            );
        } );
        return false;
    }

    global $wp_version;
    if ( version_compare( $wp_version, MEILIWOO_MIN_WP, '<' ) ) {
        add_action( 'admin_notices', static function () use ( $wp_version ) {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html( sprintf(
                    /* translators: 1: required WP version 2: current WP version */
                    __( 'MeiliWoo Search requires WordPress %1$s or higher. You are running %2$s.', 'meiliwoo-search' ),
                    MEILIWOO_MIN_WP,
                    $wp_version
                ) )
            );
        } );
        return false;
    }

    return true;
}

// ── Bootstrap ──────────────────────────────────────────────────────────────
function meiliwoo_bootstrap(): void {
    $autoloader = MEILIWOO_DIR . 'vendor/autoload.php';

    if ( ! file_exists( $autoloader ) ) {
        add_action( 'admin_notices', static function () {
            echo '<div class="notice notice-error"><p>' .
                esc_html__( 'MeiliWoo Search: Composer dependencies missing. Run `composer install` in the plugin directory.', 'meiliwoo-search' ) .
                '</p></div>';
        } );
        return;
    }

    require_once $autoloader;
    MeiliWoo\Search\Plugin::instance();
}

// ── Register hooks ─────────────────────────────────────────────────────────
if ( meiliwoo_check_requirements() ) {
    add_action( 'plugins_loaded', 'meiliwoo_bootstrap', 5 );
}

register_activation_hook( __FILE__, static function () {
    $autoloader = MEILIWOO_DIR . 'vendor/autoload.php';
    if ( file_exists( $autoloader ) ) {
        require_once $autoloader;
        MeiliWoo\Search\Plugin::activate();
    }
} );

register_deactivation_hook( __FILE__, static function () {
    $autoloader = MEILIWOO_DIR . 'vendor/autoload.php';
    if ( file_exists( $autoloader ) ) {
        require_once $autoloader;
        MeiliWoo\Search\Plugin::deactivate();
    }
} );
