<?php
/**
 * Intercepts WP_Query search requests and replaces results with Meilisearch output.
 *
 * @package MeiliWoo\Search\Query
 */

declare(strict_types=1);

namespace MeiliWoo\Search\Query;

use MeiliWoo\Search\Admin\SettingsManager;
use MeiliWoo\Search\Client\MeilisearchClient;
use WP_Query;

/**
 * Hooks into pre_get_posts / posts_pre_query to inject Meilisearch results.
 *
 * Strategy:
 *  1. Detect is_search() (or WC product catalog query).
 *  2. Run Meilisearch query with filters derived from WC_Query args.
 *  3. Set post__in + orderby=post__in on the WP_Query so WP fetches
 *     only those posts in the Meilisearch relevance order.
 *  4. On error, do nothing → falls back silently to MySQL.
 */
class QueryInterceptor {

    /** Result cache keyed by search string + filter hash. */
    private array $results_cache = [];

    public function __construct(
        private readonly MeilisearchClient $client,
        private readonly SettingsManager   $settings
    ) {}

    // ── Hook registration ──────────────────────────────────────────────────

    public function register_hooks(): void {
        // Primary intercept: modify the query before SQL is built.
        add_action( 'pre_get_posts', [ $this, 'intercept' ], 10 );

        // Early return: if we already set post__in, skip WP SQL search.
        add_filter( 'posts_pre_query', [ $this, 'maybe_short_circuit' ], 10, 2 );

        // WooCommerce catalog ordering compatibility.
        add_filter( 'woocommerce_get_catalog_ordering_args', [ $this, 'preserve_meiliwoo_order' ], 20 );
    }

    // ── Interception ───────────────────────────────────────────────────────

    /**
     * Hooked on pre_get_posts (priority 10).
     */
    public function intercept( WP_Query $query ): void {
        // Only act on the main query or explicitly-marked sub-queries.
        if ( ! $this->should_intercept( $query ) ) {
            return;
        }

        if ( ! $this->client->is_connected() ) {
            // Fallback: let WordPress handle it natively.
            if ( is_admin() ) {
                add_action( 'admin_notices', [ $this, 'fallback_notice' ] );
            }
            return;
        }

        $search_query = $this->get_search_string( $query );
        $params       = $this->build_params( $query );
        $cache_key    = md5( $search_query . serialize( $params ) );

        if ( isset( $this->results_cache[ $cache_key ] ) ) {
            $result = $this->results_cache[ $cache_key ];
        } else {
            $result = $this->client->search( $search_query, $params );
            $this->results_cache[ $cache_key ] = $result;
        }

        $hits = $result['hits'] ?? [];

        if ( empty( $hits ) ) {
            // No results: set impossible ID so WP returns 0 posts (not all posts).
            $query->set( 'post__in', [ 0 ] );
            $query->set( 'orderby',  'post__in' );
            $query->set( 'meiliwoo_intercepted', true );
            return;
        }

        // Extract WordPress post IDs from Meilisearch hits.
        $post_ids = $this->extract_post_ids( $hits );

        $query->set( 'post__in',            $post_ids );
        $query->set( 'orderby',             'post__in' );
        $query->set( 'posts_per_page',      count( $post_ids ) );
        $query->set( 'meiliwoo_intercepted', true );
        $query->set( 'meiliwoo_total_hits',  $result['totalHits'] ?? count( $hits ) );

        // Store facet distribution for theme/block consumption.
        if ( ! empty( $result['facetDistribution'] ) ) {
            $query->set( 'meiliwoo_facets', $result['facetDistribution'] );
        }
    }

    /**
     * Hooked on posts_pre_query.
     * If we've already set post__in, tell WP to skip its own search clauses.
     */
    public function maybe_short_circuit( ?array $posts, WP_Query $query ): ?array {
        // We don't short-circuit here; returning null lets WP run its normal SQL.
        // The post__in already constrains the results correctly.
        return $posts;
    }

    // ── WooCommerce ordering compatibility ─────────────────────────────────

    public function preserve_meiliwoo_order( array $args ): array {
        global $wp_query;
        if ( $wp_query instanceof WP_Query && $wp_query->get( 'meiliwoo_intercepted' ) ) {
            $args['orderby'] = 'post__in';
            $args['order']   = '';
        }
        return $args;
    }

    // ── Fallback notice ────────────────────────────────────────────────────

    public function fallback_notice(): void {
        echo '<div class="notice notice-warning is-dismissible"><p>' .
            esc_html__( 'MeiliWoo Search: Meilisearch is unreachable. Falling back to MySQL search.', 'meiliwoo-search' ) .
            '</p></div>';
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function should_intercept( WP_Query $query ): bool {
        // Skip admin queries (unless it's an admin search we want to support).
        if ( $query->is_admin ) {
            return false;
        }

        // Skip if another plugin already set post__in intentionally.
        if ( $query->get( 'meiliwoo_skip' ) ) {
            return false;
        }

        // Standard WP search.
        if ( $query->is_search() && $query->is_main_query() ) {
            return true;
        }

        // WooCommerce product catalog search.
        if ( $this->is_wc_product_search( $query ) ) {
            return true;
        }

        return false;
    }

    private function is_wc_product_search( WP_Query $query ): bool {
        if ( ! function_exists( 'is_shop' ) ) {
            return false;
        }
        $post_type = (array) $query->get( 'post_type' );
        return in_array( 'product', $post_type, true ) && $query->get( 's' );
    }

    private function get_search_string( WP_Query $query ): string {
        return (string) $query->get( 's', '' );
    }

    /**
     * Build Meilisearch search params from WP_Query / WC_Query context.
     */
    private function build_params( WP_Query $query ): array {
        $params = [
            'limit'            => max( 1, (int) $query->get( 'posts_per_page', get_option( 'posts_per_page' ) ) ),
            'offset'           => max( 0, ( (int) $query->get( 'paged', 1 ) - 1 ) * (int) $query->get( 'posts_per_page', get_option( 'posts_per_page' ) ) ),
            'attributesToRetrieve' => [ 'id', 'type', 'parent_id' ],
        ];

        // Add facet distribution if facets are configured.
        $facetable = $this->settings->get_facetable_attributes();
        if ( ! empty( $facetable ) ) {
            $params['facets'] = $facetable;
        }

        // Build filter from WC layered nav / URL params.
        $filters = $this->build_filters( $query );
        if ( ! empty( $filters ) ) {
            $params['filter'] = $filters;
        }

        // Sorting.
        $sort = $this->resolve_sort( $query );
        if ( ! empty( $sort ) ) {
            $params['sort'] = $sort;
        }

        /**
         * Filter Meilisearch search params.
         *
         * @param array    $params Meilisearch params array.
         * @param WP_Query $query  The current WP_Query instance.
         */
        return apply_filters( 'meiliwoo_search_params', $params, $query );
    }

    /**
     * Translate WC layered-nav / URL query vars into a Meilisearch filter string.
     */
    private function build_filters( WP_Query $query ): array {
        $filters = [];

        // Post type filter: only index-able types.
        $post_types = (array) $query->get( 'post_type' );
        if ( ! empty( $post_types ) && ! in_array( 'any', $post_types, true ) ) {
            $type_filters = array_map( static fn( $t ) => "type = \"{$t}\"", $post_types );
            // Include variations for product searches.
            if ( in_array( 'product', $post_types, true ) ) {
                $type_filters[] = 'type = "variation"';
            }
            $filters[] = '(' . implode( ' OR ', $type_filters ) . ')';
        }

        // Price range (WooCommerce).
        $min_price = $query->get( 'min_price' );
        $max_price = $query->get( 'max_price' );
        if ( is_numeric( $min_price ) ) {
            $filters[] = 'price >= ' . (float) $min_price;
        }
        if ( is_numeric( $max_price ) ) {
            $filters[] = 'price <= ' . (float) $max_price;
        }

        // Stock status.
        if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
            $filters[] = 'stock_status = "instock"';
        }

        // WC taxonomy filters (layered nav).
        foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification
            if ( strpos( $key, 'filter_' ) !== 0 ) {
                continue;
            }
            $attribute = wc_sanitize_taxonomy_name( substr( $key, 7 ) );
            $terms     = array_map( 'sanitize_text_field', explode( ',', (string) $value ) );
            if ( ! empty( $terms ) ) {
                $term_filters = array_map( static fn( $t ) => "attributes.{$attribute} = \"{$t}\"", $terms );
                $filters[]    = '(' . implode( ' OR ', $term_filters ) . ')';
            }
        }

        // Category filter.
        $cat_slug = $query->get( 'product_cat' );
        if ( $cat_slug ) {
            $filters[] = 'categories = "' . esc_attr( $cat_slug ) . '"';
        }

        /**
         * Filter the Meilisearch filter array.
         *
         * @param array    $filters Meilisearch filter conditions.
         * @param WP_Query $query   Current WP_Query.
         */
        return apply_filters( 'meiliwoo_search_filters', $filters, $query );
    }

    private function resolve_sort( WP_Query $query ): array {
        $orderby = (string) $query->get( 'orderby', '' );

        $sort_map = [
            'price'          => [ 'price:asc' ],
            'price-desc'     => [ 'price:desc' ],
            'popularity'     => [ 'popularity:desc' ],
            'date'           => [ 'date:desc' ],
            'rating'         => [ 'popularity:desc' ],
        ];

        return $sort_map[ $orderby ] ?? [];
    }

    /**
     * Convert Meilisearch hit IDs (e.g. "post-123", "variation-456") to int post IDs.
     *
     * @param  array $hits Meilisearch hits.
     * @return int[]       WordPress post IDs.
     */
    private function extract_post_ids( array $hits ): array {
        $ids = [];
        foreach ( $hits as $hit ) {
            $raw_id = $hit['id'] ?? '';
            // Format is "post-123" or "variation-456".
            $parts = explode( '-', (string) $raw_id, 2 );
            if ( count( $parts ) === 2 && is_numeric( $parts[1] ) ) {
                $post_id = (int) $parts[1];
                if ( $post_id > 0 ) {
                    $ids[] = $post_id;
                }
            }
        }
        return $ids;
    }
}
