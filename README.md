# JSON Product Importer for PrestaShop

A sandbox module for PrestaShop 8.2 that previews JSON product differences, applies updates to existing products, and records original values for guarded rollback.

**Current release: 0.3.2.** The internal module directory stays `jsonproductpreview` to support upgrades from the original preview module. This build deliberately runs only on the `test.4shooters.com` sandbox shop; it is not a general production importer.

## Install

1. Download `jsonproductpreview-0.3.2.zip` from [Releases](https://github.com/vm-cllevar/prestashop-json-product-importer/releases).
2. In PrestaShop, open **Modules → Module Manager → Upload a module** and upload the ZIP.
3. Select the sandbox shop and configure **JSON Product Importer**.
4. Upload a JSON file, review actual differences, and select **Apply updates** when ready.
5. Use **Recent batches** to inspect or roll back an applied batch.

Upload limits: 100 records, 1 MiB. Preview never changes catalog data. New products, ambiguous SKUs, and products with combinations are skipped.

## JSON formats

```json
[
  {"reference":"DEMO-001","name":"Sample product","price":19.95,"stock":5}
]
```

The approved-matches shape is also supported:

```json
[
  {
    "sku":"DEMO-001",
    "title":"Sample product",
    "retail_price":19.95,
    "suppliers":[{"stock":null}]
  }
]
```

`sku` maps to reference, `title` to name, and `retail_price` to base price without conversion. Existing checkout tax rules remain unchanged. Missing/null stock stays unchanged; explicit numeric zero means zero. Multiple suppliers and availability flags do not become stock quantities. Other metadata is ignored.

## Apply and rollback

- Only unique existing SKU matches in the selected shop are eligible.
- Only changed name, price, and supplied stock fields are written.
- Previews expire after one hour and are bound to the employee and shop.
- Apply locks and rechecks product identity and expected values before writing.
- Catalog changes and journal status are committed in one InnoDB transaction.
- Rollback restores only the batch's changed fields. Later edits to those fields cause a conflict instead of being overwritten.
- Repeated apply/rollback submissions are rejected.

Shared or managed stock, packs, missing stock records, unit pricing, and inconsistent base-price mirrors require manual review. See the [module documentation](jsonproductpreview/README.md) for implementation details and limitations. Journal history survives module uninstall. External stock-hook effects, search indexing, caches, and modification timestamps are not covered by catalog-field rollback.

## Development

Requires PHP 8.1+ for local tests and Python 3 for packaging. A running PrestaShop installation is not needed for the unit tests.

```sh
php tests/validator.php
php tests/source.php
php tests/updates.php
php tests/render.php
python3 scripts/package.py
```

The installable ZIP is written to `dist/`. Tests use synthetic data; store exports, screenshots, and administrative URLs are not included.

## Verification status

Verified on PrestaShop 8.2.8: module installation, actual-difference preview for 100 records, a single name update, a fresh no-change preview, and restoration through rollback. Price/stock behavior, conflicts, replay, and transactional failure handling have local test coverage; price and stock writes have not yet been exercised live.
