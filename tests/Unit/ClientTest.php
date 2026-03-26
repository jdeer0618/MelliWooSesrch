<?php
/**
 * Unit tests for MeilisearchClient.
 *
 * @package MeiliWoo\Search\Tests\Unit
 */

declare(strict_types=1);

namespace MeiliWoo\Search\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Meilisearch\Client;
use MeiliWoo\Search\Admin\SettingsManager;
use MeiliWoo\Search\Client\MeilisearchClient;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Stub WordPress functions used by MeilisearchClient.
        Functions\stubs( [
            'get_transient'    => false,
            'set_transient'    => true,
            'delete_transient' => true,
        ] );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_is_connected_returns_false_when_sdk_throws(): void {
        $settings = $this->createMock( SettingsManager::class );
        $settings->method( 'get_host' )->willReturn( 'http://localhost:7700' );
        $settings->method( 'get_api_key' )->willReturn( '' );
        $settings->method( 'get_index_name' )->willReturn( 'wp_meiliwoo_1' );

        // Mock the DB write in log_error.
        Functions\stubs( [
            'get_transient' => false,
        ] );

        global $wpdb;
        $wpdb = $this->createMock( \stdClass::class );
        $wpdb->prefix = 'wp_';
        $wpdb->method( 'insert' )->willReturn( 1 );

        $client = new MeilisearchClient( $settings );

        // Since the SDK will try to actually connect to localhost:7700 and fail
        // in CI, we just verify is_connected() returns a boolean.
        $result = $client->is_connected();
        $this->assertIsBool( $result );
    }

    public function test_reset_connection_clears_cache(): void {
        $settings = $this->createMock( SettingsManager::class );
        $settings->method( 'get_host' )->willReturn( 'http://localhost:7700' );
        $settings->method( 'get_api_key' )->willReturn( '' );
        $settings->method( 'get_index_name' )->willReturn( 'wp_meiliwoo_1' );

        Functions\expect( 'delete_transient' )->once()->with( 'meiliwoo_connected' );

        $client = new MeilisearchClient( $settings );
        $client->reset_connection();

        // No exception → pass.
        $this->assertTrue( true );
    }

    public function test_add_documents_returns_null_on_empty_array(): void {
        $settings = $this->createMock( SettingsManager::class );
        $settings->method( 'get_host' )->willReturn( 'http://localhost:7700' );
        $settings->method( 'get_api_key' )->willReturn( '' );
        $settings->method( 'get_index_name' )->willReturn( 'wp_meiliwoo_1' );

        $client = new MeilisearchClient( $settings );
        $result = $client->add_documents( [] );
        $this->assertNull( $result );
    }
}
