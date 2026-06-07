# Test Results — LiteStrip v1.0.0

- **Date:** 2026-06-08
- **PHPUnit:** 13.2.0
- **PHP:** 8.5.7 (Docker: php:8.5-cli-alpine)
- **Execution:** `docker compose exec lite-strip php vendor/bin/phpunit`
- **Result:** 106 tests, 145 assertions — **ALL PASSED**

---

## AllowedAttributes (10 tests)

| # | Test | Result |
|---|------|--------|
| 1 | Anchor allows href and rel | PASS |
| 2 | Img allows src and alt | PASS |
| 3 | Global attributes included | PASS |
| 4 | Unknown tag only has globals | PASS |
| 5 | Iframe safety transform | PASS |
| 6 | Form safety transform | PASS |
| 7 | No transform for regular tags | PASS |
| 8 | Table cell attributes | PASS |
| 9 | Form element attributes | PASS |
| 10 | Meta attributes | PASS |

## BlockedNetworks (32 tests)

### IPv4 Blocked (19 tests)

| # | Test | Result |
|---|------|--------|
| 1 | Loopback 127.0.0.1 | PASS |
| 2 | Loopback 127.255.255.255 | PASS |
| 3 | Class A private 10.0.0.1 | PASS |
| 4 | Class A private 10.255.255.255 | PASS |
| 5 | Class B private 172.16.0.1 | PASS |
| 6 | Class B private 172.31.255.255 | PASS |
| 7 | Class C private 192.168.0.1 | PASS |
| 8 | Class C private 192.168.255.255 | PASS |
| 9 | Link-local 169.254.0.1 | PASS |
| 10 | Cloud metadata 169.254.169.254 | PASS |
| 11 | Current network 0.0.0.0 | PASS |
| 12 | Current network 0.255.255.255 | PASS |
| 13 | Shared address space 100.64.0.1 | PASS |
| 14 | Shared address space 100.127.255.255 | PASS |
| 15 | IETF protocol 192.0.0.1 | PASS |
| 16 | TEST-NET-1 192.0.2.1 | PASS |
| 17 | Benchmarking 198.18.0.1 | PASS |
| 18 | TEST-NET-2 198.51.100.1 | PASS |
| 19 | TEST-NET-3 203.0.113.1 | PASS |

### IPv4 Allowed (5 tests)

| # | Test | Result |
|---|------|--------|
| 1 | Google DNS 8.8.8.8 | PASS |
| 2 | Cloudflare DNS 1.1.1.1 | PASS |
| 3 | Public 93.184.216.34 | PASS |
| 4 | Just outside class B 172.32.0.1 | PASS |
| 5 | Just outside shared 100.128.0.1 | PASS |

### IPv6 Blocked (5 tests)

| # | Test | Result |
|---|------|--------|
| 1 | Loopback ::1 | PASS |
| 2 | ULA fd00::1 | PASS |
| 3 | ULA fc00::1 | PASS |
| 4 | Link-local fe80::1 | PASS |
| 5 | Link-local fe80::abcd:1234 | PASS |

### IPv6 Allowed (2 tests)

| # | Test | Result |
|---|------|--------|
| 1 | Google DNS 2001:4860:4860::8888 | PASS |
| 2 | Cloudflare 2606:4700:4700::1111 | PASS |

### Edge Cases (1 test)

| # | Test | Result |
|---|------|--------|
| 1 | Blocks invalid input (non-IP string, empty string) | PASS |

## UrlValidator (25 tests)

### URL Format Validation (9 tests)

| # | Test | Result |
|---|------|--------|
| 1 | Accepts valid HTTPS URL | PASS |
| 2 | Accepts HTTP URL | PASS |
| 3 | Rejects empty URL | PASS |
| 4 | Rejects FTP scheme | PASS |
| 5 | Rejects javascript: scheme | PASS |
| 6 | Rejects data: scheme | PASS |
| 7 | Rejects URL with credentials | PASS |
| 8 | Rejects URL with username only | PASS |
| 9 | Rejects too long URL (>2048 chars) | PASS |

### SSRF Protection (7 tests)

| # | Test | Result |
|---|------|--------|
| 1 | Blocks private IP directly (192.168.1.1) | PASS |
| 2 | Blocks localhost directly (127.0.0.1) | PASS |
| 3 | Blocks metadata endpoint (169.254.169.254) | PASS |
| 4 | Blocks DNS resolving to private IP | PASS |
| 5 | Blocks DNS resolving to localhost | PASS |
| 6 | Blocks DNS resolving to metadata | PASS |
| 7 | Blocks DNS resolution failure | PASS |

### Same-Origin Check (5 tests)

| # | Test | Result |
|---|------|--------|
| 1 | Matches same origin exactly | PASS |
| 2 | Rejects different scheme | PASS |
| 3 | Rejects different host | PASS |
| 4 | Rejects different port | PASS |
| 5 | Handles default ports (443/80) | PASS |

### URL Resolution (4 tests)

| # | Test | Result |
|---|------|--------|
| 1 | Resolves absolute URL (passthrough) | PASS |
| 2 | Resolves root-relative URL (/path) | PASS |
| 3 | Resolves relative URL (path) | PASS |
| 4 | Resolves protocol-relative URL (//host) | PASS |

## DomProcessor (20 tests)

### Attribute Stripping (4 tests)

| # | Test | Result |
|---|------|--------|
| 1 | Strips class attribute | PASS |
| 2 | Strips id attribute | PASS |
| 3 | Strips style attribute | PASS |
| 4 | Strips data-* attributes | PASS |

### Attribute Preservation (5 tests)

| # | Test | Result |
|---|------|--------|
| 1 | Preserves href on `<a>` | PASS |
| 2 | Preserves src and alt on `<img>` | PASS |
| 3 | Preserves datetime on `<time>` | PASS |
| 4 | Preserves colspan/rowspan on `<td>` | PASS |
| 5 | Preserves lang attribute | PASS |

### Safety Transforms (2 tests)

| # | Test | Result |
|---|------|--------|
| 1 | Transforms `<iframe src>` to `data-src` | PASS |
| 2 | Transforms `<form action/method>` to `data-action/data-method` | PASS |

### Element Removal (5 tests)

| # | Test | Result |
|---|------|--------|
| 1 | Removes `<script>` tags | PASS |
| 2 | Removes `<style>` tags | PASS |
| 3 | Removes `<noscript>` tags | PASS |
| 4 | Removes `<link rel="stylesheet">` | PASS |
| 5 | Removes HTML comments | PASS |

### Empty Element Handling (4 tests)

| # | Test | Result |
|---|------|--------|
| 1 | Removes empty `<div>` | PASS |
| 2 | Keeps empty `<img>` (void element) | PASS |
| 3 | Keeps empty `<br>` (void element) | PASS |
| 4 | Keeps empty `<hr>` (void element) | PASS |

## ScriptAnalyzer (19 tests)

### Pattern Detection (7 tests)

| # | Test | Result |
|---|------|--------|
| 1 | Detects fetch() with single quotes | PASS |
| 2 | Detects fetch() with double quotes | PASS |
| 3 | Detects fetch() with backticks | PASS |
| 4 | Detects axios.get() | PASS |
| 5 | Detects $.get() (jQuery) | PASS |
| 6 | Detects $.getJSON() (jQuery) | PASS |
| 7 | Detects absolute URL in fetch() | PASS |

### Template Literal Handling (2 tests)

| # | Test | Result |
|---|------|--------|
| 1 | Strips ${...} variables from relative URL | PASS |
| 2 | Strips ${...} variables from absolute URL | PASS |

### Exclusion Filters (3 tests)

| # | Test | Result |
|---|------|--------|
| 1 | Excludes tracking pixels (/pixel, /beacon, /analytics) | PASS |
| 2 | Excludes CDN paths (/cdn-cgi/) | PASS |
| 3 | Excludes source maps (.map) | PASS |

### HTML Integration (3 tests)

| # | Test | Result |
|---|------|--------|
| 1 | Extracts endpoints from inline `<script>` | PASS |
| 2 | Ignores external `<script src>` in HTML extraction | PASS |
| 3 | Extracts script src attribute values | PASS |

### Edge Cases (4 tests)

| # | Test | Result |
|---|------|--------|
| 1 | Deduplicates identical endpoints | PASS |
| 2 | Handles empty code input | PASS |
| 3 | Handles code with no fetch calls | PASS |
| 4 | Rejects root slash "/" as endpoint | PASS |
