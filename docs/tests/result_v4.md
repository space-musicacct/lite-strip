# Test Results — LiteStrip v4 (Connect-time SSRF guard)

- **Date:** 2026-09-24
- **PHPUnit:** 13.2.0
- **PHP:** 8.5.10 (Docker: php:8.5-cli-alpine, image `nx-spacecom-lite-strip`)
- **Execution:** `docker compose exec lite-strip php vendor/bin/phpunit`
- **Result:** 156 tests, 212 assertions — **ALL PASSED**
  - 30 PHPUnit notices are reported. They are pre-existing / environmental (surfaced by PHP 8.5.10 + PHPUnit 13.2); `SafeConnector` and `BlockedNetworks` both run clean with **zero notices** in isolation, so none originate from the code added in this change.

---

## Changes from v3

| | v3 | v4 |
|---|---|---|
| Tests | 134 | 156 (+22) |
| Assertions | 183 | 212 (+29) |
| Test suites | 8 | 9 (+1) |
| New: SafeConnector | - | 7 tests |
| BlockedNetworks | 32 | 47 (+15) |

## Background

`UrlValidator` resolves a host with its own resolver and checks one A record, while the HTTP client's default connector resolves again, independently, and (via Happy Eyeballs) may dial any A/AAAA record. That time-of-check/time-of-use gap let a host pass validation while the connection went to a different, internal address (multiple A records, DNS rebinding, IPv4-mapped IPv6).

The fix routes every outbound connection through `SafeConnector`: it resolves the host itself, drops any IP on the blocklist, and dials only a vetted IP — so the address checked is exactly the address connected to. `BlockedNetworks` was also extended to cover ranges that were previously missing.

## SafeConnector (7 tests) — NEW in v4

| # | Test | Result |
|---|------|--------|
| 1 | Mixed A set `{public, internal}` → dials only the public IP, never the internal one | PASS |
| 2 | Preserves the original hostname (`?hostname=`) for TLS SNI / cert verification | PASS |
| 3 | Every resolved record blocked → connection rejected, nothing dialed | PASS |
| 4 | Host resolving to the Docker network (172.19.0.8) → rejected | PASS |
| 5 | Loopback IP literal (127.0.0.1) → rejected without dialing | PASS |
| 6 | IPv4-mapped IPv6 literal (`[::ffff:127.0.0.1]`) → rejected | PASS |
| 7 | Public IP literal (8.8.8.8) → passed through unchanged | PASS |

## BlockedNetworks additions (+15 cases)

| Family | Newly-covered ranges | Cases |
|--------|----------------------|-------|
| IPv4 | 192.88.99.0/24 (6to4 relay anycast), 224.0.0.0/4 (multicast), 240.0.0.0/4 (reserved, incl. 255.255.255.255) | 5 blocked |
| IPv6 | `::` (unspecified), `::ffff:0:0/96` (IPv4-mapped), `::/96` (IPv4-compatible), `64:ff9b::/96` (NAT64), `2002::/16` (6to4), `2001:db8::/32` (documentation), `ff00::/8` (multicast) | 8 blocked |
| IPv6 | IPv4-mapped / NAT64 wrapping a **public** address (`::ffff:8.8.8.8`, `64:ff9b::808:808`) stays allowed | 2 allowed |

For IPv4-mapped, IPv4-compatible, NAT64 and 6to4 addresses the embedded IPv4 address is re-checked against the IPv4 blocklist, so an internal target cannot be smuggled inside an IPv6 wrapper.

## Scope note

`SafeConnector` covers the page fetch, manual redirects, discovered API endpoints and external JS fetches (all share one `Browser`). The SPA path (headless Chromium) resolves and connects on its own and is **not** covered here; it is disabled in production (`ENABLE_SPA=false`).
