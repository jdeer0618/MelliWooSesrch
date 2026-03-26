<?php
/**
 * PHPUnit bootstrap for MeiliWoo Search.
 *
 * Loads the WP test suite (for integration tests) and Brain\Monkey
 * (for pure unit tests that mock WP functions).
 */

declare(strict_types=1);

define( 'MEILIWOO_VERSION',  '1.0.0' );
define( 'MEILIWOO_FILE',     dirname( __DIR__ ) . '/meiliwoo-search.php' );
define( 'MEILIWOO_DIR',      dirname( __DIR__ ) . '/' );
define( 'MEILIWOO_URL',      'http://example.com/wp-content/plugins/meiliwoo-search/' );
define( 'MEILIWOO_BASENAME', 'meiliwoo-search/meiliwoo-search.php' );

// ── Composer autoloader ────────────────────────────────────────────────────
$autoloader = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! file_exists( $autoloader ) ) {
    echo "ERROR: Run `composer install` before running tests.\n";
    exit( 1 );
}
require_once $autoloader;

// ── WordPress test suite (integration tests only) ──────────────────────────
$wp_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: dirname( __DIR__ ) . '/tests/wordpress';

if ( file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
    // Load WP test suite.
    require_once $wp_tests_dir . '/includes/functions.php';

    function _manually_load_plugin(): void {
        require dirname( __DIR__ ) . '/meiliwoo-search.php';
    }
    tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

    require_once $wp_tests_dir . '/includes/bootstrap.php';
} else {
    // Unit tests without WP: Brain\Monkey stubs are used instead.
    echo "NOTE: WP test suite not found at {$wp_tests_dir}. Running unit tests only.\n";
}
