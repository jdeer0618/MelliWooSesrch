<?php
/**
 * REST API controller for admin index operations (test connection, reindex, stats).
 *
 * @package MeiliWoo\Search\Api
 */

declare(strict_types=1);

namespace MeiliWoo\Search\Api;

use MeiliWoo\Search\Admin\SettingsManager;
use MeiliWoo\Search\Client\MeilisearchClient;
use MeiliWoo\Search\Indexer\Indexer;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Admin-only REST endpoints for managing the index.
 *
 * Base: /wp-json/meiliwoo/v1/index/
 */
class IndexController extends WP_REST_Controller {

    protected $namespace = 'meiliwoo/v1';
    protected $rest_base = 'index';

    public function __construct(
        private readonly MeilisearchClient $client,
        private readonly Indexer           $indexer,
        private readonly SettingsManager   $settings
    ) {}

    public function register_routes(): void {
        // GET /index/status – connection + stats.
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/status', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'status' ],
            'permission_callback' => [ $this, 'admin_permission' ],
        ] );

        // POST /index/reindex – schedule full reindex.
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/reindex', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'reindex' ],
            'permission_callback' => [ $this, 'admin_permission' ],
        ] );

        // POST /index/connect – test connection with optionally new credentials.
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/connect', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'test_connection' ],
            'permission_callback' => [ $this, 'admin_permission' ],
            'args'                => [
                'host'    => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'api_key' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        // DELETE /index/document/{id} – remove a single document.
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/document/(?P<id>[a-zA-Z0-9_-]+)', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [ $this, 'delete_document' ],
            'permission_callback' => [ $this, 'admin_permission' ],
        ] );

        // GET /index/progress – Action Scheduler queue status.
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/progress', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'progress' ],
            'permission_callback' => [ $this, 'admin_permission' ],
        ] );
    }

    // ── Handlers ───────────────────────────────────────────────────────────

    public function status( WP_REST_Request $request ): WP_REST_Response {
        $connected = $this->client->is_connected();
        return new WP_REST_Response( [
            'connected'   => $connected,
            'server_info' => $connected ? $this->client->get_server_info() : null,
            'stats'       => $connected ? $this->client->get_stats()       : null,
            'index_name'  => $this->settings->get_index_name(),
            'fallback'    => $this->settings->fallback_enabled(),
        ] );
    }

    public function reindex( WP_REST_Request $request ): WP_REST_Response {
        if ( ! $this->client->is_connected() ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Not connected.' ], 503 );
        }
        $total = $this->indexer->schedule_full_reindex();
        update_option( 'meiliwoo_last_sync', time(), false );
        return new WP_REST_Response( [
            'success' => true,
            'queued'  => $total,
            'message' => sprintf( '%d posts queued for indexing.', $total ),
        ] );
    }

    public function test_connection( WP_REST_Request $request ): WP_REST_Response {
        // Temporarily override settings if credentials provided in request.
        $host    = $request->get_param( 'host' );
        $api_key = $request->get_param( 'api_key' );

        if ( $host ) {
            $this->settings->set( 'host', $host );
        }
        if ( $api_key ) {
            $this->settings->set( 'api_key', $api_key );
        }

        $this->client->reset_connection();
        $connected = $this->client->is_connected();

        if ( $connected ) {
            $this->client->ensure_index();
        }

        return new WP_REST_Response( [
            'success'     => $connected,
            'server_info' => $connected ? $this->client->get_server_info() : null,
            'message'     => $connected ? 'Connection successful.' : 'Could not connect. Check host and API key.',
        ], $connected ? 200 : 503 );
    }

    public function delete_document( WP_REST_Request $request ): WP_REST_Response {
        $id     = sanitize_text_field( $request->get_param( 'id' ) );
        $result = $this->client->delete_document( $id );
        return new WP_REST_Response( [
            'success' => null !== $result,
            'task'    => $result,
        ] );
    }

    public function progress( WP_REST_Request $request ): WP_REST_Response {
        $pending = as_get_scheduled_actions( [
            'hook'     => 'meiliwoo_batch_index',
            'status'   => \ActionScheduler_Store::STATUS_PENDING,
            'per_page' => -1,
        ] );

        $running = as_get_scheduled_actions( [
            'hook'     => 'meiliwoo_batch_index',
            'status'   => \ActionScheduler_Store::STATUS_RUNNING,
            'per_page' => -1,
        ] );

        $stats = $this->client->is_connected() ? $this->client->get_stats() : null;

        return new WP_REST_Response( [
            'pending'   => count( $pending ),
            'running'   => count( $running ),
            'documents' => $stats['numberOfDocuments'] ?? 0,
            'indexing'  => $stats['isIndexing']        ?? false,
        ] );
    }

    // ── Permissions ────────────────────────────────────────────────────────

    public function admin_permission(): bool {
        return current_user_can( 'manage_options' );
    }
}
