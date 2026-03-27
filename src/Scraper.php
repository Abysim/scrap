<?php

namespace App;

class Scraper
{
    private string $logPath;
    private string $curlPath;

    public function __construct()
    {
        $this->logPath = dirname(__DIR__) . '/logs/scraper.log';
        $this->curlPath = $_ENV['CURL_IMPERSONATE_PATH'] ?? (getenv('HOME') . '/bin/curl_chrome131');
    }

    public function scrape(string $url): array
    {
        $start = microtime(true);

        // Fast path: TLS impersonation
        $html = $this->fetchViaTls($url);
        if ($html !== null) {
            $this->log($url, 'tls', 200, $start);
            return ['html' => $html, 'method' => 'tls', 'error' => null];
        }

        // Heavy path: Chromium (Phase 1B — stub returns null)
        $html = $this->fetchViaBrowser($url);
        if ($html !== null) {
            $this->log($url, 'browser', 200, $start);
            return ['html' => $html, 'method' => 'browser', 'error' => null];
        }

        $this->log($url, null, 502, $start);
        return ['html' => null, 'method' => null, 'error' => 'All methods failed'];
    }

    public function fetchViaTls(string $url): ?string
    {
        if (!file_exists($this->curlPath)) {
            return null;
        }

        $cmd = sprintf(
            '%s -s -L -m 5 -o - %s 2>/dev/null',
            escapeshellarg($this->curlPath),
            escapeshellarg($url)
        );
        $html = shell_exec($cmd);
        if ($html !== null && strlen($html) >= 200) {
            return $html;
        }
        return null;
    }

    public function fetchViaBrowser(string $url): ?string
    {
        // Phase 1B: Chromium fallback — activated after TLS-only validation period
        // When enabled, this will:
        // 1. Check /proc/meminfo for available RAM (skip if <80MB)
        // 2. Check circuit breaker (skip if 3+ failures in last 10 min)
        // 3. Launch headless Chromium via chrome-php/chrome
        // 4. Navigate, wait for networkIdle, extract HTML
        // 5. Kill browser process in finally block
        return null;
    }

    private function log(string $url, ?string $method, int $status, float $start): void
    {
        $entry = json_encode([
            'time' => date('c'),
            'url' => $url,
            'method' => $method,
            'status' => $status,
            'duration_ms' => round((microtime(true) - $start) * 1000),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents($this->logPath, $entry . "\n", FILE_APPEND | LOCK_EX);
    }
}
