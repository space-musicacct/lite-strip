# LiteStrip

Lightweight AI-optimized HTML extraction tool powered by PHP and ReactPHP.

LiteStrip takes a URL and returns clean, structured HTML with all styling attributes stripped — optimized for LLM/AI consumption. It also detects `fetch()` API endpoints in JavaScript and automatically follows them to include dynamic data.

## Features

- **Attribute Stripping** — Removes `class`, `id`, `style`, `data-*` while preserving semantic attributes (`href`, `src`, `alt`, `datetime`, etc.)
- **API Auto-Detection** — Finds `fetch()` / `axios.get()` endpoints in `<script>` tags and fetches their data automatically
- **Template Literal Support** — Handles `fetch(\`/api/data?page=\${page}\`)` by extracting the base URL
- **Multiple Output Formats** — JSON (default), HTML, Markdown
- **SPA Rendering** — Optional headless Chromium for JavaScript-rendered pages (`is_spa=true`)
- **SSRF Protection** — DNS resolution + IP blocklist + redirect validation built-in
- **PHP Complete** — No Node.js, no Python, just PHP + ReactPHP
- **Docker Ready** — Single `docker compose up` to run

## Quick Start

```bash
git clone https://github.com/space-musicacct/lite-strip.git
cd lite-strip
docker compose up -d
```

```bash
# JSON output (default)
curl "http://localhost:8080/?url=https://example.com"

# HTML output
curl "http://localhost:8080/?url=https://example.com&format=html"

# Markdown output
curl "http://localhost:8080/?url=https://example.com&format=markdown"

# With SPA rendering (requires ENABLE_SPA=true)
curl "http://localhost:8080/?url=https://example.com&is_spa=true"
```

## API Reference

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET / | `?url=...` | Process URL (query params) |
| POST / | JSON body | Process URL (JSON body) |
| GET /health | | Health check |
| GET /docs | | API documentation page |

### Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `url` | string | (required) | Target URL |
| `format` | string | `json` | Output format: `json`, `html`, `markdown` |
| `follow_apis` | bool | `true` | Detect and follow API endpoints in scripts |
| `is_full` | bool | `false` | Include `<head>` metadata in output |
| `is_spa` | bool | `false` | Render via headless Chromium (requires `ENABLE_SPA=true`) |
| `timeout` | int | `15` | Request timeout in seconds (max 30) |
| `max_apis` | int | `5` | Max API endpoints to follow (max 10) |

### JSON Response

```json
{
  "ok": true,
  "data": {
    "url": "https://example.com",
    "finalUrl": "https://example.com",
    "title": "Example Domain",
    "contentHtml": "<h1>Example Domain</h1><p>...</p>",
    "contentText": "Example Domain ...",
    "meta": { "description": "...", "language": "en", "ogImage": null },
    "detectedApis": ["https://example.com/api/data"],
    "apiData": [{ "url": "...", "status": 200, "contentType": "application/json", "data": {} }],
    "failedApis": [],
    "warnings": []
  },
  "stats": {
    "fetchedAt": "2026-06-07T22:00:00+09:00",
    "fetchTimeMs": 230,
    "processTimeMs": 45,
    "totalTimeMs": 275,
    "apisDiscovered": 1,
    "apisFollowed": 1,
    "apisSucceeded": 1,
    "originalSizeBytes": 45230,
    "outputSizeBytes": 3200
  }
}
```

### Error Response

```json
{
  "ok": false,
  "error": {
    "code": "BLOCKED_URL",
    "message": "The requested URL resolves to a private network address"
  }
}
```

| Code | HTTP | Description |
|------|------|-------------|
| `INVALID_URL` | 400 | Missing or malformed URL |
| `INVALID_PARAMETER` | 400 | Invalid parameter type or range |
| `BLOCKED_URL` | 403 | SSRF protection triggered |
| `UNSUPPORTED_SCHEME` | 422 | Non http/https URL |
| `RATE_LIMITED` | 429 | Rate limit exceeded |
| `FETCH_FAILED` | 502 | Upstream request failed |
| `RESPONSE_TOO_LARGE` | 502 | Response exceeds 2 MB |
| `SPA_DISABLED` | 501 | SPA mode not enabled on this instance |
| `SPA_QUEUE_FULL` | 503 | SPA render queue is full |
| `TIMEOUT` | 504 | Upstream request timed out |

## Configuration

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `PORT` | `8080` | HTTP server port |
| `TZ` | `UTC` | Timezone |
| `ENABLE_SPA` | `true` | Enable SPA rendering mode |
| `CHROMIUM_HOST` | - | Chromium container hostname (required for SPA) |
| `CHROMIUM_PORT` | `9222` | Chromium DevTools Protocol port |

### Docker Compose

The default `docker-compose.yml` includes both LiteStrip and a Chromium container for SPA rendering.

To run without SPA support (lighter):

```yaml
services:
  lite-strip:
    build: .
    ports:
      - "8080:8080"
    environment:
      - ENABLE_SPA=false
```

## How It Works

```
URL input
  |
  v
URL Validation (SSRF check: DNS resolve -> IP blocklist)
  |
  v
HTML Fetch (ReactPHP HTTP Client, non-blocking)
  |
  v
Script Analysis (regex: fetch(), axios.get(), $.get(), $.getJSON())
  |
  v
API Following (parallel async requests, same-origin GET only)
  |
  v
Content Extraction (body content, or full page with is_full=true)
  |
  v
DOM Processing (strip attributes via allowlist, remove script/style/noscript)
  |
  v
Output Formatting (JSON / HTML / Markdown)
```

### Attribute Allowlist

Preserved attributes:

| Attribute | Elements |
|-----------|----------|
| `href` | `<a>` |
| `src` | `<img>`, `<video>`, `<audio>`, `<source>` |
| `alt` | `<img>` |
| `datetime` | `<time>` |
| `lang`, `dir` | any |
| `colspan`, `rowspan` | `<td>`, `<th>` |
| `type` | `<input>`, `<button>`, `<ol>` |
| `name`, `value`, `placeholder` | form elements |
| `content`, `charset` | `<meta>` |
| `rel` | `<link>`, `<a>` |

Safety transforms (to prevent browser execution):
- `<iframe src>` becomes `<iframe data-src>`
- `<form action>` becomes `<form data-action>`

## Tech Stack

- **PHP 8.5** (cli-alpine)
- **ReactPHP** — HTTP server + async HTTP client
- **DOMDocument** — HTML parsing and attribute manipulation
- **league/html-to-markdown** — Markdown output
- **chrome-php/chrome** — Chrome DevTools Protocol (SPA mode)
- **zenika/alpine-chrome** — Headless Chromium container (SPA mode)

## Comparison

| Tool | Language | Browser Required | Output | PHP Native |
|------|----------|-----------------|--------|------------|
| Jina Reader | TypeScript | Yes (Chrome) | Markdown | No |
| Firecrawl | JS/Python | Yes | Markdown/JSON | No |
| Crawl4AI | Python | Yes (Playwright) | Markdown | No |
| Trafilatura | Python | No | Text/HTML | No |
| **LiteStrip** | **PHP** | **Optional** | **HTML/JSON/MD** | **Yes** |

## License

[MIT](LICENSE)
