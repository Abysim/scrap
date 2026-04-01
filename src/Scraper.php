<?php

namespace App;

class Scraper
{
    // Tier 1: Provider-specific markers — checked on ANY page size.
    private const TIER1_MARKERS = [
        '__cf_chl_f_tk=', 'cf-error-code', '/cdn-cgi/challenge-platform/',  // Cloudflare
        'window._pxappid', 'captcha.px-cdn.net',                            // PerimeterX / HUMAN
        'captcha-delivery.com',                                              // DataDome
        '_incapsula_resource', 'incapsula incident id',                      // Imperva
        'pardon our interruption',                                           // Akamai
        'sucuri website firewall',                                           // Sucuri WAF
        'kpsdk.scriptstart',                                                 // Kasada
        'blocked by network security',                                       // Generic
        'ray id:', 'cf-browser-verification', 'ddos protection by',          // Cloudflare legacy
    ];

    // Tier 2: Ambiguous markers — only on small pages (< 10KB).
    private const TIER2_MARKERS = [
        'just a moment', 'checking your browser', 'access to this page has been denied',
        'attention required', 'are you a human', 'bot protection',
        'enable javascript and cookies', 'perimeterx', 'px-captcha',
        'g-recaptcha', 'h-captcha', 'request unsuccessful',
    ];

    private string $logPath;
    private string $curlPath;
    private string $chromiumPath;

    public function __construct()
    {
        $this->logPath = dirname(__DIR__) . '/logs/scraper.log';
        $this->curlPath = $_ENV['CURL_IMPERSONATE_PATH'] ?? (getenv('HOME') . '/bin/curl_chrome131');
        $this->chromiumPath = $_ENV['CHROMIUM_PATH'] ?? '';
    }

    public function scrape(string $url, ?string $resolvedIp = null, bool $render = false, bool $raw = false): array
    {
        $start = microtime(true);

        if ($raw) {
            return $this->fetchRaw($url, $resolvedIp, $start);
        }

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

    private function fetchRaw(string $url, ?string $resolvedIp, float $start): array
    {
        $body = $this->curlFetch($url, $resolvedIp, 10);
        if ($body !== null && strlen($body) > 0) {
            $this->log($url, 'raw', 200, $start);
            return ['html' => $body, 'method' => 'raw', 'error' => null];
        }

        $this->log($url, null, 502, $start);
        return ['html' => null, 'method' => null, 'error' => 'Raw fetch failed'];
    }

    private function fetchViaTls(string $url, ?string $resolvedIp = null): ?string
    {
        $html = $this->curlFetch($url, $resolvedIp, 5);
        if ($html !== null && strlen($html) >= 200 && !$this->isUnusablePage($html)) {
            return $html;
        }
        return null;
    }

    /** Run curl-impersonate with DNS pinning. */
    private function curlFetch(string $url, ?string $resolvedIp, int $timeout): ?string
    {
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
            '%s -s -L -m %d %s -o - %s 2>/dev/null',
            escapeshellarg($this->curlPath),
            $timeout,
            $resolve,
            escapeshellarg($url)
        );
        return shell_exec($cmd);
    }

    /**
     * Tiered detection based on crawl4ai's antibot_detector approach.
     * Size-gated to avoid false positives on large real pages.
     */
    private function isUnusablePage(string $html): bool
    {
        $len = strlen($html);
        $head = strtolower(substr($html, 0, 4096));

        foreach (self::TIER1_MARKERS as $marker) {
            if (str_contains($head, $marker)) {
                return true;
            }
        }

        // Akamai reference number pattern
        if (preg_match('/reference\s*#\d+\.[0-9a-f]+\.\d+\.[0-9a-f]+/i', $head)) {
            return true;
        }

        // Tier 2: only on small pages — these strings appear in nav/footer of large real pages
        if ($len < 10240) {
            foreach (self::TIER2_MARKERS as $marker) {
                if (str_contains($head, $marker)) {
                    return true;
                }
            }
        }

        // Tier 3: structural check — only on pages < 50KB (large pages are not skeletons)
        if ($len < 51200) {
            $cleaned = preg_replace('/<(?:script|style)\b[^>]*>.*?<\/(?:script|style)>/is', '', $html) ?? '';

            if (strlen(trim(strip_tags(substr($cleaned, 0, 32768)))) < 50) {
                return true;
            }

            // No semantic content elements = JS skeleton
            if (!preg_match('/<(p|h[1-6]|article|section|li|td|blockquote|pre)\b/i', $cleaned)) {
                return true;
            }
        }

        return false;
    }

    private function fetchViaBrowser(string $url): ?string
    {
        if ($this->chromiumPath === '') {
            return null;
        }

        // Skip browser if available RAM < 100MB to avoid OOM-killing Hestia services
        $memInfo = @shell_exec("awk '/MemAvailable/ {print \$2}' /proc/meminfo");
        $memAvailable = $memInfo !== null ? (int) $memInfo : 0;
        if ($memAvailable > 0 && $memAvailable < 102400) {
            $this->logError('browser_low_memory', $url, "MemAvailable={$memAvailable}kB");
            return null;
        }

        // Kill any orphaned Chromium from a previous crashed request
        // flock in index.php guarantees no legitimate concurrent instance
        @shell_exec('pkill -f ' . escapeshellarg(basename($this->chromiumPath) . '.*headless') . ' 2>/dev/null');

        $browser = null;
        try {
            $factory = new \HeadlessChromium\BrowserFactory($this->chromiumPath);
            $browser = $factory->createBrowser([
                'headless' => true,
                'noSandbox' => true,
                'startupTimeout' => 15,
                'windowSize' => [1024, 768],
                'ignoreCertificateErrors' => true,
                'customFlags' => [
                    '--disable-gpu',
                    '--disable-dev-shm-usage',
                    '--disable-extensions',
                    '--disable-features=IsolateOrigins,site-per-process',
                ],
            ]);

            $page = $browser->createPage();
            // NETWORK_IDLE captures post-JS redirects (e.g. Cloudflare challenge completion)
            $page->navigate($url)->waitForNavigation(
                \HeadlessChromium\Page::NETWORK_IDLE,
                30000
            );

            return $page->evaluate('document.documentElement.outerHTML')
                ->getReturnValue();

        } catch (\HeadlessChromium\Exception\BrowserConnectionFailed $e) {
            $this->logError('browser_start_failed', $url, $e->getMessage());
            return null;
        } catch (\HeadlessChromium\Exception\OperationTimedOut $e) {
            $this->logError('browser_timeout', $url, $e->getMessage());
            return null;
        } catch (\HeadlessChromium\Exception\NavigationExpired $e) {
            $this->logError('browser_nav_expired', $url, $e->getMessage());
            return null;
        } catch (\Throwable $e) {
            $this->logError('browser_error', $url, $e->getMessage());
            return null;
        } finally {
            if ($browser !== null) {
                try { $browser->close(); } catch (\Throwable $e) {}
            }
        }
    }

    private function logError(string $type, string $url, string $message): void
    {
        $entry = json_encode([
            'time' => date('c'),
            'url' => $url,
            'error_type' => $type,
            'error_message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents($this->logPath, $entry . "\n", FILE_APPEND | LOCK_EX);
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
