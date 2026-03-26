<?php
/**
 * Facets & Widgets settings tab.
 *
 * @package MeiliWoo\Search\Admin\Pages
 */

declare(strict_types=1);

namespace MeiliWoo\Search\Admin\Pages;

use MeiliWoo\Search\Admin\SettingsManager;
use MeiliWoo\Search\Client\MeilisearchClient;

class FacetsPage extends BasePage {

    public function __construct(
        private readonly MeilisearchClient $client,
        private readonly SettingsManager   $settings
    ) {}

    public function handle_post( array $data ): void {
        $selected = isset( $data['meiliwoo_facets'] ) ? array_map( 'sanitize_key', (array) $data['meiliwoo_facets'] ) : [];
        $this->settings->set( 'facetable_attributes', $selected );

        if ( $this->client->is_connected() ) {
            $this->client->apply_index_settings();
        }

        add_action( 'admin_notices', function () {
            $this->notice( __( 'Facet settings saved and applied.', 'meiliwoo-search' ) );
        } );
    }

    public function render(): void {
        $enabled   = $this->settings->get_facetable_attributes();
        $available = $this->get_available_attributes();

        ?>
        <div class="meiliwoo-card">
            <h3><?php esc_html_e( 'Facetable Attributes', 'meiliwoo-search' ); ?></h3>
            <p class="description">
                <?php esc_html_e( 'Enable attributes as facets. Enabled facets become filterable in Meilisearch and can be used in WooCommerce Layered Nav widgets.', 'meiliwoo-search' ); ?>
            </p>
            <?php $this->settings_form_open( 'facets' ); ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <td class="check-column"><input type="checkbox" id="meiliwoo-check-all" /></td>
                        <th><?php esc_html_e( 'Attribute', 'meiliwoo-search' ); ?></th>
                        <th><?php esc_html_e( 'Source', 'meiliwoo-search' ); ?></th>
                        <th><?php esc_html_e( 'Facet Key', 'meiliwoo-search' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $available as $attr ) : ?>
                    <tr>
                        <td class="check-column">
                            <input type="checkbox" name="meiliwoo_facets[]"
                                   value="<?php echo esc_attr( $attr['key'] ); ?>"
                                   <?php checked( in_array( $attr['key'], $enabled, true ) ); ?> />
                        </td>
                        <td><?php echo esc_html( $attr['label'] ); ?></td>
                        <td><span class="meiliwoo-badge"><?php echo esc_html( $attr['source'] ); ?></span></td>
                        <td><code><?php echo esc_html( $attr['key'] ); ?></code></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if ( empty( $available ) ) : ?>
                    <tr>
                        <td colspan="4"><em><?php esc_html_e( 'No WooCommerce attributes found. Create some at Products → Attributes.', 'meiliwoo-search' ); ?></em></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php $this->settings_form_close( __( 'Save Facets', 'meiliwoo-search' ) ); ?>
        </div>

        <div class="meiliwoo-card">
            <h3><?php esc_html_e( 'Layered Navigation Integration', 'meiliwoo-search' ); ?></h3>
            <p>
                <?php esc_html_e( 'MeiliWoo Search automatically integrates with WooCommerce Layered Nav widgets and blocks. Enable facets above, then add the Layered Navigation widget/block in your store sidebar.', 'meiliwoo-search' ); ?>
            </p>
            <p class="description">
                <?php esc_html_e( 'Facet counts are powered by Meilisearch\'s facetDistribution feature and update in real time.', 'meiliwoo-search' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Get list of all facetable attributes from WooCommerce.
     */
    private function get_available_attributes(): array {
        $attrs = [];

        // Built-in facets.
        $attrs[] = [ 'key' => 'categories',   'label' => __( 'Product Categories', 'meiliwoo-search' ), 'source' => 'core' ];
        $attrs[] = [ 'key' => 'tags',          'label' => __( 'Product Tags', 'meiliwoo-search' ),       'source' => 'core' ];
        $attrs[] = [ 'key' => 'stock_status',  'label' => __( 'Stock Status', 'meiliwoo-search' ),       'source' => 'core' ];

        // WooCommerce global attributes.
        if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
            foreach ( wc_get_attribute_taxonomies() as $taxonomy ) {
                $label   = $taxonomy->attribute_label;
                // Use attribute_name (e.g. 'color') not wc_attribute_taxonomy_name ('pa_color')
                // so the key matches what DocumentBuilder stores in the index.
                $key     = 'attributes.' . sanitize_key( $taxonomy->attribute_name );
                $attrs[] = [ 'key' => $key, 'label' => $label, 'source' => 'woocommerce' ];
            }
        }

        /**
         * Filter the list of available facetable attributes.
         *
         * @param array $attrs Array of [ key, label, source ] items.
         */
        return apply_filters( 'meiliwoo_facet_attributes', $attrs );
    }
}
