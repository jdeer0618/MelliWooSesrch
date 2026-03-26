<?php
/**
 * Main plugin bootstrap / service locator.
 *
 * @package MeiliWoo\Search
 */

declare(strict_types=1);

namespace MeiliWoo\Search;

use MeiliWoo\Search\Admin\Admin;
use MeiliWoo\Search\Admin\SettingsManager;
use MeiliWoo\Search\Api\IndexController;
use MeiliWoo\Search\Api\SearchController;
use MeiliWoo\Search\CLI\Commands;
use MeiliWoo\Search\Client\MeilisearchClient;
use MeiliWoo\Search\Indexer\BatchIndexer;
use MeiliWoo\Search\Indexer\Indexer;
use MeiliWoo\Search\Query\QueryInterceptor;

/**
 * Core plugin class (singleton).
 */
final class Plugin {

    private static ?self $instance = null;

    private SettingsManager $settings;
    private MeilisearchClient $client;
    private Indexer $indexer;
    private QueryInterceptor $query_interceptor;

    // ── Singleton ──────────────────────────────────────────────────────────

    private function __construct() {
        $this->settings          = new SettingsManager();
        $this->client            = new MeilisearchClient( $this->settings );
        $this->indexer           = new Indexer( $this->client, $this->settings );
        $this->query_interceptor = new QueryInterceptor( $this->client, $this->settings );

        $this->load_textdomain();
        $this->register_hooks();
    }

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ── Activation / Deactivation ──────────────────────────────────────────

    public static function activate(): void {
        SettingsManager::install();

        // Schedule Action Scheduler housekeeping.
        if ( ! as_next_scheduled_action( 'meiliwoo_cleanup_logs' ) ) {
            as_schedule_recurring_action(
                time(),
                DAY_IN_SECONDS,
                'meiliwoo_cleanup_logs',
                [],
                'meiliwoo'
            );
        }
    }

    public static function deactivate(): void {
        as_unschedule_all_actions( 'meiliwoo_batch_index', [], 'meiliwoo' );
        as_unschedule_all_actions( 'meiliwoo_index_single', [], 'meiliwoo' );
        as_unschedule_all_actions( 'meiliwoo_cleanup_logs', [], 'meiliwoo' );
    }

    // ── Bootstrap ──────────────────────────────────────────────────────────

    private function load_textdomain(): void {
        load_plugin_textdomain(
            'meiliwoo-search',
            false,
            dirname( MEILIWOO_BASENAME ) . '/languages'
        );
    }

    private function register_hooks(): void {
        // Declare HPOS compatibility.
        add_action( 'before_woocommerce_init', [ $this, 'declare_hpos_compat' ] );

        // Indexer real-time hooks.
        $this->indexer->register_hooks();

        // Query interception.
        $this->query_interceptor->register_hooks();

        // Action Scheduler actions.
        add_action( 'meiliwoo_batch_index',   [ BatchIndexer::class, 'run' ] );
        add_action( 'meiliwoo_index_single',  [ $this->indexer, 'index_post' ] );
        add_action( 'meiliwoo_cleanup_logs',  [ $this, 'cleanup_logs' ] );

        // REST API.
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

        // Admin.
        if ( is_admin() ) {
            Admin::instance( $this->client, $this->settings, $this->indexer );
        }

        // WP-CLI.
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            Commands::register( $this->client, $this->indexer, $this->settings );
        }
    }

    // ── REST routes ────────────────────────────────────────────────────────

    public function register_rest_routes(): void {
        ( new SearchController( $this->client, $this->settings ) )->register_routes();
        ( new IndexController( $this->client, $this->indexer, $this->settings ) )->register_routes();
    }

    // ── Compatibility ──────────────────────────────────────────────────────

    public function declare_hpos_compat(): void {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                MEILIWOO_FILE,
                true
            );
        }
    }

    // ── Maintenance ────────────────────────────────────────────────────────

    public function cleanup_logs(): void {
        global $wpdb;
        // Keep last 1000 log rows.
        $table = $wpdb->prefix . 'meiliwoo_logs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE id NOT IN (
                    SELECT id FROM (
                        SELECT id FROM {$table} ORDER BY created_at DESC LIMIT %d
                    ) AS keep
                )",
                1000
            )
        );
    }

    // ── Accessors (for tests) ──────────────────────────────────────────────

    public function get_client(): MeilisearchClient {
        return $this->client;
    }

    public function get_indexer(): Indexer {
        return $this->indexer;
    }

    public function get_settings(): SettingsManager {
        return $this->settings;
    }
}
