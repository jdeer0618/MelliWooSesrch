<?php
/**
 * Batch indexer – executed by Action Scheduler.
 *
 * @package MeiliWoo\Search\Indexer
 */

declare(strict_types=1);

namespace MeiliWoo\Search\Indexer;

use MeiliWoo\Search\Plugin;

/**
 * Static handler for the meiliwoo_batch_index Action Scheduler action.
 *
 * Action Scheduler calls this with the array of args passed to as_enqueue_async_action().
 */
class BatchIndexer {

    /**
     * Process one batch of posts and push them to Meilisearch.
     *
     * @param array $args {
     *     @type int    $offset
     *     @type int    $batch_size
     *     @type array  $post_types
     * }
     */
    public static function run( array $args = [] ): void {
        $offset     = (int) ( $args['offset']     ?? 0 );
        $batch_size = (int) ( $args['batch_size'] ?? 100 );
        $post_types = (array) ( $args['post_types'] ?? [ 'product' ] );

        $plugin  = Plugin::instance();
        $client  = $plugin->get_client();
        $builder = new DocumentBuilder();

        if ( ! $client->is_connected() ) {
            return;
        }

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

        if ( empty( $posts ) ) {
            return;
        }

        $documents = [];
        foreach ( $posts as $post_id ) {
            $docs      = $builder->build( (int) $post_id );
            $documents = array_merge( $documents, $docs );
        }

        if ( ! empty( $documents ) ) {
            $client->add_documents( $documents );
        }
    }
}
