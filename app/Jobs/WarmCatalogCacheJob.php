<?php

namespace App\Jobs;

use App\Support\CatalogCacheWarmProgress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class WarmCatalogCacheJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public readonly string $runId)
    {
        $this->onQueue('catalog-cache');
    }

    public function handle(CatalogCacheWarmProgress $progress): void
    {
        try {
            Artisan::call('catalog:cache-warm', [
                '--fresh' => true,
                '--progress-key' => $this->runId,
            ]);
        } catch (Throwable $exception) {
            $progress->fail($this->runId, $exception->getMessage());

            throw $exception;
        }
    }
}
