<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

class CounterpartyFeedWorker
{
    public function start(int $counterpartyId, string $slug): int
    {
        $php = PHP_BINDIR.DIRECTORY_SEPARATOR.'php';

        if (! is_executable($php)) {
            $php = 'php';
        }

        $command = implode(' ', array_map('escapeshellarg', [
            $php,
            base_path('artisan'),
            'counterparties:sync-feeds',
            $slug,
            '--with-progress',
            '--counterparty-id='.$counterpartyId,
            '--trigger=manual',
        ]));
        $log = escapeshellarg(storage_path('logs/counterparty-feed-import.log'));
        $process = Process::fromShellCommandline(
            "nohup {$command} >> {$log} 2>&1 < /dev/null & echo $!",
            base_path(),
        );
        $process->setTimeout(10);
        $process->run();
        $pid = (int) trim($process->getOutput());

        if (! $process->isSuccessful() || $pid < 1) {
            throw new RuntimeException('Не вдалося запустити фоновий імпорт фіду.');
        }

        usleep(150000);

        if (function_exists('posix_kill') && ! @posix_kill($pid, 0)) {
            throw new RuntimeException('Фоновий імпорт завершився одразу після запуску. Перевірте storage/logs/counterparty-feed-import.log.');
        }

        return $pid;
    }
}
