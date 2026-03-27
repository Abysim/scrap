<?php

define('PROJECT_ROOT', dirname(__DIR__));

require PROJECT_ROOT . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(PROJECT_ROOT);
$dotenv->load();

// --- Routing ---
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/health') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'time' => date('c')]);
    exit;
}

if ($path !== '/scrape' && $path !== '/') {
    http_response_code(404);
    echo 'Not found';
    exit;
}

// --- API key validation ---
$apiKey = $_GET['api_key'] ?? '';
if ($apiKey !== ($_ENV['API_KEY'] ?? '')) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

// --- URL validation ---
$url = $_GET['url'] ?? '';
if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo 'Missing or invalid url parameter';
    exit;
}

$parsed = parse_url($url);
if (!in_array($parsed['scheme'] ?? '', ['http', 'https'])) {
    http_response_code(400);
    echo 'Only http/https URLs allowed';
    exit;
}

// SSRF prevention: reject private/reserved IPs
$host = $parsed['host'] ?? '';
$ip = gethostbyname($host);
if ($ip !== $host) {
    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
        http_response_code(400);
        echo 'Private/reserved IPs not allowed';
        exit;
    }
}

// --- Concurrency guard (flock) ---
$lock = fopen('/tmp/vps-scraper.lock', 'w');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    http_response_code(503);
    header('Retry-After: 10');
    echo 'Server busy, retry later';
    exit;
}

set_time_limit(25);

// --- Scrape ---
$scraper = new \App\Scraper();
$result = $scraper->scrape($url);

flock($lock, LOCK_UN);
fclose($lock);

// --- Response ---
if ($result['html'] !== null) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Scrape-Method: ' . $result['method']);
    echo $result['html'];
} else {
    http_response_code(502);
    echo 'All fetch methods failed';
}
