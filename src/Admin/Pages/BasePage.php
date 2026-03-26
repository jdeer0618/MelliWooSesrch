<?php
/**
 * Abstract base for admin tab pages.
 *
 * @package MeiliWoo\Search\Admin\Pages
 */

declare(strict_types=1);

namespace MeiliWoo\Search\Admin\Pages;

/**
 * Each tab page extends this to implement render() and optionally handle_post().
 */
abstract class BasePage {

    abstract public function render(): void;

    public function handle_post( array $data ): void {
        // Override in subclasses that have saveable settings.
    }

    // ── Shared rendering helpers ───────────────────────────────────────────

    protected function settings_form_open( string $tab ): void {
        $nonce = wp_create_nonce( 'meiliwoo_save_settings' );
        printf(
            '<form method="post" action="%s"><input type="hidden" name="meiliwoo_nonce" value="%s"><input type="hidden" name="tab" value="%s">',
            esc_url( admin_url( 'admin.php?page=meiliwoo-search&tab=' . $tab ) ),
            esc_attr( $nonce ),
            esc_attr( $tab )
        );
    }

    protected function settings_form_close( string $button_label = '' ): void {
        if ( '' === $button_label ) {
            $button_label = __( 'Save Settings', 'meiliwoo-search' );
        }
        submit_button( $button_label );
        echo '</form>';
    }

    protected function notice( string $message, string $type = 'success' ): void {
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr( $type ),
            esc_html( $message )
        );
    }

    protected function card( string $title, string $content, string $class = '' ): void {
        printf(
            '<div class="meiliwoo-card %s"><h3>%s</h3>%s</div>',
            esc_attr( $class ),
            esc_html( $title ),
            wp_kses_post( $content )
        );
    }
}
