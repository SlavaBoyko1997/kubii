<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Process\Process;

class ProductVariantGroupingWorker
{
    private const PID_CACHE_KEY = 'product-variant-grouping:worker-pid';

    public function start(): int
    {
        if ($pid = $this->runningPid()) {
            return $pid;
        }

        $php = PHP_BINDIR.DIRECTORY_SEPARATOR.'php';

        if (! is_executable($php)) {
            $php = 'php';
        }

        $command = implode(' ', array_map('escapeshellarg', [
            $php,
            base_path('artisan'),
            'queue:work',
            'redis',
            '--queue=default',
            '--tries=1',
            '--timeout=0',
            '--stop-when-empty',
        ]));
        $log = escapeshellarg(storage_path('logs/product-variant-grouping-worker.log'));
        $process = Process::fromShellCommandline(
            "nohup {$command} >> {$log} 2>&1 < /dev/null & echo $!",
            base_path(),
        );
        $process->setTimeout(10);
        $process->run();
        $pid = (int) trim($process->getOutput());

        if (! $process->isSuccessful() || $pid < 1) {
            throw new RuntimeException('Не вдалося запустити worker групування.');
        }

        usleep(150000);

        if (function_exists('posix_kill') && ! @posix_kill($pid, 0)) {
            throw new RuntimeException('Worker групування завершився одразу після запуску. Перевірте storage/logs/product-variant-grouping-worker.log.');
        }

        Cache::put(self::PID_CACHE_KEY, $pid, now()->addHours(2));

        return $pid;
    }

    public function runningPid(): ?int
    {
        $pid = (int) Cache::get(self::PID_CACHE_KEY);

        if ($pid < 1) {
            return null;
        }

        if (function_exists('posix_kill') && @posix_kill($pid, 0)) {
            return $pid;
        }

        Cache::forget(self::PID_CACHE_KEY);

        return null;
    }
}
