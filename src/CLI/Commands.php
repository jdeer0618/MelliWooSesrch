<?php
/**
 * WP-CLI command group for MeiliWoo Search.
 *
 * @package MeiliWoo\Search\CLI
 */

declare(strict_types=1);

namespace MeiliWoo\Search\CLI;

use MeiliWoo\Search\Admin\SettingsManager;
use MeiliWoo\Search\Client\MeilisearchClient;
use MeiliWoo\Search\Indexer\DocumentBuilder;
use MeiliWoo\Search\Indexer\Indexer;

/**
 * Manage MeiliWoo Search from the command line.
 *
 * ## EXAMPLES
 *
 *     # Test connection
 *     wp meiliwoo status
 *
 *     # Full reindex
 *     wp meiliwoo index --all
 *
 *     # Connect with new credentials
 *     wp meiliwoo connect --host=http://localhost:7700 --key=masterKey
 *
 * @when after_wp_load
 */
class Commands {

    public function __construct(
        private readonly MeilisearchClient $client,
        private readonly Indexer           $indexer,
        private readonly SettingsManager   $settings
    ) {}

    public static function register(
        MeilisearchClient $client,
        Indexer           $indexer,
        SettingsManager   $settings
    ): void {
        \WP_CLI::add_command( 'meiliwoo', new self( $client, $indexer, $settings ) );
    }

    // ── Commands ───────────────────────────────────────────────────────────

    /**
     * Show connection status and index statistics.
     *
     * ## EXAMPLES
     *
     *     wp meiliwoo status
     *
     * @subcommand status
     */
    public function status(): void {
        $connected = $this->client->is_connected();

        \WP_CLI::line( 'MeiliWoo Search v' . MEILIWOO_VERSION );
        \WP_CLI::line( '─────────────────────────────────' );

        if ( ! $connected ) {
            \WP_CLI::warning( 'Not connected to Meilisearch.' );
            \WP_CLI::line( 'Host: ' . $this->settings->get_host() );
            return;
        }

        $info  = $this->client->get_server_info();
        $stats = $this->client->get_stats();

        \WP_CLI::success( 'Connected to Meilisearch.' );
        \WP_CLI::line( 'Host:       ' . $this->settings->get_host() );
        \WP_CLI::line( 'Version:    ' . ( $info['version']['pkgVersion'] ?? 'unknown' ) );
        \WP_CLI::line( 'Status:     ' . ( $info['status'] ?? 'unknown' ) );
        \WP_CLI::line( 'Index:      ' . $this->settings->get_index_name() );
        \WP_CLI::line( 'Documents:  ' . number_format( $stats['numberOfDocuments'] ?? 0 ) );
        \WP_CLI::line( 'Indexing:   ' . ( ( $stats['isIndexing'] ?? false ) ? 'Yes' : 'No' ) );
    }

    /**
     * Connect to a Meilisearch instance and save credentials.
     *
     * ## OPTIONS
     *
     * [--host=<host>]
     * : Meilisearch host URL (e.g. http://localhost:7700).
     *
     * [--key=<key>]
     * : Master API key.
     *
     * ## EXAMPLES
     *
     *     wp meiliwoo connect --host=http://localhost:7700 --key=masterKey
     *
     * @subcommand connect
     */
    public function connect( array $args, array $assoc_args ): void {
        if ( ! empty( $assoc_args['host'] ) ) {
            $this->settings->set( 'host', sanitize_text_field( $assoc_args['host'] ) );
            \WP_CLI::line( 'Host set to: ' . $assoc_args['host'] );
        }

        if ( ! empty( $assoc_args['key'] ) ) {
            $this->settings->set( 'api_key', sanitize_text_field( $assoc_args['key'] ) );
            \WP_CLI::line( 'API key saved (encrypted).' );
        }

        $this->client->reset_connection();

        if ( $this->client->is_connected() ) {
            $this->client->ensure_index();
            \WP_CLI::success( 'Connected! Index "' . $this->settings->get_index_name() . '" is ready.' );
        } else {
            \WP_CLI::error( 'Could not connect. Check host and API key.' );
        }
    }

    /**
     * Index content into Meilisearch.
     *
     * ## OPTIONS
     *
     * [--all]
     * : Schedule a full reindex of all published content.
     *
     * [--post-id=<id>]
     * : Index a single post/product by ID.
     *
     * [--post-type=<type>]
     * : Limit full reindex to a specific post type.
     *
     * [--sync]
     * : Run indexing synchronously (process batches inline, not via Action Scheduler).
     *
     * ## EXAMPLES
     *
     *     wp meiliwoo index --all
     *     wp meiliwoo index --post-id=123
     *     wp meiliwoo index --all --sync
     *
     * @subcommand index
     */
    public function index( array $args, array $assoc_args ): void {
        if ( ! $this->client->is_connected() ) {
            \WP_CLI::error( 'Not connected to Meilisearch. Run: wp meiliwoo connect' );
        }

        // Single post.
        if ( ! empty( $assoc_args['post-id'] ) ) {
            $post_id = (int) $assoc_args['post-id'];
            $this->indexer->index_post( $post_id );
            \WP_CLI::success( "Post {$post_id} indexed." );
            return;
        }

        // Full reindex.
        if ( isset( $assoc_args['all'] ) ) {
            $sync = isset( $assoc_args['sync'] );

            if ( $sync ) {
                $this->run_sync_reindex( $assoc_args );
            } else {
                $total = $this->indexer->schedule_full_reindex();
                \WP_CLI::success( "Queued {$total} posts for indexing via Action Scheduler." );
                \WP_CLI::line( 'Run `wp action-scheduler run` to process immediately.' );
            }
            return;
        }

        \WP_CLI::error( 'Specify --all or --post-id=<id>.' );
    }

    /**
     * Delete all documents from the index.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp meiliwoo flush --yes
     *
     * @subcommand flush
     */
    public function flush( array $args, array $assoc_args ): void {
        if ( ! $this->client->is_connected() ) {
            \WP_CLI::error( 'Not connected.' );
        }

        \WP_CLI::confirm( 'This will delete ALL documents from the index. Continue?', $assoc_args );

        $this->client->delete_all_documents();
        \WP_CLI::success( 'Index flushed.' );
    }

    /**
     * Search the Meilisearch index directly.
     *
     * ## OPTIONS
     *
     * <query>
     * : The search query.
     *
     * [--limit=<n>]
     * : Number of results (default: 10).
     *
     * [--format=<format>]
     * : Output format: table, json, csv (default: table).
     *
     * ## EXAMPLES
     *
     *     wp meiliwoo search "blue jeans" --limit=5
     *     wp meiliwoo search "shirt" --format=json
     *
     * @subcommand search
     */
    public function search( array $args, array $assoc_args ): void {
        if ( ! $this->client->is_connected() ) {
            \WP_CLI::error( 'Not connected.' );
        }

        $query  = $args[0] ?? '';
        $limit  = (int) ( $assoc_args['limit'] ?? 10 );
        $format = $assoc_args['format'] ?? 'table';

        $result = $this->client->search( $query, [
            'limit'                => $limit,
            'attributesToRetrieve' => [ 'id', 'title', 'type', 'sku', 'price', 'stock_status' ],
        ] );

        $hits = $result['hits'] ?? [];

        \WP_CLI::line( sprintf(
            'Found %d result(s) in %dms.',
            $result['totalHits']        ?? count( $hits ),
            $result['processingTimeMs'] ?? 0
        ) );

        if ( empty( $hits ) ) {
            \WP_CLI::line( 'No results.' );
            return;
        }

        \WP_CLI\Utils\format_items( $format, $hits, [ 'id', 'title', 'type', 'sku', 'price', 'stock_status' ] );
    }

    /**
     * Reset all plugin settings to defaults.
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * @subcommand reset
     */
    public function reset( array $args, array $assoc_args ): void {
        \WP_CLI::confirm( 'This will reset ALL MeiliWoo settings. Continue?', $assoc_args );
        $this->settings->reset_all();
        \WP_CLI::success( 'Settings reset.' );
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function run_sync_reindex( array $assoc_args ): void {
        $post_types = ! empty( $assoc_args['post-type'] )
            ? [ sanitize_key( $assoc_args['post-type'] ) ]
            : $this->indexer->get_indexable_post_types();

        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders})",
                ...$post_types
            )
        );

        $batch_size = $this->settings->get_batch_size();
        $builder    = new DocumentBuilder();
        $processed  = 0;

        $progress = \WP_CLI\Utils\make_progress_bar( "Indexing {$total} posts", $total );

        for ( $offset = 0; $offset < $total; $offset += $batch_size ) {
            $posts = get_posts( [
                'post_type'      => $post_types,
                'post_status'    => 'publish',
                'posts_per_page' => $batch_size,
                'offset'         => $offset,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ] );

            $documents = [];
            foreach ( $posts as $post_id ) {
                $documents = array_merge( $documents, $builder->build( (int) $post_id ) );
                $progress->tick();
                ++$processed;
            }

            if ( ! empty( $documents ) ) {
                $this->client->add_documents( $documents );
            }

            // Avoid memory bloat.
            $this->stop_the_insanity();
        }

        $progress->finish();
        update_option( 'meiliwoo_last_sync', time(), false );
        \WP_CLI::success( "Indexed {$processed} posts." );
    }

    /** Clear object caches to prevent memory exhaustion. */
    private function stop_the_insanity(): void {
        global $wpdb, $wp_object_cache;
        $wpdb->queries = [];
        if ( is_object( $wp_object_cache ) && method_exists( $wp_object_cache, '__remoteset' ) ) {
            $wp_object_cache->__remoteset();
        }
        wp_cache_flush_group( 'posts' );
    }
}
