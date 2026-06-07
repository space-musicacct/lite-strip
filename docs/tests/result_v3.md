# Test Results — LiteStrip v3 (Post-release security fix)

- **Date:** 2026-06-08
- **PHPUnit:** 13.2.0
- **PHP:** 8.5.7 (Docker: php:8.5-cli-alpine)
- **Execution:** `docker compose exec lite-strip php vendor/bin/phpunit`
- **Result:** 134 tests, 183 assertions — **ALL PASSED**

---

## Changes from v2

| | v2 | v3 |
|---|---|---|
| Tests | 129 | 134 (+5) |
| Assertions | 174 | 183 (+9) |
| Test suites | 7 | 8 (+1) |
| New: HtmlFetcherRedirectSsrf | - | 5 tests |

## HtmlFetcherRedirectSsrf (5 tests) — NEW in v3

| # | Test | Result |
|---|------|--------|
| 1 | Redirect to private IP (127.0.0.1) is blocked | PASS |
| 2 | Redirect to metadata endpoint (169.254.169.254) is blocked | PASS |
| 3 | Redirect to internal hostname (DNS → private IP) is blocked | PASS |
| 4 | Safe redirect (public → public) is allowed | PASS |
| 5 | Fetcher without validator allows all redirects (backward compat) | PASS |
