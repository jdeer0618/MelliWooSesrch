<?php
/**
 * Indexing settings tab.
 *
 * @package MeiliWoo\Search\Admin\Pages
 */

declare(strict_types=1);

namespace MeiliWoo\Search\Admin\Pages;

use MeiliWoo\Search\Admin\SettingsManager;
use MeiliWoo\Search\Client\MeilisearchClient;
use MeiliWoo\Search\Indexer\Indexer;

class IndexingPage extends BasePage {

    public function __construct(
        private readonly MeilisearchClient $client,
        private readonly SettingsManager   $settings,
        private readonly Indexer           $indexer
    ) {}

    public function handle_post( array $data ): void {
        $this->settings->set( 'index_posts', ! empty( $data['meiliwoo_index_posts'] ) );
        $this->settings->set( 'index_pages', ! empty( $data['meiliwoo_index_pages'] ) );
        $this->settings->set( 'batch_size',  (int) ( $data['meiliwoo_batch_size'] ?? 100 ) );

        if ( ! empty( $data['meiliwoo_reindex'] ) && $this->client->is_connected() ) {
            $total = $this->indexer->schedule_full_reindex();
            add_action( 'admin_notices', function () use ( $total ) {
                $this->notice( sprintf(
                    /* translators: %d: number of posts */
                    __( 'Full reindex scheduled: %d posts queued via Action Scheduler.', 'meiliwoo-search' ),
                    $total
                ) );
            } );
        } else {
            add_action( 'admin_notices', function () {
                $this->notice( __( 'Indexing settings saved.', 'meiliwoo-search' ) );
            } );
        }
    }

    public function render(): void {
        $stats     = $this->client->is_connected() ? $this->client->get_stats() : null;
        $doc_count = $stats['numberOfDocuments'] ?? 0;
        $is_indexing = $stats['isIndexing'] ?? false;
        $last_sync = get_option( 'meiliwoo_last_sync', 0 );

        // Count pending AS actions.
        $pending = as_get_scheduled_actions( [
            'hook'     => 'meiliwoo_batch_index',
            'status'   => \ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 1,
        ] );
        $pending_count = count( $pending );

        ?>
        <div class="meiliwoo-row">
            <!-- Stats -->
            <div class="meiliwoo-card meiliwoo-stats-card">
                <h3><?php esc_html_e( 'Index Statistics', 'meiliwoo-search' ); ?></h3>
                <div class="meiliwoo-stat-grid">
                    <div class="meiliwoo-stat">
                        <span class="meiliwoo-stat-number"><?php echo esc_html( number_format_i18n( $doc_count ) ); ?></span>
                        <span class="meiliwoo-stat-label"><?php esc_html_e( 'Documents indexed', 'meiliwoo-search' ); ?></span>
                    </div>
                    <div class="meiliwoo-stat">
                        <span class="meiliwoo-stat-number <?php echo $is_indexing ? 'is-active' : ''; ?>">
                            <?php echo $is_indexing ? esc_html__( 'Yes', 'meiliwoo-search' ) : esc_html__( 'No', 'meiliwoo-search' ); ?>
                        </span>
                        <span class="meiliwoo-stat-label"><?php esc_html_e( 'Currently indexing', 'meiliwoo-search' ); ?></span>
                    </div>
                    <div class="meiliwoo-stat">
                        <span class="meiliwoo-stat-number"><?php echo esc_html( number_format_i18n( $pending_count ) ); ?></span>
                        <span class="meiliwoo-stat-label"><?php esc_html_e( 'Batches queued', 'meiliwoo-search' ); ?></span>
                    </div>
                    <div class="meiliwoo-stat">
                        <span class="meiliwoo-stat-number">
                            <?php echo $last_sync
                                ? esc_html( human_time_diff( $last_sync ) . ' ago' )
                                : esc_html__( 'Never', 'meiliwoo-search' ); ?>
                        </span>
                        <span class="meiliwoo-stat-label"><?php esc_html_e( 'Last sync', 'meiliwoo-search' ); ?></span>
                    </div>
                </div>

                <?php if ( $pending_count > 0 ) : ?>
                <div class="meiliwoo-progress-wrap">
                    <div class="meiliwoo-progress-bar" id="meiliwoo-progress-bar"></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Reindex button -->
            <div class="meiliwoo-card">
                <h3><?php esc_html_e( 'Full Reindex', 'meiliwoo-search' ); ?></h3>
                <p><?php esc_html_e( 'Queue all published content for reindexing. This runs in the background via Action Scheduler and does not affect your site performance.', 'meiliwoo-search' ); ?></p>
                <?php $this->settings_form_open( 'indexing' ); ?>
                <?php wp_nonce_field( 'meiliwoo_save_settings', 'meiliwoo_nonce' ); ?>
                <input type="hidden" name="meiliwoo_reindex" value="1" />
                <?php submit_button(
                    __( 'Reindex Everything', 'meiliwoo-search' ),
                    'primary large',
                    'submit',
                    false,
                    [ 'id' => 'meiliwoo-reindex-btn', 'disabled' => ! $this->client->is_connected() ]
                ); ?>
                </form>

                <?php if ( ! $this->client->is_connected() ) : ?>
                <p class="description" style="color:#c00">
                    <?php esc_html_e( 'Connect to Meilisearch first (Connection tab).', 'meiliwoo-search' ); ?>
                </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Settings form -->
        <div class="meiliwoo-card">
            <h3><?php esc_html_e( 'Indexing Configuration', 'meiliwoo-search' ); ?></h3>
            <?php $this->settings_form_open( 'indexing' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Content Types', 'meiliwoo-search' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" disabled checked />
                            <?php esc_html_e( 'WooCommerce Products (always enabled)', 'meiliwoo-search' ); ?>
                        </label><br>
                        <label>
                            <input type="checkbox" name="meiliwoo_index_posts"
                                   <?php checked( $this->settings->should_index_posts() ); ?> value="1" />
                            <?php esc_html_e( 'Regular Posts', 'meiliwoo-search' ); ?>
                        </label><br>
                        <label>
                            <input type="checkbox" name="meiliwoo_index_pages"
                                   <?php checked( $this->settings->should_index_pages() ); ?> value="1" />
                            <?php esc_html_e( 'Pages', 'meiliwoo-search' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="meiliwoo_batch_size"><?php esc_html_e( 'Batch Size', 'meiliwoo-search' ); ?></label></th>
                    <td>
                        <input type="number" id="meiliwoo_batch_size" name="meiliwoo_batch_size"
                               value="<?php echo esc_attr( $this->settings->get_batch_size() ); ?>"
                               min="10" max="500" step="10" class="small-text" />
                        <p class="description"><?php esc_html_e( 'Number of documents per batch (10–500). Default: 100.', 'meiliwoo-search' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php $this->settings_form_close(); ?>
        </div>
        <?php
    }
}
