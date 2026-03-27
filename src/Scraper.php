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

    public function scrape(string $url, ?string $resolvedIp = null): array
    {
        $start = microtime(true);

        $html = $this->fetchViaTls($url, $resolvedIp);
        if ($html !== null) {
            $this->log($url, 'tls', 200, $start);
            return ['html' => $html, 'method' => 'tls', 'error' => null];
        }

        $html = $this->fetchViaBrowser($url);
        if ($html !== null) {
            $this->log($url, 'browser', 200, $start);
            return ['html' => $html, 'method' => 'browser', 'error' => null];
        }

        $this->log($url, null, 502, $start);
        return ['html' => null, 'method' => null, 'error' => 'All methods failed'];
    }

    private function fetchViaTls(string $url, ?string $resolvedIp = null): ?string
    {
        // Pin DNS to the already-validated IP to prevent DNS rebinding (TOCTOU)
        $resolve = '';
        if ($resolvedIp !== null) {
            $host = parse_url($url, PHP_URL_HOST);
            if ($host !== null) {
                $resolve = sprintf(
                    '--resolve %s:80:%s --resolve %s:443:%s',
                    escapeshellarg($host), escapeshellarg($resolvedIp),
                    escapeshellarg($host), escapeshellarg($resolvedIp)
                );
            }
        }

        $cmd = sprintf(
            '%s -s -L -m 5 %s -o - %s 2>/dev/null',
            escapeshellarg($this->curlPath),
            $resolve,
            escapeshellarg($url)
        );
        $html = shell_exec($cmd);
        if ($html !== null && strlen($html) >= 200 && !$this->isBlockPage($html)) {
            return $html;
        }
        return null;
    }

    private function isBlockPage(string $html): bool
    {
        $lower = strtolower(substr($html, 0, 4096));

        $markers = [
            'access to this page has been denied',
            'attention required',
            'just a moment',
            'checking your browser',
            'enable javascript and cookies',
            'ray id:',
            'cf-browser-verification',
            'perimeterx',
            'px-captcha',
            'are you a human',
            'bot protection',
            'ddos protection by',
        ];

        foreach ($markers as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        // Strip script/style from full HTML (truncating mid-tag breaks regex)
        $cleaned = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $cleaned = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $cleaned);
        $textLen = strlen(trim(strip_tags(substr($cleaned, 0, 16384))));
        if ($textLen < 200) {
            return true;
        }

        return false;
    }

    private function fetchViaBrowser(string $url): ?string
    {
        // Phase 1B: headless Chromium fallback (not yet enabled)
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
