<?php
/**
 * Transforms WordPress posts / WooCommerce products into Meilisearch documents.
 *
 * @package MeiliWoo\Search\Indexer
 */

declare(strict_types=1);

namespace MeiliWoo\Search\Indexer;

use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Post;

/**
 * Builds a flat Meilisearch document array from a WP_Post or WC_Product.
 */
class DocumentBuilder {

    // ── Public API ─────────────────────────────────────────────────────────

    /**
     * Build one or more documents for a given post ID.
     *
     * For a variable product this returns documents for the parent AND all variations.
     *
     * @return array<array<string,mixed>>
     */
    public function build( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post ) {
            return [];
        }

        if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
            return $this->build_product_docs( $post_id );
        }

        if ( 'product_variation' === $post->post_type ) {
            return $this->build_variation_doc( $post_id );
        }

        return [ $this->build_post_doc( $post ) ];
    }

    // ── Product documents ──────────────────────────────────────────────────

    /**
     * @return array<array<string,mixed>>
     */
    private function build_product_docs( int $post_id ): array {
        $product = wc_get_product( $post_id );
        if ( ! $product instanceof WC_Product ) {
            return [];
        }

        $docs = [ $this->product_to_doc( $product ) ];

        // For variable products, also index each variation.
        if ( $product instanceof WC_Product_Variable ) {
            foreach ( $product->get_children() as $variation_id ) {
                $variation_docs = $this->build_variation_doc( $variation_id, $product );
                $docs           = array_merge( $docs, $variation_docs );
            }
        }

        return $docs;
    }

    /**
     * @return array<array<string,mixed>>
     */
    private function build_variation_doc( int $variation_id, ?WC_Product_Variable $parent = null ): array {
        $variation = wc_get_product( $variation_id );
        if ( ! $variation instanceof WC_Product_Variation ) {
            return [];
        }

        if ( null === $parent ) {
            $parent = wc_get_product( $variation->get_parent_id() );
        }

        $doc = [
            'id'           => 'variation-' . $variation_id,
            'type'         => 'variation',
            'parent_id'    => $variation->get_parent_id(),
            'title'        => $parent ? $parent->get_name() . ' – ' . implode( ', ', $variation->get_variation_attributes() ) : $variation->get_name(),
            'content'      => $variation->get_description(),
            'excerpt'      => '',
            'sku'          => $variation->get_sku(),
            'price'        => (float) ( $variation->get_price() ?: 0 ),
            'price_min'    => (float) ( $variation->get_price() ?: 0 ),
            'price_max'    => (float) ( $variation->get_price() ?: 0 ),
            'sale_price'   => (float) ( $variation->get_sale_price() ?: 0 ),
            'stock_status' => $variation->get_stock_status(),
            'stock_quantity' => $variation->get_stock_quantity() ?? 0,
            'categories'   => $parent ? $this->get_term_names( $parent->get_id(), 'product_cat' ) : [],
            'tags'         => $parent ? $this->get_term_names( $parent->get_id(), 'product_tag' ) : [],
            'attributes'   => $this->get_variation_attributes( $variation ),
            'image'        => get_the_post_thumbnail_url( $variation_id, 'full' ) ?: ( $parent ? get_the_post_thumbnail_url( $parent->get_id(), 'full' ) : '' ),
            'permalink'    => get_permalink( $variation_id ) ?: '',
            'date'         => get_post_datetime( $variation_id )?->format( 'c' ) ?? '',
            'popularity'   => $parent ? (int) $parent->get_total_sales() : 0,
        ];

        /**
         * Filter a variation document before indexing.
         *
         * @param array              $doc       Document array.
         * @param WC_Product_Variation $variation Variation product object.
         */
        return [ apply_filters( 'meiliwoo_document', $doc, get_post( $variation_id ) ) ];
    }

    private function product_to_doc( WC_Product $product ): array {
        $post_id   = $product->get_id();
        $is_variable = $product instanceof WC_Product_Variable;

        $doc = [
            'id'             => 'post-' . $post_id,
            'type'           => 'product',
            'parent_id'      => 0,
            'title'          => $product->get_name(),
            'content'        => wp_strip_all_tags( $product->get_description() ),
            'excerpt'        => wp_strip_all_tags( $product->get_short_description() ),
            'sku'            => $product->get_sku(),
            'price'          => (float) ( $product->get_price() ?: 0 ),
            'price_min'      => $is_variable ? (float) ( $product->get_variation_price( 'min' ) ?: 0 ) : (float) ( $product->get_price() ?: 0 ),
            'price_max'      => $is_variable ? (float) ( $product->get_variation_price( 'max' ) ?: 0 ) : (float) ( $product->get_price() ?: 0 ),
            'sale_price'     => (float) ( $product->get_sale_price() ?: 0 ),
            'stock_status'   => $product->get_stock_status(),
            'stock_quantity' => $product->get_stock_quantity() ?? 0,
            'categories'     => $this->get_term_names( $post_id, 'product_cat' ),
            'tags'           => $this->get_term_names( $post_id, 'product_tag' ),
            'attributes'     => $this->get_product_attributes( $product ),
            'image'          => get_the_post_thumbnail_url( $post_id, 'full' ) ?: '',
            'gallery'        => $this->get_gallery_urls( $product ),
            'permalink'      => get_permalink( $post_id ) ?: '',
            'date'           => get_post_datetime( $post_id )?->format( 'c' ) ?? '',
            'popularity'     => (int) $product->get_total_sales(),
        ];

        /** Filter: meiliwoo_document */
        return apply_filters( 'meiliwoo_document', $doc, get_post( $post_id ) );
    }

    // ── Generic post document ──────────────────────────────────────────────

    private function build_post_doc( WP_Post $post ): array {
        $doc = [
            'id'           => 'post-' . $post->ID,
            'type'         => $post->post_type,
            'parent_id'    => 0,
            'title'        => $post->post_title,
            'content'      => wp_strip_all_tags( $post->post_content ),
            'excerpt'      => wp_strip_all_tags( $post->post_excerpt ),
            'sku'          => '',
            'price'        => 0.0,
            'price_min'    => 0.0,
            'price_max'    => 0.0,
            'sale_price'   => 0.0,
            'stock_status' => '',
            'stock_quantity' => 0,
            'categories'   => $this->get_term_names( $post->ID, 'category' ),
            'tags'         => $this->get_term_names( $post->ID, 'post_tag' ),
            'attributes'   => (object) [],
            'image'        => get_the_post_thumbnail_url( $post->ID, 'full' ) ?: '',
            'gallery'      => [],
            'permalink'    => get_permalink( $post->ID ) ?: '',
            'date'         => get_post_datetime( $post->ID )?->format( 'c' ) ?? '',
            'popularity'   => 0,
        ];

        return apply_filters( 'meiliwoo_document', $doc, $post );
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function get_term_names( int $post_id, string $taxonomy ): array {
        $terms = get_the_terms( $post_id, $taxonomy );
        if ( ! is_array( $terms ) ) {
            return [];
        }
        return array_values( array_map( static fn( $t ) => $t->name, $terms ) );
    }

    private function get_product_attributes( WC_Product $product ): object {
        $result = [];
        foreach ( $product->get_attributes() as $slug => $attribute ) {
            if ( ! $attribute->get_variation() && ! $attribute->get_visible() ) {
                continue;
            }
            $name = wc_attribute_label( $slug, $product );
            if ( $attribute->is_taxonomy() ) {
                $terms = $attribute->get_terms();
                if ( is_array( $terms ) ) {
                    $result[ $name ] = array_map( static fn( $t ) => $t->name, $terms );
                }
            } else {
                $result[ $name ] = $attribute->get_options();
            }
        }
        return (object) $result;
    }

    private function get_variation_attributes( WC_Product_Variation $variation ): object {
        $result = [];
        foreach ( $variation->get_variation_attributes() as $key => $value ) {
            $label          = wc_attribute_label( str_replace( 'attribute_', '', $key ), $variation );
            $result[ $label ] = [ $value ];
        }
        return (object) $result;
    }

    private function get_gallery_urls( WC_Product $product ): array {
        $urls = [];
        foreach ( $product->get_gallery_image_ids() as $image_id ) {
            $url = wp_get_attachment_url( $image_id );
            if ( $url ) {
                $urls[] = $url;
            }
        }
        return $urls;
    }
}
