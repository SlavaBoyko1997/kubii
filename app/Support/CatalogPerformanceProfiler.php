<?php

namespace App\Support;

class CatalogPerformanceProfiler
{
    private bool $enabled = false;

    private float $lastCheckpoint = 0.0;

    private array $timings = [];

    public function begin(): void
    {
        $this->enabled = (bool) config('performance.catalog_profiling');
        $this->lastCheckpoint = microtime(true);
        $this->timings = [];
    }

    public function checkpoint(string $name): void
    {
        if (! $this->enabled) {
            return;
        }

        $now = microtime(true);
        $this->timings[$name] = round(($now - $this->lastCheckpoint) * 1000, 2);
        $this->lastCheckpoint = $now;
    }

    public function timings(): array
    {
        return $this->timings;
    }
}
