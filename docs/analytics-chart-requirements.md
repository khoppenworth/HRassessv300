# Analytics chart requirements

The analytics dashboard renders questionnaire and work-function heatmaps in the browser using
[Chart.js](https://www.chartjs.org/). The JavaScript bundle is downloaded from the CDN by default
and will automatically fall back to the local `assets/adminlte/plugins/chart.js/Chart.min.js`
package when the CDN is blocked.

To ensure the heatmaps display correctly, make sure the server environment satisfies the following
requirements:

1. **HTTPS access to the CDN** – allow outbound HTTPS requests to `cdn.jsdelivr.net`. The page
   includes an integrity attribute, so the CDN response must not be modified in transit.
2. **Local asset availability** – if the CDN cannot be reached (for example, on an air-gapped
   network) the fallback bundle must be present and readable at
   `<web_root>/assets/adminlte/plugins/chart.js/Chart.min.js`.
3. **Writable cache directory** – PHP needs permission to write to the cache directory configured
   in `config.php` so the analytics query results can be stored between requests.
4. **Database access** – the analytics queries depend on the reporting views added during the
   v3.0 schema migration. Run the migrations in `migration.sql` and ensure the database user has
   permission to read the materialized views that back the heatmaps.
5. **Content Security Policy (CSP)** – if a CSP header is configured, it must allow scripts that
   carry the generated nonce value and permit loading from the CDN domain. The application injects
   the nonce automatically via `csp_nonce()`.

With these prerequisites in place, the browser will either download Chart.js from the CDN or load
it from the fallback location, and the updated renderer ensures each heatmap canvas is given an
explicit size so the charts are drawn immediately once data is available.
