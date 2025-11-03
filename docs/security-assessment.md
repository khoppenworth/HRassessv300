# Security assessment – HRassess v3.0.1

Date: 2025-11-03

## Scope
- Public landing and authentication endpoints (e.g. `index.php`, `login.php`, `oauth.php`).
- Shared bootstrap (`config.php`) and supporting libraries used by all user-facing requests.

## Summary of findings
- **Strengths**: The application enables strict transport security, a Content Security Policy with nonces, and disables dangerous legacy browser features via modern security headers.
- **Gap**: No request throttling was in place. This left `login.php`, the API explorer, and other heavy database-backed pages vulnerable to resource exhaustion via repeated unauthenticated requests, increasing the impact of volumetric or application-layer DDoS attacks.

## Remediation
- Added a lightweight IP-based rate limiter that enforces a rolling quota (default 240 requests per minute) before sessions and database connections are initialised. Responses over the limit receive HTTP 429 with a `Retry-After` header.
- Exposed `RATE_LIMIT_REQUESTS` and `RATE_LIMIT_WINDOW_SECONDS` environment variables so operators can tune limits to their deployment size.

## Residual risk & recommendations
- The file-backed limiter is suitable for single-node deployments. For clustered environments, migrate the limiter storage to a shared cache such as Redis or Memcached to retain global visibility of request volume.
- Continue to deploy the service behind a network-layer DDoS mitigation provider (cloud WAF or CDN) to block volumetric floods before they hit the origin.
