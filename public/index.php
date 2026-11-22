<?php
declare(strict_types=1);

/*
 * Parliament Chess — front controller for PHP's built-in server (or any PHP 8.2+ host).
 *
 *   php -S 0.0.0.0:8080 -t public public/index.php      (bin/serve does this, with the JIT on)
 */
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (PHP_SAPI === 'cli-server' && $uri !== '/' && is_file(__DIR__ . $uri)) {
    return false; // static files: the client, its script and styles
}

// Old share links (/?game=…, /?watch=…) live on: the game itself is /play.html.
if ($uri === '/' && array_intersect(array_keys($_GET), ['game', 'watch', 'replay', 'replays', 'orrery', 'rules'])) {
    header('Location: /play.html?' . ($_SERVER['QUERY_STRING'] ?? ''), true, 302);
    return true;
}
if ($uri === '/play') {
    header('Location: /play.html', true, 301);
    return true;
}
// The lab report and its media, read-only, from ../docs (the GitHub Pages folder).
if (str_starts_with($uri, '/docs/')) {
    $base = realpath(__DIR__ . '/../docs');
    $file = realpath($base . '/' . substr(rawurldecode($uri), 6));
    if ($file !== false && is_dir($file)) {
        $file = realpath($file . '/index.html'); // /docs/ is the website's splash page
    }
    if ($file === false || !str_starts_with($file, $base . '/') || !is_file($file)) {
        http_response_code(404);
        echo 'not found';
        return true;
    }
    $types = ['html' => 'text/html; charset=utf-8', 'md' => 'text/markdown; charset=utf-8', 'png' => 'image/png',
        'gif' => 'image/gif', 'mp4' => 'video/mp4', 'json' => 'application/json', 'css' => 'text/css', 'svg' => 'image/svg+xml',
        'jpg' => 'image/jpeg', 'js' => 'text/javascript', 'tsv' => 'text/tab-separated-values; charset=utf-8'];
    header('Content-Type: ' . ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    readfile($file);
    return true;
}

require __DIR__ . '/../src/autoload.php';

use Parliament\Server\Api;
use Parliament\Server\ApiError;

if (str_starts_with($uri, '/api/')) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $body = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $body = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
    }
    try {
        $out = (new Api())->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', substr($uri, 5), $_GET, $body);
        echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (ApiError $e) {
        http_response_code($e->status);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (\Throwable $e) {
        http_response_code(500);
        error_log((string)$e);
        echo json_encode(['error' => 'server error: ' . $e->getMessage()]);
    }
    return true;
}

if ($uri === '/rules.md') {
    header('Content-Type: text/markdown; charset=utf-8');
    readfile(__DIR__ . '/../RULES.md');
    return true;
}

header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/index.html');
return true;
