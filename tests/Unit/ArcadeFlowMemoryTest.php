<?php

use Symfony\Component\Process\Process;

test('fast flow releases large saved histories and exact HTTP retries remain once only at 128M', function () {
    $directory = sys_get_temp_dir().'/arcade-race-memory-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $database = $directory.'/test.sqlite';
    touch($database);
    $call = function (string $worker, array $input): array {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', dirname(__DIR__).'/Support/'.$worker], dirname(__DIR__, 2));
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $process->setTimeout(60);
        $process->mustRun();

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    };
    try {
        $fixture = $call('arcade-concurrency-worker.php', ['database' => $database, 'action' => 'init'])['result'] + ['database' => $database];
        $call('arcade-flow-memory.php', $fixture + ['action' => 'seed']);
        $before = $call('arcade-flow-memory.php', $fixture + ['action' => 'inspect']);
        $accepted = $call('arcade-flow-memory.php', $fixture + ['action' => 'http']);
        $after = $call('arcade-flow-memory.php', $fixture + ['action' => 'inspect']);
        $retry = $call('arcade-flow-memory.php', $fixture + ['action' => 'http']);
        $retried = $call('arcade-flow-memory.php', $fixture + ['action' => 'inspect']);
        expect($accepted['app_debug'])->toBeFalse()->and($accepted['status'])->toBe(200)->and($accepted['phase'])->toBe('offense')
            ->and($accepted['replayed'])->toBeFalse()->and($accepted['peak'])->toBeLessThan(128 * 1024 * 1024)
            ->and($retry['status'])->toBe(200)->and($retry['replayed'])->toBeTrue()
            ->and($retry['receipt'])->toBe($accepted['receipt'])->and($retry['body_hash'])->toBe($accepted['body_hash'])
            ->and($after['history_count'] - $before['history_count'])->toBeGreaterThanOrEqual(8)
            ->and($after['version'])->toBeGreaterThan($before['version'])
            ->and($after['prefix_hash'])->toBe($before['prefix_hash'])->and($after['receipts'])->toBe(1)
            ->and($retried)->toBe($after);
    } finally {
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});
