<?php

/**
 * SPA レンダリングワーカー (子プロセスとして実行)
 * stdin から JSON {url, timeout, chromiumHost, chromiumPort} を受け取り、
 * レンダリング済み HTML を JSON で stdout に返す
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use HeadlessChromium\Browser;
use HeadlessChromium\Communication\Connection;

$input = json_decode(file_get_contents('php://stdin'), true);
if (!$input || !isset($input['url'])) {
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit(1);
}

$url = $input['url'];
$timeout = $input['timeout'] ?? 15;
$chromiumHost = $input['chromiumHost'] ?? 'chromium';
$chromiumPort = $input['chromiumPort'] ?? 9222;

try {
    $ip = gethostbyname($chromiumHost);

    $json = @file_get_contents(
        "http://{$ip}:{$chromiumPort}/json/version",
        false,
        stream_context_create(['http' => ['timeout' => 5]])
    );

    if ($json === false) {
        throw new \RuntimeException("Cannot connect to Chromium");
    }

    $data = json_decode($json, true);
    $path = parse_url($data['webSocketDebuggerUrl'] ?? '', PHP_URL_PATH);
    $wsUrl = "ws://{$ip}:{$chromiumPort}{$path}";

    $connection = new Connection($wsUrl);
    $connection->connect();
    $browser = new Browser($connection);

    $page = $browser->createPage();
    $page->navigate($url)->waitForNavigation('networkIdle', $timeout * 1000);

    $html = $page->evaluate('document.documentElement.outerHTML')->getReturnValue();
    $finalUrl = $page->evaluate('window.location.href')->getReturnValue();

    $page->close();
    $connection->disconnect();

    echo json_encode([
        'ok' => true,
        'html' => $html ?? '',
        'finalUrl' => $finalUrl ?? $url,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
    exit(1);
}
