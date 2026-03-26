<?php
/**
 * Plugin settings / options manager.
 *
 * @package MeiliWoo\Search\Admin
 */

declare(strict_types=1);

namespace MeiliWoo\Search\Admin;

/**
 * Manages all plugin options stored in wp_options.
 */
class SettingsManager {

    private const OPTION_PREFIX = 'meiliwoo_';

    /** Defaults for every option key. */
    private const DEFAULTS = [
        'host'                  => 'http://localhost:7700',
        'api_key'               => '',
        'index_name'            => '',       // auto-generated on first access
        'index_posts'           => false,
        'index_pages'           => false,
        'fallback_enabled'      => true,
        'batch_size'            => 100,
        'autocomplete_enabled'  => true,
        'typo_tolerance'        => true,
        'facetable_attributes'  => [],
        'sortable_attributes'   => [],
        'ranking_rules'         => [
            'words',
            'typo',
            'proximity',
            'attribute',
            'sort',
            'exactness',
        ],
        'searchable_fields'     => [
            'title',
            'content',
            'excerpt',
            'sku',
            'categories',
            'tags',
        ],
        'synonyms'              => [],
        'distinct_attribute'    => null,
    ];

    // ── Install / Uninstall ────────────────────────────────────────────────

    public static function install(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        // Logs table.
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}meiliwoo_logs (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action        VARCHAR(64)     NOT NULL,
            object_id     BIGINT UNSIGNED,
            status        VARCHAR(32)     NOT NULL DEFAULT 'success',
            message       TEXT,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY action (action),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // ── Read ───────────────────────────────────────────────────────────────

    public function get( string $key, mixed $default = null ): mixed {
        $stored = get_option( self::OPTION_PREFIX . $key, null );
        if ( null !== $stored ) {
            return $stored;
        }
        return $default ?? ( self::DEFAULTS[ $key ] ?? null );
    }

    public function get_host(): string {
        return rtrim( (string) $this->get( 'host', 'http://localhost:7700' ), '/' );
    }

    public function get_api_key(): string {
        $key = (string) $this->get( 'api_key', '' );
        return $this->decrypt( $key );
    }

    public function get_index_name(): string {
        $name = (string) $this->get( 'index_name', '' );
        if ( '' === $name ) {
            $name = 'wp_meiliwoo_' . get_current_blog_id();
            $this->set( 'index_name', $name );
        }
        return $name;
    }

    public function get_batch_size(): int {
        return max( 1, (int) $this->get( 'batch_size', 100 ) );
    }

    public function should_index_posts(): bool {
        return (bool) $this->get( 'index_posts', false );
    }

    public function should_index_pages(): bool {
        return (bool) $this->get( 'index_pages', false );
    }

    public function fallback_enabled(): bool {
        return (bool) $this->get( 'fallback_enabled', true );
    }

    public function autocomplete_enabled(): bool {
        return (bool) $this->get( 'autocomplete_enabled', true );
    }

    public function get_searchable_fields(): array {
        return (array) $this->get( 'searchable_fields', self::DEFAULTS['searchable_fields'] );
    }

    public function get_ranking_rules(): array {
        return (array) $this->get( 'ranking_rules', self::DEFAULTS['ranking_rules'] );
    }

    public function get_synonyms(): array {
        return (array) $this->get( 'synonyms', [] );
    }

    public function get_facetable_attributes(): array {
        return (array) $this->get( 'facetable_attributes', [] );
    }

    public function get_sortable_attributes(): array {
        $base = [ 'price', 'price_min', 'price_max', 'stock_quantity', 'popularity', 'date' ];
        return array_unique( array_merge( $base, (array) $this->get( 'sortable_attributes', [] ) ) );
    }

    // ── Write ──────────────────────────────────────────────────────────────

    public function set( string $key, mixed $value ): bool {
        if ( 'api_key' === $key && '' !== $value ) {
            $value = $this->encrypt( (string) $value );
        }
        return update_option( self::OPTION_PREFIX . $key, $value, false );
    }

    public function set_many( array $values ): void {
        foreach ( $values as $key => $value ) {
            $this->set( $key, $value );
        }
    }

    public function delete( string $key ): bool {
        return delete_option( self::OPTION_PREFIX . $key );
    }

    // ── Encryption (lightweight, server-side only) ─────────────────────────

    private function encrypt( string $plaintext ): string {
        if ( '' === $plaintext || ! function_exists( 'sodium_crypto_secretbox' ) ) {
            return base64_encode( $plaintext );
        }
        $key   = $this->derive_key();
        $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
        return base64_encode( $nonce . $cipher );
    }

    private function decrypt( string $ciphertext ): string {
        if ( '' === $ciphertext ) {
            return '';
        }
        if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
            $decoded = base64_decode( $ciphertext, true );
            return false !== $decoded ? $decoded : $ciphertext;
        }
        try {
            $key     = $this->derive_key();
            $decoded = base64_decode( $ciphertext, true );
            if ( false === $decoded || strlen( $decoded ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
                return $ciphertext; // fallback: unencrypted legacy value
            }
            $nonce  = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $cipher = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
            return false !== $plain ? $plain : '';
        } catch ( \SodiumException $e ) {
            return '';
        }
    }

    private function derive_key(): string {
        $salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt( 'auth' );
        return substr( hash( 'sha256', $salt . 'meiliwoo', true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
    }

    // ── All options (for reset) ────────────────────────────────────────────

    public function reset_all(): void {
        foreach ( array_keys( self::DEFAULTS ) as $key ) {
            $this->delete( $key );
        }
    }
}
