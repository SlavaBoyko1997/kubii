<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

class CatalogCacheWorker
{
    public function start(string $runId): int
    {
        $php = PHP_BINDIR.DIRECTORY_SEPARATOR.'php';

        if (! is_executable($php)) {
            $php = 'php';
        }

        $command = implode(' ', array_map('escapeshellarg', [
            $php,
            base_path('artisan'),
            'catalog:cache-warm',
            '--fresh',
            '--progress-key='.$runId,
            '--trigger=manual',
        ]));
        $log = escapeshellarg(storage_path('logs/catalog-cache-warm.log'));
        $process = Process::fromShellCommandline(
            "nohup {$command} >> {$log} 2>&1 < /dev/null & echo $!",
            base_path(),
        );
        $process->setTimeout(10);
        $process->run();
        $pid = (int) trim($process->getOutput());

        if (! $process->isSuccessful() || $pid < 1) {
            throw new RuntimeException('Не вдалося запустити фонове оновлення кешу.');
        }

        usleep(150000);

        if (function_exists('posix_kill') && ! @posix_kill($pid, 0)) {
            throw new RuntimeException('Фонове оновлення кешу завершилося одразу після запуску. Перевірте storage/logs/catalog-cache-warm.log.');
        }

        return $pid;
    }
}
