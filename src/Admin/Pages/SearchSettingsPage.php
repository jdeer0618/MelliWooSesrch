<?php
/**
 * Search settings tab (ranking rules, synonyms, field boosts).
 *
 * @package MeiliWoo\Search\Admin\Pages
 */

declare(strict_types=1);

namespace MeiliWoo\Search\Admin\Pages;

use MeiliWoo\Search\Admin\SettingsManager;
use MeiliWoo\Search\Client\MeilisearchClient;

class SearchSettingsPage extends BasePage {

    private const DEFAULT_RANKING_RULES = [
        'words', 'typo', 'proximity', 'attribute', 'sort', 'exactness',
    ];

    private const DEFAULT_SEARCHABLE = [
        'title', 'sku', 'excerpt', 'content', 'categories', 'tags',
    ];

    public function __construct(
        private readonly MeilisearchClient $client,
        private readonly SettingsManager   $settings
    ) {}

    public function handle_post( array $data ): void {
        // Ranking rules: a newline-separated ordered list.
        if ( isset( $data['meiliwoo_ranking_rules'] ) ) {
            $rules = array_filter( array_map( 'sanitize_text_field', explode( "\n", wp_unslash( $data['meiliwoo_ranking_rules'] ) ) ) );
            $this->settings->set( 'ranking_rules', array_values( $rules ) );
        }

        // Searchable fields.
        if ( isset( $data['meiliwoo_searchable_fields'] ) ) {
            $fields = array_filter( array_map( 'sanitize_text_field', (array) $data['meiliwoo_searchable_fields'] ) );
            $this->settings->set( 'searchable_fields', array_values( $fields ) );
        }

        // Synonyms: one synonym group per line, terms separated by comma.
        if ( isset( $data['meiliwoo_synonyms'] ) ) {
            $synonyms = [];
            foreach ( explode( "\n", wp_unslash( $data['meiliwoo_synonyms'] ) ) as $line ) {
                $terms = array_filter( array_map( 'sanitize_text_field', explode( ',', $line ) ) );
                if ( count( $terms ) >= 2 ) {
                    $terms = array_values( $terms );
                    // Build bidirectional synonyms.
                    foreach ( $terms as $term ) {
                        $synonyms[ $term ] = $terms;
                    }
                }
            }
            $this->settings->set( 'synonyms', $synonyms );
        }

        $this->settings->set( 'typo_tolerance', ! empty( $data['meiliwoo_typo_tolerance'] ) );

        // Push updated settings to Meilisearch.
        if ( $this->client->is_connected() ) {
            $this->client->apply_index_settings();
        }

        add_action( 'admin_notices', function () {
            $this->notice( __( 'Search settings saved and applied to Meilisearch.', 'meiliwoo-search' ) );
        } );
    }

    public function render(): void {
        $ranking_rules     = $this->settings->get_ranking_rules();
        $searchable_fields = $this->settings->get_searchable_fields();
        $synonyms          = $this->settings->get_synonyms();

        // Convert synonyms array back to display format.
        $synonym_lines = [];
        $seen          = [];
        foreach ( $synonyms as $term => $equivalents ) {
            $key = implode( ',', $equivalents );
            if ( ! isset( $seen[ $key ] ) ) {
                $synonym_lines[] = implode( ', ', $equivalents );
                $seen[ $key ]    = true;
            }
        }

        $all_searchable = [
            'title'       => __( 'Title', 'meiliwoo-search' ),
            'content'     => __( 'Description', 'meiliwoo-search' ),
            'excerpt'     => __( 'Short Description', 'meiliwoo-search' ),
            'sku'         => __( 'SKU', 'meiliwoo-search' ),
            'categories'  => __( 'Categories', 'meiliwoo-search' ),
            'tags'        => __( 'Tags', 'meiliwoo-search' ),
            'attributes'  => __( 'Attributes', 'meiliwoo-search' ),
        ];

        ?>
        <?php $this->settings_form_open( 'search' ); ?>

        <div class="meiliwoo-row">
            <!-- Field priority -->
            <div class="meiliwoo-card">
                <h3><?php esc_html_e( 'Searchable Fields', 'meiliwoo-search' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Fields are searched in order (top = highest boost).', 'meiliwoo-search' ); ?></p>
                <ul class="meiliwoo-sortable" id="meiliwoo-field-order">
                    <?php foreach ( $all_searchable as $field => $label ) :
                        $checked = in_array( $field, $searchable_fields, true );
                    ?>
                    <li class="meiliwoo-sortable-item" data-field="<?php echo esc_attr( $field ); ?>">
                        <span class="dashicons dashicons-menu meiliwoo-drag-handle"></span>
                        <label>
                            <input type="checkbox" name="meiliwoo_searchable_fields[]"
                                   value="<?php echo esc_attr( $field ); ?>"
                                   <?php checked( $checked ); ?> />
                            <?php echo esc_html( $label ); ?>
                        </label>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Ranking rules -->
            <div class="meiliwoo-card">
                <h3><?php esc_html_e( 'Ranking Rules', 'meiliwoo-search' ); ?></h3>
                <p class="description">
                    <?php esc_html_e( 'One rule per line, in priority order. Standard Meilisearch rules + custom sort fields.', 'meiliwoo-search' ); ?>
                    <a href="https://www.meilisearch.com/docs/learn/relevancy/ranking_rules" target="_blank" rel="noopener">
                        <?php esc_html_e( 'Docs ↗', 'meiliwoo-search' ); ?>
                    </a>
                </p>
                <textarea name="meiliwoo_ranking_rules" rows="10" class="large-text code"><?php
                    echo esc_textarea( implode( "\n", $ranking_rules ) );
                ?></textarea>
                <button type="button" class="button" id="meiliwoo-reset-ranking">
                    <?php esc_html_e( 'Reset to Default', 'meiliwoo-search' ); ?>
                </button>
                <input type="hidden" id="meiliwoo-default-ranking"
                       value="<?php echo esc_attr( implode( "\n", self::DEFAULT_RANKING_RULES ) ); ?>" />
            </div>
        </div>

        <div class="meiliwoo-row">
            <!-- Synonyms -->
            <div class="meiliwoo-card">
                <h3><?php esc_html_e( 'Synonyms', 'meiliwoo-search' ); ?></h3>
                <p class="description">
                    <?php esc_html_e( 'One synonym group per line. Separate terms with commas. Example: t-shirt, tee, tshirt', 'meiliwoo-search' ); ?>
                </p>
                <textarea name="meiliwoo_synonyms" rows="8" class="large-text code"><?php
                    echo esc_textarea( implode( "\n", $synonym_lines ) );
                ?></textarea>
            </div>

            <!-- Options -->
            <div class="meiliwoo-card">
                <h3><?php esc_html_e( 'Search Options', 'meiliwoo-search' ); ?></h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><?php esc_html_e( 'Typo Tolerance', 'meiliwoo-search' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="meiliwoo_typo_tolerance"
                                       value="1" <?php checked( $this->settings->get( 'typo_tolerance', true ) ); ?> />
                                <?php esc_html_e( 'Enable fuzzy / typo-tolerant matching', 'meiliwoo-search' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Autocomplete', 'meiliwoo-search' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="meiliwoo_autocomplete_enabled"
                                       value="1" <?php checked( $this->settings->autocomplete_enabled() ); ?> />
                                <?php esc_html_e( 'Enable live autocomplete REST endpoint', 'meiliwoo-search' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <?php $this->settings_form_close( __( 'Save & Apply to Meilisearch', 'meiliwoo-search' ) ); ?>
        <?php
    }
}
