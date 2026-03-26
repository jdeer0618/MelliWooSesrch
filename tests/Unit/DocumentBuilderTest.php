<?php
/**
 * Unit tests for DocumentBuilder.
 *
 * @package MeiliWoo\Search\Tests\Unit
 */

declare(strict_types=1);

namespace MeiliWoo\Search\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MeiliWoo\Search\Indexer\DocumentBuilder;
use PHPUnit\Framework\TestCase;
use WP_Post;

class DocumentBuilderTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_build_returns_empty_for_invalid_post_id(): void {
        Functions\expect( 'get_post' )->once()->with( 9999 )->andReturn( null );

        $builder = new DocumentBuilder();
        $result  = $builder->build( 9999 );
        $this->assertSame( [], $result );
    }

    public function test_build_post_doc_has_required_keys(): void {
        $post             = $this->make_post( 1, 'post' );
        $post->post_title = 'Hello World';

        Functions\expect( 'get_post' )->once()->with( 1 )->andReturn( $post );
        Functions\stubs( [
            'wc_get_product'              => false,
            'wp_strip_all_tags'           => static fn( $s ) => strip_tags( $s ),
            'get_the_terms'               => [],
            'get_the_post_thumbnail_url'  => '',
            'get_permalink'               => 'http://example.com/?p=1',
            'get_post_datetime'           => null,
            'apply_filters'               => static fn( $hook, $val ) => $val,
        ] );

        $builder = new DocumentBuilder();
        $docs    = $builder->build( 1 );

        $this->assertCount( 1, $docs );
        $doc = $docs[0];

        $this->assertSame( 'post-1', $doc['id'] );
        $this->assertSame( 'post', $doc['type'] );
        $this->assertSame( 'Hello World', $doc['title'] );
        $this->assertArrayHasKey( 'content', $doc );
        $this->assertArrayHasKey( 'categories', $doc );
        $this->assertArrayHasKey( 'permalink', $doc );
    }

    public function test_document_id_format_for_post(): void {
        $post = $this->make_post( 42, 'post' );
        Functions\expect( 'get_post' )->once()->with( 42 )->andReturn( $post );
        Functions\stubs( [
            'wc_get_product'             => false,
            'wp_strip_all_tags'          => static fn( $s ) => $s,
            'get_the_terms'              => [],
            'get_the_post_thumbnail_url' => '',
            'get_permalink'              => '',
            'get_post_datetime'          => null,
            'apply_filters'              => static fn( $hook, $val ) => $val,
        ] );

        $builder = new DocumentBuilder();
        $docs    = $builder->build( 42 );
        $this->assertSame( 'post-42', $docs[0]['id'] );
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function make_post( int $id, string $type ): WP_Post {
        $post              = new WP_Post( new \stdClass() );
        $post->ID          = $id;
        $post->post_type   = $type;
        $post->post_title  = "Post {$id}";
        $post->post_content = '';
        $post->post_excerpt = '';
        $post->post_status = 'publish';
        return $post;
    }
}
