# JSON Product Importer for PrestaShop

A sandbox module for PrestaShop 8.2 that previews JSON product differences, applies updates to existing products, and records original values for guarded rollback.

**Current release: 0.4.0.** The internal module directory stays `jsonproductpreview` to support upgrades from the original preview module. This build deliberately runs only on the `test.4shooters.com` sandbox shop; it is not a general production importer.

## Install

1. Download `jsonproductpreview-0.4.0.zip` from [Releases](https://github.com/vm-cllevar/prestashop-json-product-importer/releases).
2. In PrestaShop, open **Modules → Module Manager → Upload a module** and upload the ZIP.
3. Select the sandbox shop and configure **JSON Product Importer**.
4. Upload a JSON file, review actual differences, and select **Apply updates** when ready.
5. Use **Recent batches** to inspect or roll back an applied batch.

Use **Full-file import jobs** for files up to 64 MiB / 100,000 records. The browser sends small requests; the server validates and previews 100-record chunks. The original small-file form still accepts 100 records / 1 MiB. Preview never changes catalog data. New products, ambiguous SKUs, and products with combinations are skipped.

## Full-file workflow

1. Choose the file under **Full-file import jobs** and press **Upload and preview full file**.
2. Review the full summary and use **Previous batch / Next batch** to inspect differences and errors.
3. Press **Apply reviewed updates** only after reviewing. Each transaction updates at most 100 products.
4. **Pause** stops after the current request. Select a saved job and **Load job** to resume. For an incomplete upload, reselect the identical file; its SHA-256 fingerprint must match.
5. **Roll back job** restores applied batches in reverse order. A conflict stops rollback; already restored batches remain restored. Resume after resolving the conflict.

Duplicate SKUs anywhere in the uploaded file exclude every occurrence, using the catalog's SKU collation. Apply is unavailable until the entire preview finishes. Upload retries are checked against saved chunk hashes. Per-job locks prevent overlapping requests, and committed batch statuses determine resume progress.

A full-file job is **atomic per batch, not across the whole file**. Earlier successful batches remain committed if a later one conflicts or fails. Job previews expire after 24 hours; small-file previews after one hour. Normalized input, preview results and rollback originals are retained in private database tables and survive module uninstall. Only the originating employee in the selected shop can operate a job.

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
php tests/jobs.php
python3 scripts/package.py
```

The installable ZIP is written to `dist/`. Tests use synthetic data; store exports, screenshots, and administrative URLs are not included.

## Verification status

Verified on PrestaShop 8.2.8: name, price and stock changes followed by rollback; a three-chunk job with two changed products; duplicate SKUs crossing chunk boundaries; and restoration confirmed by a fresh no-change preview. Local tests cover validation, field differences, stale conflicts, replay, transaction failure and chunk boundaries. Full-file upload/preview validation is recorded in the release notes.
