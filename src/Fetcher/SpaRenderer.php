<?php

declare(strict_types=1);

namespace LiteStrip\Fetcher;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;

use function React\Promise\reject;

/**
 * Renders JavaScript-heavy pages (SPAs) via a headless Chromium child process.
 *
 * Requests are queued and processed one at a time to avoid Fiber conflicts
 * between chrome-php's blocking I/O and ReactPHP's event loop. The actual
 * rendering is done in a separate PHP process (spa-worker.php) using proc_open.
 */
class SpaRenderer
{
    private string $chromiumHost;
    private int $chromiumPort;
    private string $workerScript;

    private bool $processing = false;

    /** @var list<array{url: string, timeout: int, deferred: Deferred}> */
    private array $queue = [];

    /** @var int Maximum number of queued SPA render requests */
    private const MAX_QUEUE_SIZE = 10;

    public function __construct(
        string $chromiumHost = 'chromium',
        int $chromiumPort = 9222
    ) {
        $this->chromiumHost = $chromiumHost;
        $this->chromiumPort = $chromiumPort;
        $this->workerScript = __DIR__ . '/spa-worker.php';
    }

    /**
     * Queues a URL for SPA rendering and returns a promise that resolves with the rendered HTML.
     *
     * @param string $url Target URL to render
     * @param int $timeout Rendering timeout in seconds
     * @return PromiseInterface<array{html: string, finalUrl: string, status: int}>
     */
    public function renderAsync(string $url, int $timeout = 15): PromiseInterface
    {
        if (count($this->queue) >= self::MAX_QUEUE_SIZE) {
            return reject(
                new RuntimeException('SPA render queue is full. Try again later.')
            );
        }

        $deferred = new Deferred();
        $this->queue[] = ['url' => $url, 'timeout' => $timeout, 'deferred' => $deferred];

        if (!$this->processing) {
            Loop::futureTick(fn() => $this->processNext());
        }

        return $deferred->promise();
    }

    /**
     * Processes the next item in the queue. Only one render runs at a time.
     */
    private function processNext(): void
    {
        if ($this->processing || empty($this->queue)) {
            return;
        }

        $this->processing = true;
        $item = array_shift($this->queue);

        try {
            $result = $this->execWorker($item['url'], $item['timeout']);
            $item['deferred']->resolve($result);
        } catch (Throwable $e) {
            $item['deferred']->reject($e);
        }

        $this->processing = false;

        if (!empty($this->queue)) {
            Loop::futureTick(fn() => $this->processNext());
        }
    }

    /**
     * Spawns a child PHP process to perform the actual Chromium rendering.
     *
     * Uses proc_open for synchronous execution to keep chrome-php's blocking I/O
     * completely isolated from the ReactPHP event loop.
     *
     * @param string $url Target URL
     * @param int $timeout Timeout in seconds
     * @return array{html: string, finalUrl: string, status: int}
     * @throws RuntimeException If the worker process fails
     */
    private function execWorker(string $url, int $timeout): array
    {
        $input = json_encode([
            'url' => $url,
            'timeout' => $timeout,
            'chromiumHost' => $this->chromiumHost,
            'chromiumPort' => $this->chromiumPort,
        ]);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            'php ' . escapeshellarg($this->workerScript),
            $descriptors,
            $pipes
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start SPA worker process');
        }

        fwrite($pipes[0], $input);
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($output === false || $output === '') {
            throw new RuntimeException('SPA worker returned no output: ' . ($errorOutput ?: "exit code $exitCode"));
        }

        $data = json_decode($output, true);
        if (!$data || !($data['ok'] ?? false)) {
            throw new RuntimeException('SPA rendering failed: ' . ($data['error'] ?? 'Unknown error'));
        }

        return [
            'html' => $data['html'],
            'finalUrl' => $data['finalUrl'],
            'status' => 200,
        ];
    }
}
