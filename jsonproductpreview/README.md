# JSON Product Importer 0.3.2

PrestaShop 8.2 sandbox module. Internal ID remains `jsonproductpreview` for in-place upgrades. Writes are restricted to the selected `test.4shooters.com` shop and require module configuration/product edit permission, POST, and CSRF validation.

Upload a JSON array (1–100 rows, up to 1 MiB). Supports reference/name/price/optional stock, or approved-matches sku/title/retail_price/suppliers. Retail price is used directly as the base price; checkout tax rules remain unchanged. Missing/null stock and ambiguous supplier quantities remain unchanged. Explicit numeric zero is an actual quantity.

Preview compares normalized six-decimal prices, names, and supplied stock. Only actual differences become an immutable, one-hour preview batch. Unchanged records, unknown/ambiguous SKUs, combinations and unit-priced products are excluded. Shared/managed/missing stock and packs require manual review for stock changes. Existing products only; no creation.

Apply locks and rechecks all changed fields and SKU identities, then writes the batch and its audit status in one InnoDB transaction. Repeated requests are rejected. Rollback checks that changed fields still equal the applied values, then restores their originals atomically. Unrelated later changes are preserved. A conflict aborts the whole batch. History belongs to the employee/shop and is retained on uninstall in PREFIX_jsonproductpreview_batch; no public export endpoint is registered.

Product name and price use narrowly scoped PrestaShop Db writes, avoiding Product::update's cross-shop unit-ratio changes and broad product-save hooks. The default-shop base-price mirror is maintained. Stock uses StockAvailable::setQuantity with stock movement logging disabled (the module journal records old/new quantities). Product/search caches are refreshed after commit. Search-index/cache maintenance and any external stock-hook side effects are outside the transaction; rollback covers the recorded catalog fields, not external integrations or modification timestamps.

Install/update through Modules > Module Manager > Upload a module. Select 4Shooters, configure, upload JSON, inspect differences, then explicitly apply. Use Recent batches to inspect and roll back applied batches. The original source upload is temporary; normalized changed values are retained in the journal. No cron, product import on upload, or external requests.

Local checks: `php tests/validator.php`, `php tests/source.php`, `php tests/updates.php` from the project root. Runtime adapter is verified separately in the sandbox.

Live verification on PrestaShop 8.2.8 (2026-10-18): first100 → 1 actual name whitespace difference, 96 unchanged, 2 ambiguous SKUs skipped, 1 missing SKU invalid. Applied the single name correction for SKU 108442; fresh preview → 0 updates and 97 unchanged. Rolled the batch back successfully. Prices and stock were not written during this live test. Local tests cover price normalization, stock zero, stale conflicts, transaction failure, replay, scope and rollback; price/stock writes still need dedicated live verification before broader use.
