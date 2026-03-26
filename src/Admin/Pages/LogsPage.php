<?php
/**
 * Logs & Test search tab.
 *
 * @package MeiliWoo\Search\Admin\Pages
 */

declare(strict_types=1);

namespace MeiliWoo\Search\Admin\Pages;

use MeiliWoo\Search\Client\MeilisearchClient;

class LogsPage extends BasePage {

    public function __construct(
        private readonly MeilisearchClient $client
    ) {}

    public function render(): void {
        global $wpdb;

        $logs = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$wpdb->prefix}meiliwoo_logs ORDER BY created_at DESC LIMIT 100",
            ARRAY_A
        );

        ?>
        <!-- Live test search -->
        <div class="meiliwoo-card">
            <h3><?php esc_html_e( 'Test Search', 'meiliwoo-search' ); ?></h3>
            <div class="meiliwoo-test-search">
                <input type="text" id="meiliwoo-test-query" class="regular-text"
                       placeholder="<?php esc_attr_e( 'Enter a search query…', 'meiliwoo-search' ); ?>" />
                <button type="button" class="button button-primary" id="meiliwoo-run-test">
                    <?php esc_html_e( 'Search', 'meiliwoo-search' ); ?>
                </button>
            </div>
            <div id="meiliwoo-test-results" class="meiliwoo-test-results" style="display:none;">
                <h4><?php esc_html_e( 'Results', 'meiliwoo-search' ); ?></h4>
                <div id="meiliwoo-test-results-inner"></div>
            </div>
        </div>

        <!-- Log table -->
        <div class="meiliwoo-card">
            <h3>
                <?php esc_html_e( 'Indexing Log', 'meiliwoo-search' ); ?>
                <span class="meiliwoo-badge"><?php echo esc_html( number_format_i18n( count( $logs ) ) ); ?></span>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=meiliwoo-search&tab=logs&clear=1&_wpnonce=' . wp_create_nonce( 'meiliwoo_clear_logs' ) ) ); ?>"
                   class="button button-small" style="float:right">
                    <?php esc_html_e( 'Clear Logs', 'meiliwoo-search' ); ?>
                </a>
            </h3>

            <?php
            // Handle clear logs.
            if ( ! empty( $_GET['clear'] ) && ! empty( $_GET['_wpnonce'] ) &&
                wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'meiliwoo_clear_logs' ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}meiliwoo_logs" );
                echo '<div class="notice notice-success inline"><p>' .
                    esc_html__( 'Logs cleared.', 'meiliwoo-search' ) .
                    '</p></div>';
                $logs = [];
            }
            ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Time', 'meiliwoo-search' ); ?></th>
                        <th><?php esc_html_e( 'Action', 'meiliwoo-search' ); ?></th>
                        <th><?php esc_html_e( 'Object ID', 'meiliwoo-search' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'meiliwoo-search' ); ?></th>
                        <th><?php esc_html_e( 'Message', 'meiliwoo-search' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $logs ) ) : ?>
                    <tr><td colspan="5"><em><?php esc_html_e( 'No log entries yet.', 'meiliwoo-search' ); ?></em></td></tr>
                    <?php else : ?>
                        <?php foreach ( $logs as $log ) : ?>
                        <tr class="meiliwoo-log-<?php echo esc_attr( $log['status'] ); ?>">
                            <td>
                                <abbr title="<?php echo esc_attr( $log['created_at'] ); ?>">
                                    <?php echo esc_html( human_time_diff( strtotime( $log['created_at'] ) ) . ' ago' ); ?>
                                </abbr>
                            </td>
                            <td><code><?php echo esc_html( $log['action'] ); ?></code></td>
                            <td><?php echo $log['object_id'] ? esc_html( $log['object_id'] ) : '—'; ?></td>
                            <td>
                                <span class="meiliwoo-badge meiliwoo-badge-<?php echo esc_attr( $log['status'] ); ?>">
                                    <?php echo esc_html( $log['status'] ); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html( $log['message'] ?: '—' ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
