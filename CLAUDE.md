# Project: Scrap (VPS Scraper API)

## CRITICAL: Production Safety
- **This project runs directly on production VPS (vps-web). There is no staging environment.**
- **Root commands (`sudo`, `apt install`) require interactive password** — never automate without user confirmation
- **PHP-FPM `disable_functions` was modified globally** in `/etc/php/8.4/fpm/php.ini` to enable `shell_exec`/`exec`/`proc_open`. Hestia panel updates may revert this — if scraping stops working, check `disable_functions` first.

## Overview
Lightweight self-hosted scraping API that bypasses bot detection via TLS fingerprint impersonation.
Returns raw HTML for a given URL. Used as a fallback step in the Laravel `api` project's news content fetching pipeline (before paid ScraperAPI).

## Tech Stack
- **Language**: PHP 8.4
- **Dependencies**: `vlucas/phpdotenv`, `chrome-php/chrome`
- **External binaries**: `curl-impersonate-chrome` at `~/bin/curl_chrome131` (TLS fingerprint), Chrome for Testing at `~/bin/chrome-for-testing/chrome` (headless rendering)
- **No framework** — bare PHP with PSR-4 autoloading

## Architecture

### Hybrid Two-Tier Fetch
1. **Fast path (TLS impersonation):** `curl-impersonate` binary mimics Chrome's TLS fingerprint (JA3/JA4). ~5MB RAM, 2-5 seconds.
2. **Heavy path (Chromium):** Headless Chromium via `chrome-php/chrome`. Handles JS challenges (Cloudflare Under Attack). ~300MB RAM (swap), 5-30 seconds. Includes pre-flight memory check (<100MB available = skip), orphaned process cleanup, and NETWORK_IDLE wait for post-JS redirects.

### Content Detection (`isBlockPage`)
Before returning HTML, the TLS path checks for insufficient content:
- **Block page markers**: Cloudflare ("just a moment", "ray id:"), PerimeterX, Akamai, generic ("access denied", "bot protection") — checked in first 4KB
- **JS skeleton / empty page detection**: strips `<script>` and `<style>` content from full HTML, then checks if first 32KB of cleaned HTML has <200 chars of visible text. Catches JS-rendered SPAs (thestreet.com at 776B, reuters.com at 774B) and large JS apps (sports.yahoo.com at 1.6MB but 85 chars visible text)
- When detected, `fetchViaTls()` returns null → browser fallback gets a chance (Phase 1B) → if browser also fails → 502

### Staged Rollout
- **Phase 1A:** TLS impersonation via curl-impersonate.
- **Phase 1B (active):** Headless Chromium fallback via chrome-php/chrome. Activated 2026-04-02 after confirming 22.3% 502 rate (132/593 requests). Chrome for Testing v147 installed on VPS. Disable by removing `CHROMIUM_PATH` from `.env`.

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
│   │   └── Scraper.php
│   ├── public/
│   │   ├── index.php     ← entry point (symlinked from public_html)
│   │   └── .htaccess
│   ├── vendor/
│   ├── logs/
│   │   └── scraper.log   ← application log (inside open_basedir)
│   ├── composer.json
│   └── .env
├── public_html/          ← Apache document root
│   ├── index.php         ← symlink → ../private/public/index.php
│   └── .htaccess         ← symlink → ../private/public/.htaccess
└── logs/                 ← Hestia apache logs (NOT writable by PHP)
```

### Deploy steps
```bash
ssh vps-web "cd ~/web/scrap.abysim.com/private && git pull origin master"
# Only if composer.json changed:
ssh vps-web "cd ~/web/scrap.abysim.com/private && composer install --no-dev"
```

### Environment
- `.env` on VPS: `~/web/scrap.abysim.com/private/.env` (contains `API_KEY`, `CURL_IMPERSONATE_PATH`, `CHROMIUM_PATH`)
- `curl-impersonate` binary: `~/bin/curl_chrome131`
- Chrome for Testing binary: `~/bin/chrome-for-testing/chrome` (v147, installed via static download -- not snap)
- Composer: `/usr/local/bin/composer` (in PATH)
- Root access: `sudo` requires password (interactive). Only needed for `apt install` (Phase 1B Chromium).

## API

### Endpoints
- `GET /scrape?url=<URL>&api_key=<KEY>` — Fetch and return raw HTML
- `GET /health` — Health check (returns JSON `{"status":"ok","time":"..."}`)

### Response codes
- `200` — Success, body is raw HTML, `X-Scrape-Method` header indicates `tls` or `browser`
- `400` — Missing/invalid URL, SSRF attempt, or DNS resolution failure
- `401` — Invalid or missing API key
- `502` — All fetch methods failed (block page, JS skeleton, timeout, or curl error)
- `503` — Server busy (concurrent request limit), includes `Retry-After: 10`

### Security
- **Timing-safe** API key comparison via `hash_equals()`
- **DNS pinning**: resolved IP passed to curl via `--resolve` flags (prevents DNS rebinding TOCTOU)
- **SSRF prevention**: rejects private/reserved IPs, non-http(s) schemes, fails closed on DNS failure
- **File lock** (`flock`) ensures single concurrent request
- **Project files** in `private/` directory (not web-accessible via Apache)

## Consumer
- **Laravel `api` project** (`/DATA/xampp/htdocs/api/`) calls this service as step 4 in `FreeNewsService::extractContent()`, before ScraperAPI (step 5).
- Config on bigcats: `VPSCRAPER_URL=https://scrap.abysim.com/scrape`, `VPSCRAPER_KEY=<api-key>`
- Timeout: 55 seconds from Laravel side (bumped from 20s for Phase 1B browser path)
- DailyStat counter: `fetch_vps_scraper`

## VPS Constraints
- **454MB RAM** (162MB free), full Hestia stack (nginx, apache, mariadb, php-fpm, mail, DNS, FTP)
- **1 CPU core**, 2.2GB disk free
- **1GB swap** (42% used)
- All Hestia services must stay active — scraper must coexist
- Sequential processing only — never run concurrent Chromium instances

## Local Development
- **PHP CLI:** Use `p` (PHP 8.4) instead of `php` (PHP 7.2 XAMPP). `p` is at `/usr/local/bin/p`. Use for syntax checks (`p -l`), composer (`p /usr/local/bin/composer`), and all CLI operations.

## Gotchas
- **`open_basedir`** restricts PHP file access to `private/`, `public_html/`, and system paths. `~/bin/` and `~/web/scrap.abysim.com/logs/` are NOT accessible. That's why logs go to `private/logs/` and `file_exists()` cannot check the curl binary path.
- **`shell_exec` was disabled by default** in Hestia's PHP-FPM. Fixed in global `/etc/php/8.4/fpm/php.ini`. Per-pool `php_admin_value[disable_functions]` does NOT work to re-enable functions — PHP removes them from the function table at startup.
- **Hestia pool config** at `/etc/php/8.4/fpm/pool.d/scrap.abysim.com.conf` says "DO NOT MODIFY" — Hestia regenerates it from templates. Custom per-pool settings should use Hestia custom templates.
- **`strip_tags()` keeps script content as text** — must `preg_replace` `<script>` and `<style>` blocks before `strip_tags()` for accurate text measurement.
- **Regex on truncated HTML breaks** — if you `substr()` mid-`<script>` tag, `preg_replace('/<script>.*?<\/script>/s')` can't find the closing tag. Always strip scripts from full HTML, then truncate the cleaned result.
- **OPcache** may serve stale PHP after deploys. Default `revalidate_freq` is 2s, so changes take effect within seconds.

## Logs
- **Application log**: `~/web/scrap.abysim.com/private/logs/scraper.log`
- **Format**: one-line JSON per request: `{"time":"...","url":"...","method":"tls|browser|null","status":200|502,"duration_ms":123}`
- **Log rotation**: weekly crontab truncation if >10MB
- **Apache access/error logs**: `~/web/scrap.abysim.com/logs/` (symlinks to `/var/log/apache2/domains/`)

### Investigating logs via SSH
```bash
# View recent requests
ssh vps-web "tail -20 ~/web/scrap.abysim.com/private/logs/scraper.log"

# TLS success rate
ssh vps-web "grep -c '\"method\":\"tls\"' ~/web/scrap.abysim.com/private/logs/scraper.log"

# Failures
ssh vps-web "grep '\"method\":null' ~/web/scrap.abysim.com/private/logs/scraper.log"

# Total requests
ssh vps-web "wc -l ~/web/scrap.abysim.com/private/logs/scraper.log"

# Check memory (Phase 1B)
ssh vps-web "free -m"

# Check for OOM kills
ssh vps-web "dmesg | grep -i oom | tail -5"

# Check PHP-FPM disable_functions (if scraping breaks)
ssh vps-web "php -r \"echo ini_get('disable_functions');\""
```

### Checking consumer-side logs (bigcats)
```bash
# VPS Scraper results in Laravel pipeline
ssh bigcats "grep 'VPS Scraper' ~/api/storage/logs/laravel.log | tail -20"

# Successes vs failures
ssh bigcats "grep -c 'VPS Scraper fetched HTML' ~/api/storage/logs/laravel.log"
ssh bigcats "grep -c 'VPS Scraper failed' ~/api/storage/logs/laravel.log"
ssh bigcats "grep -c 'VPS Scraper busy' ~/api/storage/logs/laravel.log"
ssh bigcats "grep -c 'VPS Scraper HTML fetched but extraction failed' ~/api/storage/logs/laravel.log"
```
