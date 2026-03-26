<?php
/**
 * Integration tests against a real Meilisearch instance.
 *
 * Requires: MEILISEARCH_HOST and MEILISEARCH_API_KEY env vars,
 * and a running Meilisearch (via Docker Compose).
 *
 * Skipped automatically when Meilisearch is not reachable.
 *
 * @package MeiliWoo\Search\Tests\Integration
 * @group   integration
 */

declare(strict_types=1);

namespace MeiliWoo\Search\Tests\Integration;

use Meilisearch\Client as MeilisearchSDK;
use MeiliWoo\Search\Admin\SettingsManager;
use MeiliWoo\Search\Client\MeilisearchClient;
use PHPUnit\Framework\TestCase;

class IndexingTest extends TestCase {

    private static string $test_index = 'meiliwoo_test_integration';
    private MeilisearchClient $client;
    private SettingsManager $settings;
    private MeilisearchSDK $sdk;

    protected function setUp(): void {
        parent::setUp();

        $host    = getenv( 'MEILISEARCH_HOST' )    ?: 'http://localhost:7700';
        $api_key = getenv( 'MEILISEARCH_API_KEY' ) ?: 'changeme_master_key';

        // Skip if Meilisearch is not reachable.
        try {
            $this->sdk = new MeilisearchSDK( $host, $api_key );
            $this->sdk->health();
        } catch ( \Throwable $e ) {
            $this->markTestSkipped( 'Meilisearch not reachable: ' . $e->getMessage() );
        }

        $this->settings = $this->createSettingsStub( $host, $api_key );
        $this->client   = new MeilisearchClient( $this->settings );
    }

    protected function tearDown(): void {
        // Clean up test index.
        try {
            $this->sdk->deleteIndex( self::$test_index );
        } catch ( \Throwable ) {
            // Ignore if already deleted.
        }
        parent::tearDown();
    }

    // ── Tests ──────────────────────────────────────────────────────────────

    public function test_is_connected_returns_true(): void {
        $this->assertTrue( $this->client->is_connected() );
    }

    public function test_ensure_index_creates_index(): void {
        $result = $this->client->ensure_index();
        $this->assertTrue( $result );
    }

    public function test_add_and_search_documents(): void {
        $this->client->ensure_index();

        $documents = [
            [
                'id'           => 'post-1',
                'type'         => 'product',
                'title'        => 'Blue Denim Jeans',
                'content'      => 'Classic blue denim jeans for all occasions.',
                'excerpt'      => 'Blue denim jeans.',
                'sku'          => 'JEANS-001',
                'price'        => 49.99,
                'price_min'    => 49.99,
                'price_max'    => 49.99,
                'sale_price'   => 0.0,
                'stock_status' => 'instock',
                'stock_quantity' => 100,
                'categories'   => [ 'Clothing', 'Bottoms' ],
                'tags'         => [ 'denim', 'casual' ],
                'attributes'   => [ 'color' => [ 'Blue' ], 'size' => [ 'M', 'L', 'XL' ] ],
                'image'        => '',
                'permalink'    => 'http://example.com/blue-jeans/',
                'date'         => '2026-01-01T00:00:00Z',
                'popularity'   => 42,
            ],
        ];

        $task = $this->client->add_documents( $documents );
        $this->assertNotNull( $task );

        // Wait for indexing.
        $this->sdk->waitForTask( $task['taskUid'], 10000 );

        // Search for the document.
        $result = $this->client->search( 'blue jeans' );
        $hits   = $result['hits'] ?? [];
        $this->assertNotEmpty( $hits );
        $this->assertSame( 'post-1', $hits[0]['id'] );
    }

    public function test_delete_document(): void {
        $this->client->ensure_index();

        $docs = [ [ 'id' => 'post-999', 'type' => 'product', 'title' => 'Temp Product' ] ];
        $task = $this->client->add_documents( $docs );
        $this->sdk->waitForTask( $task['taskUid'], 10000 );

        $deleteTask = $this->client->delete_document( 'post-999' );
        $this->assertNotNull( $deleteTask );
        $this->sdk->waitForTask( $deleteTask['taskUid'], 10000 );

        $result = $this->client->search( 'Temp Product', [ 'filter' => 'id = "post-999"' ] );
        $this->assertEmpty( $result['hits'] ?? [] );
    }

    public function test_get_stats_returns_array(): void {
        $this->client->ensure_index();
        $stats = $this->client->get_stats();
        $this->assertIsArray( $stats );
        $this->assertArrayHasKey( 'numberOfDocuments', $stats );
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function createSettingsStub( string $host, string $api_key ): SettingsManager {
        $settings = $this->createMock( SettingsManager::class );
        $settings->method( 'get_host' )->willReturn( $host );
        $settings->method( 'get_api_key' )->willReturn( $api_key );
        $settings->method( 'get_index_name' )->willReturn( self::$test_index );
        $settings->method( 'get_ranking_rules' )->willReturn( [ 'words', 'typo', 'proximity', 'attribute', 'sort', 'exactness' ] );
        $settings->method( 'get_facetable_attributes' )->willReturn( [ 'categories', 'stock_status' ] );
        $settings->method( 'get_sortable_attributes' )->willReturn( [ 'price', 'popularity', 'date' ] );
        $settings->method( 'get_searchable_fields' )->willReturn( [ 'title', 'content', 'sku' ] );
        $settings->method( 'get_synonyms' )->willReturn( [] );
        $settings->method( 'get' )->with( 'typo_tolerance', true )->willReturn( true );
        $settings->method( 'fallback_enabled' )->willReturn( true );
        return $settings;
    }
}
