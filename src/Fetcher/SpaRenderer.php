<?php

declare(strict_types=1);

namespace LiteStrip\Fetcher;

use React\ChildProcess\Process;
use React\Promise\Deferred;

use function React\Async\await;

class SpaRenderer
{
    private string $chromiumHost;
    private int $chromiumPort;
    private string $workerScript;

    public function __construct(
        string $chromiumHost = 'chromium',
        int $chromiumPort = 9222
    ) {
        $this->chromiumHost = $chromiumHost;
        $this->chromiumPort = $chromiumPort;
        $this->workerScript = __DIR__ . '/spa-worker.php';
    }

    /**
     * @param string $url     レンダリング対象 URL
     * @param int    $timeout タイムアウト秒数
     * @return array{html: string, finalUrl: string, status: int}
     */
    public function render(string $url, int $timeout = 15): array
    {
        $input = json_encode([
            'url' => $url,
            'timeout' => $timeout,
            'chromiumHost' => $this->chromiumHost,
            'chromiumPort' => $this->chromiumPort,
        ]);

        $deferred = new Deferred();
        $output = '';
        $errorOutput = '';

        $process = new Process('php ' . escapeshellarg($this->workerScript));
        $process->start();

        $process->stdout->on('data', function (string $chunk) use (&$output) {
            $output .= $chunk;
        });

        $process->stderr->on('data', function (string $chunk) use (&$errorOutput) {
            $errorOutput .= $chunk;
        });

        $process->on('exit', function ($exitCode) use ($deferred, &$output, &$errorOutput) {
            if ($exitCode !== 0 && $output === '') {
                $deferred->reject(new \RuntimeException(
                    'SPA worker failed: ' . ($errorOutput ?: "exit code {$exitCode}")
                ));
            } else {
                $deferred->resolve($output);
            }
        });

        $process->stdin->write($input);
        $process->stdin->end();

        // タイムアウト
        $timer = \React\EventLoop\Loop::addTimer($timeout + 10, function () use ($process, $deferred) {
            $process->terminate(9);
            $deferred->reject(new \RuntimeException('SPA rendering timed out'));
        });

        try {
            /** @var string $result */
            $result = await($deferred->promise());
            \React\EventLoop\Loop::cancelTimer($timer);
        } catch (\Throwable $e) {
            \React\EventLoop\Loop::cancelTimer($timer);
            throw new \RuntimeException('SPA rendering failed: ' . $e->getMessage(), 0, $e);
        }

        $data = json_decode($result, true);
        if (!$data || !($data['ok'] ?? false)) {
            throw new \RuntimeException('SPA rendering failed: ' . ($data['error'] ?? 'Unknown error'));
        }

        return [
            'html' => $data['html'],
            'finalUrl' => $data['finalUrl'],
            'status' => 200,
        ];
    }
}
