# MeiliWoo Search

**High-performance, self-hostable Meilisearch-powered search for WordPress and WooCommerce.**

[![CI](https://github.com/jdeer0618/melliwoosesrch/actions/workflows/ci.yml/badge.svg)](https://github.com/jdeer0618/melliwoosesrch/actions/workflows/ci.yml)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://php.net)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

---

## Features

- **Zero theme changes** – intercepts WP_Query, returns `post__in` ordered by Meilisearch relevance.
- **Full WooCommerce support** – products, variations, stock, prices, attributes as facets.
- **Self-hostable** – one-command Docker setup, no cloud required.
- **Real-time + batch indexing** – Action Scheduler powered, 100k products in < 15 min.
- **Typo tolerance, synonyms, facets, ranking rules** – all configurable via admin UI.
- **Fallback** – silently falls back to MySQL if Meilisearch is unreachable.
- **WP-CLI** – `wp meiliwoo index --all --sync` for automated deployments.

---

## Requirements

| Component     | Version         |
|---------------|-----------------|
| PHP           | 8.1+            |
| WordPress     | 6.8+            |
| WooCommerce   | 10.0+           |
| Meilisearch   | v1.9+ (v1.x)   |

---

## Quick Start

### 1. Start Meilisearch

```bash
# Copy the env file and set your master key
cp .env.example .env   # edit MEILISEARCH_MASTER_KEY

docker compose up -d
```

### 2. Install the plugin

```bash
# Via Composer (recommended)
composer require jdeer0618/meiliwoo-search

# Or upload the ZIP via WP Admin → Plugins → Add New
```

### 3. Install PHP dependencies

```bash
cd wp-content/plugins/meiliwoo-search
composer install --no-dev --optimize-autoloader
```

### 4. Connect

**Option A – WP-CLI:**
```bash
wp meiliwoo connect --host=http://localhost:7700 --key=YOUR_MASTER_KEY
wp meiliwoo index --all --sync
```

**Option B – Admin UI:**
Go to **WooCommerce → MeiliWoo Search → Connection** and enter your host + API key.

---

## WP-CLI Commands

```bash
wp meiliwoo status                        # Show connection status and stats
wp meiliwoo connect --host=URL --key=KEY  # Save credentials and connect
wp meiliwoo index --all                   # Schedule full reindex (async)
wp meiliwoo index --all --sync            # Full reindex (synchronous, shows progress)
wp meiliwoo index --post-id=123           # Index a single post
wp meiliwoo search "blue jeans"           # Test search from CLI
wp meiliwoo flush --yes                   # Delete all documents
wp meiliwoo reset --yes                   # Reset all settings
```

---

## Admin UI

**WooCommerce → MeiliWoo Search** (or **Settings → MeiliWoo Search** if WooCommerce is inactive):

| Tab              | Description                                              |
|------------------|----------------------------------------------------------|
| Connection       | Host, API key, connection status, test button            |
| Indexing         | Reindex button, stats, batch size, content-type toggles  |
| Search Settings  | Field order, ranking rules, synonyms, typo tolerance     |
| Facets & Widgets | Enable/disable facet attributes for Layered Nav          |
| Logs & Test      | Live test search, indexing log, log clear                |

---

## Developer Filters

```php
// Modify a document before indexing
add_filter( 'meiliwoo_document', function( array $doc, WP_Post $post ): array {
    $doc['my_custom_field'] = get_post_meta( $post->ID, 'my_field', true );
    return $doc;
}, 10, 2 );

// Modify Meilisearch search params per query
add_filter( 'meiliwoo_search_params', function( array $params, WP_Query $query ): array {
    $params['limit'] = 20;
    return $params;
}, 10, 2 );

// Add custom facet attributes
add_filter( 'meiliwoo_facet_attributes', function( array $attrs ): array {
    $attrs[] = [ 'key' => 'my_custom_meta', 'label' => 'My Attribute', 'source' => 'custom' ];
    return $attrs;
} );

// Modify ranking rules programmatically
add_filter( 'meiliwoo_ranking_rules', function( array $rules ): array {
    return array_merge( $rules, [ 'popularity:desc' ] );
} );

// Skip MeiliWoo interception for a specific query
add_action( 'pre_get_posts', function( WP_Query $query ) {
    if ( $some_condition ) {
        $query->set( 'meiliwoo_skip', true );
    }
} );
```

---

## Data Model

Each Meilisearch document:

```json
{
  "id": "post-123",
  "type": "product",
  "parent_id": 0,
  "title": "Blue Denim Jeans",
  "content": "...",
  "excerpt": "...",
  "sku": "JEANS-001",
  "price": 49.99,
  "price_min": 39.99,
  "price_max": 59.99,
  "sale_price": 39.99,
  "stock_status": "instock",
  "stock_quantity": 100,
  "categories": ["Clothing", "Bottoms"],
  "tags": ["denim", "casual"],
  "attributes": { "color": ["Blue"], "size": ["M", "L", "XL"] },
  "image": "https://...",
  "permalink": "https://...",
  "date": "2026-01-01T00:00:00Z",
  "popularity": 1243
}
```

Product **variations** are indexed as separate documents with `type: "variation"` and `parent_id` set, enabling per-variation stock/price filtering.

---

## Running Tests

```bash
# Unit tests (no WP or Meilisearch required)
composer test -- --testsuite unit

# Integration tests (requires Meilisearch running on :7700)
docker compose up -d
composer test -- --testsuite integration

# Code style
composer cs
```

---

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
