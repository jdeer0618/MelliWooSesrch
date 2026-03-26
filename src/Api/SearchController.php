<?php
/**
 * REST API controller for live search / autocomplete.
 *
 * @package MeiliWoo\Search\Api
 */

declare(strict_types=1);

namespace MeiliWoo\Search\Api;

use MeiliWoo\Search\Admin\SettingsManager;
use MeiliWoo\Search\Client\MeilisearchClient;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Endpoint: GET /wp-json/meiliwoo/v1/search
 *
 * Powers the live autocomplete and test-search UI.
 */
class SearchController extends WP_REST_Controller {

    protected $namespace = 'meiliwoo/v1';
    protected $rest_base = 'search';

    public function __construct(
        private readonly MeilisearchClient $client,
        private readonly SettingsManager   $settings
    ) {}

    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'search' ],
                    'permission_callback' => '__return_true', // Public – results are already public.
                    'args'                => $this->get_search_args(),
                ],
            ]
        );
    }

    // ── Search handler ─────────────────────────────────────────────────────

    public function search( WP_REST_Request $request ): WP_REST_Response {
        if ( ! $this->settings->autocomplete_enabled() ) {
            return new WP_REST_Response( [ 'error' => 'Autocomplete disabled.' ], 403 );
        }

        if ( ! $this->client->is_connected() ) {
            return new WP_REST_Response( [ 'hits' => [], 'totalHits' => 0, 'fallback' => true ] );
        }

        $query  = sanitize_text_field( $request->get_param( 'q' ) ?? '' );
        $limit  = max( 1, min( 50, (int) ( $request->get_param( 'limit' ) ?? 10 ) ) );
        $type   = sanitize_key( $request->get_param( 'type' ) ?? '' );

        $params = [
            'limit'                => $limit,
            'attributesToRetrieve' => [ 'id', 'title', 'type', 'sku', 'price', 'image', 'permalink', 'stock_status' ],
            'attributesToHighlight' => [ 'title', 'sku' ],
            'highlightPreTag'      => '<mark>',
            'highlightPostTag'     => '</mark>',
        ];

        if ( $type ) {
            $params['filter'] = "type = \"{$type}\"";
        }

        $result = $this->client->search( $query, $params );
        $hits   = $result['hits'] ?? [];

        // Enrich hits with WP edit links for admin users.
        $is_admin = current_user_can( 'edit_posts' );
        $hits = array_map( function ( $hit ) use ( $is_admin ) {
            $parts   = explode( '-', $hit['id'] ?? '', 2 );
            $post_id = isset( $parts[1] ) ? (int) $parts[1] : 0;
            if ( $is_admin && $post_id ) {
                $hit['edit_link'] = get_edit_post_link( $post_id, 'raw' );
            }
            unset( $hit['_formatted'] ); // Keep response clean; highlight is in _formatted key from SDK.
            return $hit;
        }, $hits );

        // Re-attach highlights.
        foreach ( ( $result['hits'] ?? [] ) as $i => $raw ) {
            if ( isset( $raw['_formatted'] ) ) {
                $hits[ $i ]['_formatted'] = $raw['_formatted'];
            }
        }

        return new WP_REST_Response( [
            'hits'              => $hits,
            'totalHits'         => $result['totalHits']        ?? count( $hits ),
            'facetDistribution' => $result['facetDistribution'] ?? [],
            'processingTimeMs'  => $result['processingTimeMs']  ?? 0,
        ] );
    }

    // ── Schema ─────────────────────────────────────────────────────────────

    private function get_search_args(): array {
        return [
            'q' => [
                'description'       => __( 'Search query string.', 'meiliwoo-search' ),
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'limit' => [
                'description' => __( 'Number of results (max 50).', 'meiliwoo-search' ),
                'type'        => 'integer',
                'default'     => 10,
                'minimum'     => 1,
                'maximum'     => 50,
            ],
            'type' => [
                'description'       => __( 'Filter by document type (product, post, page…).', 'meiliwoo-search' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_key',
            ],
        ];
    }
}
