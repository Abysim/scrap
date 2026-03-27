# Project: Scrap (VPS Scraper API)

## Overview
Lightweight self-hosted scraping API that bypasses bot detection via TLS fingerprint impersonation.
Returns raw HTML for a given URL. Used as a fallback step in the Laravel `api` project's news content fetching pipeline (before paid ScraperAPI).

## Tech Stack
- **Language**: PHP 8.4
- **Dependencies**: `vlucas/phpdotenv` (Phase 1A), `chrome-php/chrome` (Phase 1B, conditional)
- **External binary**: `curl-impersonate-chrome` (~10MB, provides Chrome TLS fingerprint)
- **No framework** — bare PHP with PSR-4 autoloading

## Architecture

### Hybrid Two-Tier Fetch
1. **Fast path (TLS impersonation):** `curl-impersonate` binary mimics Chrome's TLS fingerprint (JA3/JA4). Handles ~80-90% of bot-blocked sites. ~5MB RAM, 2-5 seconds.
2. **Heavy path (Chromium):** Headless Chromium via `chrome-php/chrome`. Handles JS challenges (Cloudflare Under Attack). ~300MB RAM (swap), 10-30 seconds. Phase 1B — activated only after TLS-only success rate validation.

### Staged Rollout
- **Phase 1A (current):** TLS-only. `fetchViaBrowser()` is a stub returning null.
- **Phase 1B (conditional):** Add Chromium after 1+ week if TLS success rate <70%.

## Deployment

### Production server
- **Host:** `ssh vps-web` (Ubuntu 22.04, 454MB RAM, 1 CPU)
- **User:** `hestia`
- **Web panel:** Hestia Control Panel
- **Domain:** `scrap.abysim.com`

### Directory layout on VPS
```
~/web/scrap.abysim.com/
├── private/              ← git repo clone (project root)
│   ├── src/
│   ├── vendor/
│   ├── composer.json
│   └── .env
├── public_html/          ← Apache document root
│   ├── index.php         ← symlink → ../private/public/index.php
│   └── .htaccess         ← symlink → ../private/public/.htaccess
└── logs/                 ← application logs
    └── scraper.log
```

### Deploy steps
```bash
ssh vps-web "cd ~/web/scrap.abysim.com/private && git pull"
ssh vps-web "cd ~/web/scrap.abysim.com/private && composer install --no-dev"
```

### Environment
- `.env` on VPS: `~/web/scrap.abysim.com/private/.env` (contains `API_KEY`, `CURL_IMPERSONATE_PATH`)
- `curl-impersonate` binary: `~/bin/curl_chrome131`
- Composer: `/usr/local/bin/composer` (in PATH)
- Root access: `sudo` requires password (interactive). Only needed for `apt install` (Phase 1B Chromium).

## API

### Endpoints
- `GET /scrape?url=<URL>&api_key=<KEY>` — Fetch and return raw HTML
- `GET /health` — Health check (returns JSON `{"status":"ok","time":"..."}`)

### Response codes
- `200` — Success, body is raw HTML, `X-Scrape-Method` header indicates `tls` or `browser`
- `400` — Missing/invalid URL or SSRF attempt
- `401` — Invalid or missing API key
- `502` — All fetch methods failed
- `503` — Server busy (concurrent request limit), includes `Retry-After: 10`

### Security
- Shared API key authentication (`?api_key=` parameter)
- SSRF prevention: rejects private/reserved IPs, non-http(s) schemes
- File lock ensures single concurrent request (prevents Chromium memory overload)
- Project files in `private/` directory (not web-accessible)

## Consumer
- **Laravel `api` project** (`/DATA/xampp/htdocs/api/`) calls this service as step 4 in `FreeNewsService::extractContent()`, before ScraperAPI (step 5).
- Config: `VPSCRAPER_URL=https://scrap.abysim.com/scrape`, `VPSCRAPER_KEY=<api-key>`
- Timeout: 20 seconds from Laravel side

## VPS Constraints
- **454MB RAM** (162MB free), full Hestia stack (nginx, apache, mariadb, php-fpm, mail, DNS, FTP)
- **1 CPU core**, 2.2GB disk free
- **1GB swap** (42% used)
- All Hestia services must stay active — scraper must coexist
- Sequential processing only — never run concurrent Chromium instances

## Logs
- Application log: `~/web/scrap.abysim.com/logs/scraper.log`
- One-line JSON per request: `{"time":"...","url":"...","method":"tls|browser|null","status":200|502,"duration_ms":123}`
- Log rotation: weekly crontab truncation if >10MB

## Monitoring
- TLS success rate: `grep '"method":"tls"' logs/scraper.log | wc -l`
- Total requests: `wc -l logs/scraper.log`
- Failures: `grep '"method":null' logs/scraper.log | wc -l`
- Memory during Chromium (Phase 1B): `free -m` + `dmesg | grep -i oom`
