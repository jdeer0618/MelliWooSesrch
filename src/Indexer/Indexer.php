<?php
/**
 * Real-time indexer: hooks into WordPress / WooCommerce events.
 *
 * @package MeiliWoo\Search\Indexer
 */

declare(strict_types=1);

namespace MeiliWoo\Search\Indexer;

use MeiliWoo\Search\Admin\SettingsManager;
use MeiliWoo\Search\Client\MeilisearchClient;

/**
 * Listens to WP/WC CRUD hooks and queues or directly indexes documents.
 */
class Indexer {

    /** Post types that are always indexed (WooCommerce products). */
    private const ALWAYS_INDEXED = [ 'product' ];

    /** Post statuses that should be in the index. */
    private const INDEXED_STATUSES = [ 'publish' ];

    public function __construct(
        private readonly MeilisearchClient $client,
        private readonly SettingsManager   $settings
    ) {}

    // ── Hook registration ──────────────────────────────────────────────────

    public function register_hooks(): void {
        // Post save (covers products via standard WP hooks too).
        add_action( 'wp_after_insert_post', [ $this, 'on_post_saved' ], 10, 2 );

        // WooCommerce-specific stock/price changes.
        add_action( 'woocommerce_update_product',       [ $this, 'on_product_updated' ] );
        add_action( 'woocommerce_product_set_stock',    [ $this, 'on_stock_changed' ] );
        add_action( 'woocommerce_variation_set_stock',  [ $this, 'on_stock_changed' ] );

        // Trash / delete.
        add_action( 'wp_trash_post',    [ $this, 'on_post_deleted' ] );
        add_action( 'before_delete_post', [ $this, 'on_post_deleted' ] );
        add_action( 'untrashed_post',   [ $this, 'on_post_saved_by_id' ] );

        // WooCommerce order placed → update popularity.
        add_action( 'woocommerce_order_status_completed', [ $this, 'on_order_completed' ] );
    }

    // ── Event handlers ─────────────────────────────────────────────────────

    /**
     * Called by wp_after_insert_post.
     *
     * @param int     $post_id Post ID.
     * @param \WP_Post $post   Post object.
     */
    public function on_post_saved( int $post_id, \WP_Post $post ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( ! $this->should_index( $post ) ) {
            return;
        }
        $this->schedule_single( $post_id );
    }

    public function on_post_saved_by_id( int $post_id ): void {
        $post = get_post( $post_id );
        if ( $post instanceof \WP_Post ) {
            $this->on_post_saved( $post_id, $post );
        }
    }

    public function on_product_updated( int $product_id ): void {
        $this->schedule_single( $product_id );
    }

    public function on_stock_changed( \WC_Product $product ): void {
        $this->schedule_single( $product->get_id() );
    }

    public function on_post_deleted( int $post_id ): void {
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return;
        }
        if ( ! $this->should_index( $post ) ) {
            return;
        }

        // For variable products, also delete variations.
        if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $post_id );
            if ( $product instanceof \WC_Product_Variable ) {
                foreach ( $product->get_children() as $variation_id ) {
                    $this->client->delete_document( 'variation-' . $variation_id );
                }
            }
        }

        $this->client->delete_document( 'post-' . $post_id );
        $this->log( 'delete', $post_id );
    }

    public function on_order_completed( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        foreach ( $order->get_items() as $item ) {
            if ( method_exists( $item, 'get_product_id' ) ) {
                $this->schedule_single( $item->get_product_id() );
            }
        }
    }

    // ── Indexing ───────────────────────────────────────────────────────────

    /**
     * Schedule a single-post index via Action Scheduler.
     */
    public function schedule_single( int $post_id ): void {
        as_enqueue_async_action(
            'meiliwoo_index_single',
            [ $post_id ],
            'meiliwoo'
        );
    }

    /**
     * Directly index a single post (called by Action Scheduler or CLI).
     */
    public function index_post( int $post_id ): void {
        if ( ! $this->client->is_connected() ) {
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        // If the post is no longer published, remove it.
        if ( ! in_array( $post->post_status, self::INDEXED_STATUSES, true ) ) {
            $this->client->delete_document( 'post-' . $post_id );
            if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
                $product = wc_get_product( $post_id );
                if ( $product instanceof \WC_Product_Variable ) {
                    foreach ( $product->get_children() as $vid ) {
                        $this->client->delete_document( 'variation-' . $vid );
                    }
                }
            }
            return;
        }

        $builder   = new DocumentBuilder();
        $documents = $builder->build( $post_id );

        if ( ! empty( $documents ) ) {
            $this->client->add_documents( $documents );
            $this->log( 'index', $post_id, count( $documents ) . ' doc(s)' );
        }
    }

    /**
     * Full reindex: schedules batches via Action Scheduler.
     *
     * @return int Total number of posts queued.
     */
    public function schedule_full_reindex(): int {
        global $wpdb;

        $post_types = $this->get_indexable_post_types();
        $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders})",
                ...$post_types
            )
        );

        $batch_size = $this->settings->get_batch_size();

        for ( $offset = 0; $offset < $total; $offset += $batch_size ) {
            as_enqueue_async_action(
                'meiliwoo_batch_index',
                [
                    'offset'     => $offset,
                    'batch_size' => $batch_size,
                    'post_types' => $post_types,
                ],
                'meiliwoo'
            );
        }

        return $total;
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function should_index( \WP_Post $post ): bool {
        if ( in_array( $post->post_type, self::ALWAYS_INDEXED, true ) ) {
            return true;
        }
        if ( 'product_variation' === $post->post_type ) {
            return true;
        }
        if ( 'post' === $post->post_type && $this->settings->should_index_posts() ) {
            return true;
        }
        if ( 'page' === $post->post_type && $this->settings->should_index_pages() ) {
            return true;
        }
        return false;
    }

    public function get_indexable_post_types(): array {
        $types = [ 'product' ];
        if ( $this->settings->should_index_posts() ) {
            $types[] = 'post';
        }
        if ( $this->settings->should_index_pages() ) {
            $types[] = 'page';
        }
        return $types;
    }

    private function log( string $action, int $object_id, string $message = '' ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $wpdb->prefix . 'meiliwoo_logs',
            [
                'action'    => $action,
                'object_id' => $object_id,
                'status'    => 'success',
                'message'   => $message,
            ],
            [ '%s', '%d', '%s', '%s' ]
        );
    }
}
