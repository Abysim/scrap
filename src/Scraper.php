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

    public function scrape(string $url, ?string $resolvedIp = null, bool $render = false): array
    {
        $start = microtime(true);

        // If render=true, skip TLS and go straight to browser (Phase 1B)
        if (!$render) {
            $html = $this->fetchViaTls($url, $resolvedIp);
            if ($html !== null) {
                $this->log($url, 'tls', 200, $start);
                return ['html' => $html, 'method' => 'tls', 'error' => null];
            }
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
        if ($html !== null && strlen($html) >= 200 && !$this->isUnusablePage($html)) {
            return $html;
        }
        return null;
    }

    /**
     * Tiered detection based on crawl4ai's antibot_detector approach.
     * Size-gated to avoid false positives on large real pages.
     */
    private function isUnusablePage(string $html): bool
    {
        $len = strlen($html);
        $head = strtolower(substr($html, 0, 4096));

        // Tier 1: Provider-specific markers — checked on ANY page size.
        // These are highly specific and essentially never appear in real content.
        $tier1 = [
            // Cloudflare
            '__cf_chl_f_tk=',
            'cf-error-code',
            '/cdn-cgi/challenge-platform/',
            // PerimeterX / HUMAN Security
            'window._pxappid',
            'captcha.px-cdn.net',
            // DataDome
            'captcha-delivery.com',
            // Imperva / Incapsula
            '_incapsula_resource',
            'incapsula incident id',
            // Akamai
            'pardon our interruption',
            // Sucuri WAF
            'sucuri website firewall',
            // Kasada
            'kpsdk.scriptstart',
            // Generic
            'blocked by network security',
            // Cloudflare legacy (still on some edge configs)
            'ray id:',
            'cf-browser-verification',
            'ddos protection by',
        ];
        foreach ($tier1 as $marker) {
            if (str_contains($head, $marker)) {
                return true;
            }
        }

        // Akamai reference number pattern
        if (preg_match('/reference\s*#\d+\.[0-9a-f]+\.\d+\.[0-9a-f]+/i', $head)) {
            return true;
        }

        // Tier 2: Ambiguous markers — only on small pages (< 10KB).
        // These strings can appear in nav/footer of large real pages.
        if ($len < 10240) {
            $tier2 = [
                'just a moment',
                'checking your browser',
                'access to this page has been denied',
                'attention required',
                'are you a human',
                'bot protection',
                'enable javascript and cookies',
                'perimeterx',
                'px-captcha',
                'g-recaptcha',
                'h-captcha',
                'request unsuccessful',
            ];
            foreach ($tier2 as $marker) {
                if (str_contains($head, $marker)) {
                    return true;
                }
            }
        }

        // Tier 3: Structural check — only on pages < 50KB.
        // Large pages (50KB+) are clearly not skeletons — skip expensive regex.
        if ($len < 51200) {
            // Strip scripts/styles from full HTML (truncating mid-tag breaks regex)
            $cleaned = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
            $cleaned = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $cleaned ?? '');

            // Check visible text in first 32KB of cleaned HTML
            $visibleText = trim(strip_tags(substr($cleaned ?? '', 0, 32768)));

            if (strlen($visibleText) < 50) {
                return true;
            }

            // No semantic content elements = JS skeleton (even if some nav text exists)
            if (!preg_match('/<(p|h[1-6]|article|section|li|td|blockquote|pre)\b/i', $cleaned ?? '')) {
                return true;
            }
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
