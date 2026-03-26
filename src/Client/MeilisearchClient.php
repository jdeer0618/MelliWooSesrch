<?php
/**
 * Meilisearch client wrapper.
 *
 * @package MeiliWoo\Search\Client
 */

declare(strict_types=1);

namespace MeiliWoo\Search\Client;

use Meilisearch\Client;
use Meilisearch\Contracts\IndexesQuery;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\CommunicationException;
use MeiliWoo\Search\Admin\SettingsManager;

/**
 * Thin wrapper around the Meilisearch PHP SDK.
 *
 * Handles connection management, error logging, and fallback detection.
 */
class MeilisearchClient {

    private ?Client $sdk    = null;
    private ?bool $connected = null;

    public function __construct( private readonly SettingsManager $settings ) {}

    // ── SDK instance ───────────────────────────────────────────────────────

    private function sdk(): Client {
        if ( null === $this->sdk ) {
            $this->sdk = new Client(
                $this->settings->get_host(),
                $this->settings->get_api_key()
            );
        }
        return $this->sdk;
    }

    // ── Connection ─────────────────────────────────────────────────────────

    /**
     * Returns true if Meilisearch is reachable.
     * Result is cached for the duration of the request.
     */
    public function is_connected(): bool {
        if ( null !== $this->connected ) {
            return $this->connected;
        }

        // Check transient first (30-second TTL).
        $cached = get_transient( 'meiliwoo_connected' );
        if ( false !== $cached ) {
            $this->connected = (bool) $cached;
            return $this->connected;
        }

        try {
            $this->sdk()->health();
            $this->connected = true;
            set_transient( 'meiliwoo_connected', 1, 30 );
        } catch ( \Throwable $e ) {
            $this->connected = false;
            set_transient( 'meiliwoo_connected', 0, 30 );
            $this->log_error( 'health_check', $e->getMessage() );
        }

        return $this->connected;
    }

    /** Force-clear the connection cache (used after settings save). */
    public function reset_connection(): void {
        $this->sdk       = null;
        $this->connected = null;
        delete_transient( 'meiliwoo_connected' );
    }

    // ── Index access ───────────────────────────────────────────────────────

    /**
     * Get (or lazily create) the plugin's Meilisearch index.
     */
    public function get_index(): \Meilisearch\Endpoints\Indexes {
        return $this->sdk()->index( $this->settings->get_index_name() );
    }

    /**
     * Ensure the index exists with correct settings.
     * Called on first connect and on settings save.
     */
    public function ensure_index(): bool {
        try {
            $index_name = $this->settings->get_index_name();

            // Create index if it doesn't exist.
            try {
                $this->sdk()->getIndex( $index_name );
            } catch ( ApiException $e ) {
                if ( 404 === $e->httpStatus ) {
                    $task = $this->sdk()->createIndex( $index_name, [ 'primaryKey' => 'id' ] );
                    $this->sdk()->waitForTask( $task['taskUid'] );
                } else {
                    throw $e;
                }
            }

            // Apply index settings.
            $this->apply_index_settings();
            return true;
        } catch ( \Throwable $e ) {
            $this->log_error( 'ensure_index', $e->getMessage() );
            return false;
        }
    }

    public function apply_index_settings(): void {
        $index = $this->get_index();

        // Filterable attributes (facets).
        $filterable = array_unique( array_merge(
            [ 'type', 'stock_status', 'categories', 'tags', 'parent_id' ],
            $this->settings->get_facetable_attributes()
        ) );

        // Sortable attributes.
        $sortable = $this->settings->get_sortable_attributes();

        // Searchable fields.
        $searchable = $this->settings->get_searchable_fields();

        $task = $index->updateSettings( [
            'filterableAttributes' => $filterable,
            'sortableAttributes'   => $sortable,
            'searchableAttributes' => $searchable,
            'rankingRules'         => $this->settings->get_ranking_rules(),
            'typoTolerance'        => [
                'enabled' => $this->settings->get( 'typo_tolerance', true ),
            ],
        ] );

        // Apply synonyms if any.
        $synonyms = $this->settings->get_synonyms();
        if ( ! empty( $synonyms ) ) {
            $index->updateSynonyms( $synonyms );
        }
    }

    // ── Search ─────────────────────────────────────────────────────────────

    /**
     * Run a search and return the raw Meilisearch response.
     *
     * @param  string $query   Search string.
     * @param  array  $params  Meilisearch search params (filter, sort, facets, limit, offset…).
     * @return array           ['hits' => [], 'facetDistribution' => [], 'totalHits' => int, ...]
     */
    public function search( string $query, array $params = [] ): array {
        try {
            $result = $this->get_index()->search( $query, $params );
            return $result->toArray();
        } catch ( \Throwable $e ) {
            $this->log_error( 'search', $e->getMessage() );
            return [ 'hits' => [], 'totalHits' => 0 ];
        }
    }

    // ── Indexing ───────────────────────────────────────────────────────────

    /**
     * Add or replace documents in the index.
     *
     * @param  array $documents  Array of document arrays.
     * @return array|null        Task info, or null on failure.
     */
    public function add_documents( array $documents ): ?array {
        if ( empty( $documents ) ) {
            return null;
        }
        try {
            return $this->get_index()->addDocuments( $documents, 'id' );
        } catch ( \Throwable $e ) {
            $this->log_error( 'add_documents', $e->getMessage() );
            return null;
        }
    }

    /**
     * Delete a single document by string ID (e.g. "post-123").
     */
    public function delete_document( string $id ): ?array {
        try {
            return $this->get_index()->deleteDocument( $id );
        } catch ( \Throwable $e ) {
            $this->log_error( 'delete_document', $e->getMessage() );
            return null;
        }
    }

    /**
     * Delete all documents in the index.
     */
    public function delete_all_documents(): ?array {
        try {
            return $this->get_index()->deleteAllDocuments();
        } catch ( \Throwable $e ) {
            $this->log_error( 'delete_all_documents', $e->getMessage() );
            return null;
        }
    }

    // ── Stats ──────────────────────────────────────────────────────────────

    /**
     * @return array{numberOfDocuments:int, isIndexing:bool, fieldDistribution:array}|null
     */
    public function get_stats(): ?array {
        try {
            return $this->get_index()->stats();
        } catch ( \Throwable $e ) {
            $this->log_error( 'get_stats', $e->getMessage() );
            return null;
        }
    }

    /**
     * @return array{status:string, version:array}|null
     */
    public function get_server_info(): ?array {
        try {
            $health  = $this->sdk()->health();
            $version = $this->sdk()->version();
            return [
                'status'  => $health['status'] ?? 'unknown',
                'version' => $version,
            ];
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    // ── Logging ────────────────────────────────────────────────────────────

    private function log_error( string $action, string $message ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $wpdb->prefix . 'meiliwoo_logs',
            [
                'action'  => sanitize_key( $action ),
                'status'  => 'error',
                'message' => substr( $message, 0, 65535 ),
            ],
            [ '%s', '%s', '%s' ]
        );
    }
}
