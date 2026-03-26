<?php
/**
 * Connection settings tab.
 *
 * @package MeiliWoo\Search\Admin\Pages
 */

declare(strict_types=1);

namespace MeiliWoo\Search\Admin\Pages;

use MeiliWoo\Search\Admin\SettingsManager;
use MeiliWoo\Search\Client\MeilisearchClient;

class ConnectionPage extends BasePage {

    public function __construct(
        private readonly MeilisearchClient $client,
        private readonly SettingsManager   $settings
    ) {}

    // ── Handle POST ────────────────────────────────────────────────────────

    public function handle_post( array $data ): void {
        $host    = sanitize_text_field( wp_unslash( $data['meiliwoo_host']    ?? '' ) );
        $api_key = sanitize_text_field( wp_unslash( $data['meiliwoo_api_key'] ?? '' ) );

        if ( '' !== $host ) {
            $this->settings->set( 'host', $host );
        }
        if ( '' !== $api_key ) {
            $this->settings->set( 'api_key', $api_key );
        }

        $this->client->reset_connection();

        // Attempt to ensure the index exists with current settings.
        if ( $this->client->is_connected() ) {
            $this->client->ensure_index();
            add_action( 'admin_notices', function () {
                $this->notice( __( 'Connection settings saved and verified.', 'meiliwoo-search' ) );
            } );
        } else {
            add_action( 'admin_notices', function () {
                $this->notice( __( 'Settings saved, but could not connect to Meilisearch. Check host and API key.', 'meiliwoo-search' ), 'warning' );
            } );
        }
    }

    // ── Render ─────────────────────────────────────────────────────────────

    public function render(): void {
        $connected   = $this->client->is_connected();
        $server_info = $connected ? $this->client->get_server_info() : null;
        $stats       = $connected ? $this->client->get_stats() : null;
        $host        = $this->settings->get_host();

        ?>
        <div class="meiliwoo-row">
            <!-- Status card -->
            <div class="meiliwoo-card meiliwoo-status-card <?php echo $connected ? 'is-connected' : 'is-disconnected'; ?>">
                <h3><?php esc_html_e( 'Connection Status', 'meiliwoo-search' ); ?></h3>
                <div class="meiliwoo-status-indicator">
                    <span class="meiliwoo-status-dot"></span>
                    <strong>
                        <?php echo $connected
                            ? esc_html__( 'Connected', 'meiliwoo-search' )
                            : esc_html__( 'Disconnected', 'meiliwoo-search' ); ?>
                    </strong>
                </div>
                <?php if ( $server_info ) : ?>
                    <table class="meiliwoo-info-table">
                        <tr>
                            <td><?php esc_html_e( 'Host', 'meiliwoo-search' ); ?></td>
                            <td><code><?php echo esc_html( $host ); ?></code></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Version', 'meiliwoo-search' ); ?></td>
                            <td><?php echo esc_html( $server_info['version']['pkgVersion'] ?? '—' ); ?></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Status', 'meiliwoo-search' ); ?></td>
                            <td><?php echo esc_html( $server_info['status'] ?? '—' ); ?></td>
                        </tr>
                        <?php if ( $stats ) : ?>
                        <tr>
                            <td><?php esc_html_e( 'Documents', 'meiliwoo-search' ); ?></td>
                            <td><?php echo esc_html( number_format_i18n( $stats['numberOfDocuments'] ?? 0 ) ); ?></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Index', 'meiliwoo-search' ); ?></td>
                            <td><code><?php echo esc_html( $this->settings->get_index_name() ); ?></code></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                <?php endif; ?>
                <button type="button" class="button button-secondary" id="meiliwoo-test-connection">
                    <?php esc_html_e( 'Test Connection', 'meiliwoo-search' ); ?>
                </button>
            </div>

            <!-- Settings form -->
            <div class="meiliwoo-card">
                <h3><?php esc_html_e( 'Meilisearch Settings', 'meiliwoo-search' ); ?></h3>
                <?php $this->settings_form_open( 'connection' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="meiliwoo_host"><?php esc_html_e( 'Meilisearch Host', 'meiliwoo-search' ); ?></label></th>
                        <td>
                            <input type="url" id="meiliwoo_host" name="meiliwoo_host"
                                   value="<?php echo esc_attr( $host ); ?>"
                                   class="regular-text" placeholder="http://localhost:7700" />
                            <p class="description"><?php esc_html_e( 'Full URL including port. No trailing slash.', 'meiliwoo-search' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="meiliwoo_api_key"><?php esc_html_e( 'Master API Key', 'meiliwoo-search' ); ?></label></th>
                        <td>
                            <input type="password" id="meiliwoo_api_key" name="meiliwoo_api_key"
                                   value="" autocomplete="new-password"
                                   class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank to keep current key', 'meiliwoo-search' ); ?>" />
                            <p class="description">
                                <?php esc_html_e( 'Stored encrypted. Leave blank to keep the existing key.', 'meiliwoo-search' ); ?>
                                <?php if ( $this->settings->get_api_key() ) : ?>
                                    <span class="meiliwoo-badge meiliwoo-badge-set"><?php esc_html_e( 'Key is set', 'meiliwoo-search' ); ?></span>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Index Name', 'meiliwoo-search' ); ?></th>
                        <td>
                            <code><?php echo esc_html( $this->settings->get_index_name() ); ?></code>
                            <p class="description"><?php esc_html_e( 'Auto-generated. One index per site.', 'meiliwoo-search' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Fallback', 'meiliwoo-search' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="meiliwoo_fallback_enabled"
                                       <?php checked( $this->settings->fallback_enabled() ); ?> value="1" />
                                <?php esc_html_e( 'Fall back to MySQL search when Meilisearch is unavailable', 'meiliwoo-search' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <?php $this->settings_form_close(); ?>
            </div>
        </div>

        <div class="meiliwoo-card">
            <h3><?php esc_html_e( 'Quick Setup', 'meiliwoo-search' ); ?></h3>
            <p><?php esc_html_e( 'Start Meilisearch with Docker:', 'meiliwoo-search' ); ?></p>
            <pre class="meiliwoo-code">docker compose up -d</pre>
            <p><?php esc_html_e( 'Or connect WP-CLI after starting:', 'meiliwoo-search' ); ?></p>
            <pre class="meiliwoo-code">wp meiliwoo connect --host=http://localhost:7700 --key=YOUR_MASTER_KEY</pre>
        </div>
        <?php
    }
}
