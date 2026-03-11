# Goal Description
The Woo Update API plugin updates product prices and stock in real‑time by calling an external API on every WooCommerce price/stock filter. This causes unnecessary latency, especially because each request may trigger multiple API calls per product and the AJAX endpoint bypasses any caching.

## Proposed Changes
---
### Core Improvements
- **Introduce persistent object caching** using WordPress transients (or an external cache like Redis) to store API responses per SKU for a configurable short TTL (e.g., 5 minutes). This cache will be checked before any API request.
- **Refactor [class-price-updater.php](file:///c:/Users/julio/local-repos/woo-update/includes/class-price-updater.php) and related filter hooks** to use a shared cache layer (`Woo_Update_API_Cache`) that abstracts get/set logic, reducing duplicated code.
- **Update AJAX handler** to also use the persistent cache instead of always calling [get_product_data_direct](file:///c:/Users/julio/local-repos/woo-update/includes/class-api-handler.php#10-77).
- **Add a configuration option** in the plugin settings to enable/disable persistent caching and set the TTL.
- **Limit filter execution to front‑end only** by moving the `is_admin()` guard to a higher level and ensuring hooks are not added on admin pages.
- **Batch API requests** for multiple products when possible (e.g., when loading a product list) – feature not yet implemented in the API. Add an admin settings checkbox to enable this future functionality.

---
### Files to Modify / Add
- [includes/class-api-handler.php](file:///c:/Users/julio/local-repos/woo-update/includes/class-api-handler.php) – add batch method and cache lookup.
- [includes/class-price-updater.php](file:///c:/Users/julio/local-repos/woo-update/includes/class-price-updater.php) – replace direct cache calls with the new cache layer.
- [includes/class-ajax-handler.php](file:///c:/Users/julio/local-repos/woo-update/includes/class-ajax-handler.php) – use cached method.
- [admin/class-settings.php](file:///c:/Users/julio/local-repos/woo-update/admin/class-settings.php) – add settings fields for cache enable/TTL.
- Create new file `includes/class-cache.php` – encapsulates transient logic.

## Verification Plan
---
### Automated Tests
- **Unit test for `Woo_Update_API_Cache`**: verify that [set](file:///c:/Users/julio/local-repos/woo-update/woo-update-api.php#113-117) stores a transient and [get](file:///c:/Users/julio/local-repos/woo-update/woo-update-api.php#46-52) retrieves it within TTL, and returns false after expiration.
- **Integration test for price filter**: simulate a product request, ensure the API is called only once per SKU within the same request and subsequent requests hit the transient.
- **AJAX endpoint test**: send a mock AJAX request and assert that the response is served from cache after the first call.

*All tests will be placed under `tests/` using WP‑UnitTestCase and can be run with `phpunit`.*

### Manual Verification
1. Enable the plugin with caching turned on and set TTL to 1 minute.
2. Load a product page on the front‑end; observe the network tab – only one API call should be made.
3. Refresh the page within a minute; no new API call should appear.
4. Wait >1 minute, refresh again; a new API call should be made.
5. Verify that admin pages (product edit) do not trigger API calls.

---
