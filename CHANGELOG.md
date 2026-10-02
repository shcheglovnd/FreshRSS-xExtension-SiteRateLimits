# Changelog

## 0.1.0 — 2026-10-02

First release.

* Background refreshes wait until a site welcomes the next request, instead of failing with `429 Too Many Requests`. The wait follows per-site rules (`reddit.com 60` by default) or the site's rate-limit headers (`X-RateLimit-*`, `RateLimit-*`).
* One more request after a 429, after a short `Retry-After` or the site's rule.
* Feeds whose site needs more than 2 minutes are left for a later refresh, without error.
* Refreshes from the web interface never wait.
* English and Russian translations.
