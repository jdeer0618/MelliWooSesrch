<?php
/**
 * Admin panel bootstrap and menu registration.
 *
 * @package MeiliWoo\Search\Admin
 */

declare(strict_types=1);

namespace MeiliWoo\Search\Admin;

use MeiliWoo\Search\Admin\Pages\ConnectionPage;
use MeiliWoo\Search\Admin\Pages\FacetsPage;
use MeiliWoo\Search\Admin\Pages\IndexingPage;
use MeiliWoo\Search\Admin\Pages\LogsPage;
use MeiliWoo\Search\Admin\Pages\SearchSettingsPage;
use MeiliWoo\Search\Client\MeilisearchClient;
use MeiliWoo\Search\Indexer\Indexer;

/**
 * Registers the admin menu, enqueues assets, and delegates to page classes.
 */
class Admin {

    private static ?self $instance = null;

    private array $pages = [];

    private function __construct(
        private readonly MeilisearchClient $client,
        private readonly SettingsManager   $settings,
        private readonly Indexer           $indexer
    ) {
        add_action( 'admin_menu',             [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init',             [ $this, 'handle_ajax' ] );
        add_action( 'admin_notices',          [ $this, 'maybe_show_setup_notice' ] );
    }

    public static function instance(
        MeilisearchClient $client,
        SettingsManager   $settings,
        Indexer           $indexer
    ): self {
        if ( null === self::$instance ) {
            self::$instance = new self( $client, $settings, $indexer );
        }
        return self::$instance;
    }

    // ── Menu ───────────────────────────────────────────────────────────────

    public function register_menus(): void {
        $parent_slug = 'meiliwoo-search';

        // Top-level menu (or sub-menu under WooCommerce if active).
        if ( class_exists( 'WooCommerce' ) ) {
            add_submenu_page(
                'woocommerce',
                __( 'MeiliWoo Search', 'meiliwoo-search' ),
                __( 'MeiliWoo Search', 'meiliwoo-search' ),
                'manage_options',
                $parent_slug,
                [ $this, 'render_page' ]
            );
        } else {
            add_menu_page(
                __( 'MeiliWoo Search', 'meiliwoo-search' ),
                __( 'MeiliWoo Search', 'meiliwoo-search' ),
                'manage_options',
                $parent_slug,
                [ $this, 'render_page' ],
                'dashicons-search',
                58
            );
        }

        // Register page handlers.
        $this->pages = [
            'connection'     => new ConnectionPage( $this->client, $this->settings ),
            'indexing'       => new IndexingPage( $this->client, $this->settings, $this->indexer ),
            'search'         => new SearchSettingsPage( $this->client, $this->settings ),
            'facets'         => new FacetsPage( $this->client, $this->settings ),
            'logs'           => new LogsPage( $this->client ),
        ];
    }

    // ── Render ─────────────────────────────────────────────────────────────

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'meiliwoo-search' ) );
        }

        $tab     = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'connection'; // phpcs:ignore WordPress.Security.NonceVerification
        $page    = $this->pages[ $tab ] ?? $this->pages['connection'];

        // Handle form submission.
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['meiliwoo_nonce'] ) ) {
            if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meiliwoo_nonce'] ) ), 'meiliwoo_save_settings' ) ) {
                $page->handle_post( $_POST );
            }
        }

        $tabs = [
            'connection' => __( 'Connection', 'meiliwoo-search' ),
            'indexing'   => __( 'Indexing', 'meiliwoo-search' ),
            'search'     => __( 'Search Settings', 'meiliwoo-search' ),
            'facets'     => __( 'Facets & Widgets', 'meiliwoo-search' ),
            'logs'       => __( 'Logs & Test', 'meiliwoo-search' ),
        ];

        echo '<div class="wrap meiliwoo-wrap">';
        echo '<h1>' . esc_html__( 'MeiliWoo Search', 'meiliwoo-search' ) . '</h1>';
        echo $this->render_tabs( $tabs, $tab ); // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<div class="meiliwoo-tab-content">';
        $page->render();
        echo '</div>';
        echo '</div>';
    }

    private function render_tabs( array $tabs, string $current ): string {
        $base_url = admin_url( 'admin.php?page=meiliwoo-search&tab=' );
        $html     = '<nav class="nav-tab-wrapper meiliwoo-tabs">';
        foreach ( $tabs as $slug => $label ) {
            $class  = ( $slug === $current ) ? 'nav-tab nav-tab-active' : 'nav-tab';
            $html  .= sprintf(
                '<a href="%s" class="%s">%s</a>',
                esc_url( $base_url . $slug ),
                esc_attr( $class ),
                esc_html( $label )
            );
        }
        $html .= '</nav>';
        return $html;
    }

    // ── Assets ─────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        if ( ! $this->is_plugin_page( $hook ) ) {
            return;
        }

        wp_enqueue_style(
            'meiliwoo-admin',
            MEILIWOO_URL . 'assets/css/admin.css',
            [],
            MEILIWOO_VERSION
        );

        wp_enqueue_script(
            'meiliwoo-admin',
            MEILIWOO_URL . 'assets/js/admin.js',
            [ 'jquery', 'wp-api-fetch', 'wp-i18n' ],
            MEILIWOO_VERSION,
            true
        );

        wp_localize_script( 'meiliwoo-admin', 'meiliwooAdmin', [
            'restUrl'   => esc_url_raw( rest_url( 'meiliwoo/v1/' ) ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'connected' => $this->client->is_connected(),
            'i18n'      => [
                'connecting'       => __( 'Testing connection…', 'meiliwoo-search' ),
                'connected'        => __( 'Connected', 'meiliwoo-search' ),
                'disconnected'     => __( 'Disconnected', 'meiliwoo-search' ),
                'indexing'         => __( 'Indexing…', 'meiliwoo-search' ),
                'indexComplete'    => __( 'Index complete!', 'meiliwoo-search' ),
                'confirmReindex'   => __( 'This will reindex all content. Continue?', 'meiliwoo-search' ),
            ],
        ] );

        wp_set_script_translations( 'meiliwoo-admin', 'meiliwoo-search', MEILIWOO_DIR . 'languages' );
    }

    // ── AJAX ───────────────────────────────────────────────────────────────

    public function handle_ajax(): void {
        // Delegate to REST API – admin AJAX is handled via /wp-json/meiliwoo/v1/*.
    }

    // ── Setup notice ───────────────────────────────────────────────────────

    public function maybe_show_setup_notice(): void {
        if ( $this->client->is_connected() ) {
            return;
        }
        $screen = get_current_screen();
        if ( $screen && str_contains( $screen->id, 'meiliwoo' ) ) {
            return; // Already on our settings page.
        }
        printf(
            '<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__( 'MeiliWoo Search is not connected to Meilisearch.', 'meiliwoo-search' ),
            esc_url( admin_url( 'admin.php?page=meiliwoo-search&tab=connection' ) ),
            esc_html__( 'Configure now →', 'meiliwoo-search' )
        );
    }

    private function is_plugin_page( string $hook ): bool {
        return str_contains( $hook, 'meiliwoo' ) || str_contains( $hook, 'woocommerce_page_meiliwoo' );
    }
}
