/**
 * MeiliWoo Search – Admin JavaScript
 *
 * Handles: connection testing, reindex progress polling,
 * test-search UI, ranking rule reset, and sortable lists.
 */
/* global meiliwooAdmin, jQuery */

(function ($) {
    'use strict';

    const api  = window.meiliwooAdmin || {};
    const rest = api.restUrl || '/wp-json/meiliwoo/v1/';
    const nonce = api.nonce || '';

    /**
     * Fetch wrapper using the WP REST nonce.
     */
    function apiFetch(path, options = {}) {
        return fetch(rest + path, {
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce,
            },
            ...options,
        }).then((r) => r.json());
    }

    // ── Connection test button ─────────────────────────────────────────────

    $('#meiliwoo-test-connection').on('click', function () {
        const $btn = $(this);
        $btn.prop('disabled', true)
            .html('<span class="meiliwoo-spinner"></span>' + (api.i18n.connecting || 'Testing…'));

        apiFetch('index/status')
            .then((data) => {
                if (data.connected) {
                    $btn.html('✓ ' + (api.i18n.connected || 'Connected'));
                    $btn.removeClass('button-secondary').addClass('button-primary');
                } else {
                    $btn.html('✗ ' + (api.i18n.disconnected || 'Disconnected'));
                }
            })
            .catch(() => {
                $btn.html('✗ ' + (api.i18n.disconnected || 'Disconnected'));
            })
            .finally(() => {
                $btn.prop('disabled', false);
                setTimeout(() => $btn.html('Test Connection'), 4000);
            });
    });

    // ── Reindex button with progress polling ──────────────────────────────

    $('#meiliwoo-reindex-btn').on('click', function (e) {
        if (!confirm(api.i18n.confirmReindex || 'Reindex all content?')) {
            e.preventDefault();
            return;
        }
    });

    // Poll for indexing progress if batches are queued.
    function pollProgress() {
        const $bar = $('#meiliwoo-progress-bar');
        if (!$bar.length) return;

        apiFetch('index/progress').then((data) => {
            const total   = data.pending + data.running;
            const percent = total > 0 ? Math.max(5, 100 - (data.pending / (total + 1)) * 100) : 100;
            $bar.css('width', percent + '%');

            if (data.pending > 0 || data.running > 0) {
                setTimeout(pollProgress, 3000);
            } else {
                $bar.css({ width: '100%', animation: 'none' });
                $bar.closest('.meiliwoo-progress-wrap')
                    .after('<p class="description" style="color:#00a32a">✓ ' + (api.i18n.indexComplete || 'Index complete!') + '</p>');
            }
        });
    }

    if ($('#meiliwoo-progress-bar').length) {
        pollProgress();
    }

    // ── Ranking rules reset ────────────────────────────────────────────────

    $('#meiliwoo-reset-ranking').on('click', function () {
        const defaultRules = $('#meiliwoo-default-ranking').val();
        $('textarea[name="meiliwoo_ranking_rules"]').val(defaultRules);
    });

    // ── Test search ────────────────────────────────────────────────────────

    let searchTimeout;

    $('#meiliwoo-run-test').on('click', runTestSearch);
    $('#meiliwoo-test-query').on('keypress', function (e) {
        if (e.which === 13) runTestSearch();
    });

    function runTestSearch() {
        const query = $('#meiliwoo-test-query').val().trim();
        if (!query) return;

        const $results = $('#meiliwoo-test-results');
        const $inner   = $('#meiliwoo-test-results-inner');

        $results.show();
        $inner.html('<span class="meiliwoo-spinner"></span> Searching…');

        apiFetch('search?q=' + encodeURIComponent(query) + '&limit=10')
            .then((data) => {
                if (!data.hits || data.hits.length === 0) {
                    $inner.html('<p><em>No results found.</em></p>');
                    return;
                }

                const timeMs = data.processingTimeMs || 0;
                let html = `<p><small>${data.totalHits || data.hits.length} result(s) in ${timeMs}ms</small></p>`;

                data.hits.forEach((hit) => {
                    const title     = (hit._formatted && hit._formatted.title) || escapeHtml(hit.title || '');
                    const imgHtml   = hit.image ? `<img src="${escapeHtml(hit.image)}" alt="" />` : '<div style="width:48px;height:48px;background:#f0f0f1;border-radius:4px;flex-shrink:0"></div>';
                    const sku       = hit.sku ? ` · SKU: ${escapeHtml(hit.sku)}` : '';
                    const price     = hit.price != null ? ` · $${parseFloat(hit.price).toFixed(2)}` : '';
                    const editLink  = hit.edit_link ? ` <a href="${escapeHtml(hit.edit_link)}" target="_blank">Edit ↗</a>` : '';

                    html += `
                    <div class="meiliwoo-test-result-item">
                        ${imgHtml}
                        <div>
                            <div class="result-title">${title}${editLink}</div>
                            <div class="result-meta">${escapeHtml(hit.type || '')}${sku}${price}</div>
                        </div>
                    </div>`;
                });

                $inner.html(html);
            })
            .catch((err) => {
                $inner.html('<p style="color:#d63638">Error: ' + escapeHtml(String(err)) + '</p>');
            });
    }

    // ── Sortable fields ────────────────────────────────────────────────────

    if ($.fn.sortable && $('#meiliwoo-field-order').length) {
        $('#meiliwoo-field-order').sortable({
            handle: '.meiliwoo-drag-handle',
            axis:   'y',
            update: function () {
                // Values are collected on form submit via standard checkbox inputs.
            },
        });
    }

    // ── Facets: check-all ──────────────────────────────────────────────────

    $('#meiliwoo-check-all').on('change', function () {
        $('input[name="meiliwoo_facets[]"]').prop('checked', $(this).is(':checked'));
    });

    // ── Utility ────────────────────────────────────────────────────────────

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

})(jQuery);
