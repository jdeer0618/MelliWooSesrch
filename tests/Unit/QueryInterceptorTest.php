<?php
/**
 * Unit tests for QueryInterceptor.
 *
 * @package MeiliWoo\Search\Tests\Unit
 */

declare(strict_types=1);

namespace MeiliWoo\Search\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use MeiliWoo\Search\Admin\SettingsManager;
use MeiliWoo\Search\Client\MeilisearchClient;
use MeiliWoo\Search\Query\QueryInterceptor;
use PHPUnit\Framework\TestCase;
use WP_Query;

class QueryInterceptorTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_intercept_skips_admin_queries(): void {
        $client   = $this->createMock( MeilisearchClient::class );
        $settings = $this->createMock( SettingsManager::class );

        $client->expects( $this->never() )->method( 'search' );

        $interceptor = new QueryInterceptor( $client, $settings );

        $query           = $this->createMock( WP_Query::class );
        $query->is_admin = true;

        // Calling intercept on an admin query should not call search().
        $interceptor->intercept( $query );
    }

    public function test_intercept_falls_back_when_not_connected(): void {
        $client   = $this->createMock( MeilisearchClient::class );
        $settings = $this->createMock( SettingsManager::class );

        $client->method( 'is_connected' )->willReturn( false );
        $client->expects( $this->never() )->method( 'search' );

        Functions\stubs( [ 'add_action' => true ] );

        $query           = $this->createMock( WP_Query::class );
        $query->is_admin = false;
        $query->method( 'is_search' )->willReturn( true );
        $query->method( 'is_main_query' )->willReturn( true );
        $query->method( 'get' )->willReturnMap( [
            [ 'meiliwoo_skip', false ],
            [ 's', 'test' ],
        ] );

        $interceptor = new QueryInterceptor( $client, $settings );
        $interceptor->intercept( $query );
    }

    public function test_extract_post_ids_via_empty_results(): void {
        $client   = $this->createMock( MeilisearchClient::class );
        $settings = $this->createMock( SettingsManager::class );

        $client->method( 'is_connected' )->willReturn( true );
        $client->method( 'search' )->willReturn( [ 'hits' => [], 'totalHits' => 0 ] );

        $settings->method( 'get_facetable_attributes' )->willReturn( [] );
        $settings->method( 'get' )->willReturn( null );

        Functions\stubs( [
            'get_option'     => 10,
            'apply_filters'  => static fn( $hook, $val ) => $val,
            'esc_attr'       => static fn( $s ) => $s,
        ] );

        $query           = $this->createMock( WP_Query::class );
        $query->is_admin = false;
        $query->method( 'is_search' )->willReturn( true );
        $query->method( 'is_main_query' )->willReturn( true );
        $query->method( 'get' )->willReturnMap( [
            [ 'meiliwoo_skip', false ],
            [ 's', 'nonexistent' ],
            [ 'posts_per_page', 10 ],
            [ 'paged', 1 ],
            [ 'post_type', 'any' ],
            [ 'min_price', null ],
            [ 'max_price', null ],
            [ 'product_cat', null ],
            [ 'orderby', '' ],
        ] );

        // post__in set to [0] means no results.
        $query->expects( $this->atLeast( 1 ) )->method( 'set' )
              ->with( $this->equalTo( 'post__in' ), $this->equalTo( [ 0 ] ) );

        $interceptor = new QueryInterceptor( $client, $settings );
        $interceptor->intercept( $query );
    }
}
