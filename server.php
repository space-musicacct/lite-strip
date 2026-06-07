<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

date_default_timezone_set('Asia/Tokyo');

use LiteStrip\Config\ServerConfig;
use LiteStrip\Fetcher\HtmlFetcher;
use LiteStrip\Fetcher\SpaRenderer;
use LiteStrip\Fetcher\UrlValidator;
use LiteStrip\Follower\ApiFollower;
use LiteStrip\Formatter\HtmlFormatter;
use LiteStrip\Formatter\JsonFormatter;
use LiteStrip\Formatter\MarkdownFormatter;
use LiteStrip\Processor\ContentExtractor;
use LiteStrip\Processor\DomProcessor;
use LiteStrip\Processor\ScriptAnalyzer;
use LiteStrip\Server\RequestHandler;
use React\Http\HttpServer;
use React\Socket\SocketServer;

$port = (int) ($argv[1] ?? getenv('PORT') ?: ServerConfig::DEFAULT_PORT);

$dnsResolver = (new React\Dns\Resolver\Factory())->create('8.8.8.8');
$browser = new React\Http\Browser();

$urlValidator = new UrlValidator($dnsResolver);
$htmlFetcher = new HtmlFetcher($browser);

$chromiumHost = getenv('CHROMIUM_HOST') ?: null;
$chromiumPort = (int) (getenv('CHROMIUM_PORT') ?: 9222);
$spaRenderer = $chromiumHost ? new SpaRenderer($chromiumHost, $chromiumPort) : null;

$scriptAnalyzer = new ScriptAnalyzer();
$apiFollower = new ApiFollower($htmlFetcher, $urlValidator);
$contentExtractor = new ContentExtractor();
$domProcessor = new DomProcessor();

$handler = new RequestHandler(
    $urlValidator,
    $htmlFetcher,
    $spaRenderer,
    $scriptAnalyzer,
    $apiFollower,
    $contentExtractor,
    $domProcessor,
    new HtmlFormatter(),
    new JsonFormatter(),
    new MarkdownFormatter(),
);

$server = new HttpServer(function (\Psr\Http\Message\ServerRequestInterface $request) use ($handler) {
    try {
        return $handler->handle($request);
    } catch (\Throwable $e) {
        error_log('[LiteStrip] Unhandled exception: ' . $e->getMessage());
        return new React\Http\Message\Response(500, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Access-Control-Allow-Origin' => '*',
        ], json_encode([
            'ok' => false,
            'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'An unexpected error occurred'],
        ]));
    }
});

$socket = new SocketServer("0.0.0.0:{$port}");
$server->listen($socket);

echo "LiteStrip v" . ServerConfig::VERSION . " listening on http://0.0.0.0:{$port}\n";

$shutdown = function () use ($socket) {
    echo "Shutting down...\n";
    $socket->close();
};

React\EventLoop\Loop::addSignal(SIGTERM, $shutdown);
React\EventLoop\Loop::addSignal(SIGINT, $shutdown);
