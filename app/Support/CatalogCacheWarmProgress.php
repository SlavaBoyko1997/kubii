<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class CatalogCacheWarmProgress
{
    private const LATEST_KEY = 'catalog:warm-progress:latest';

    public function start(string $runId): array
    {
        $status = [
            'run_id' => $runId,
            'state' => 'queued',
            'stage' => 'Запускаємо фонове оновлення',
            'current' => 0,
            'total' => 0,
            'percent' => 0,
            'message' => null,
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
        ];

        Cache::forever(self::LATEST_KEY, $runId);
        Cache::forever($this->key($runId), $status);

        return $status;
    }

    public function update(string $runId, string $stage, int $current, int $total): array
    {
        $status = $this->status($runId) ?? $this->start($runId);
        $status['state'] = 'running';
        $status['stage'] = $stage;
        $status['current'] = max(0, $current);
        $status['total'] = max(0, $total);
        $status['percent'] = $total > 0 ? min(99, (int) floor(($current / $total) * 100)) : 0;

        Cache::forever($this->key($runId), $status);

        return $status;
    }

    public function complete(string $runId): array
    {
        $status = $this->status($runId) ?? $this->start($runId);
        $status['state'] = 'completed';
        $status['stage'] = 'Новий кеш активовано';
        $status['current'] = $status['total'];
        $status['percent'] = 100;
        $status['finished_at'] = now()->toIso8601String();

        Cache::forever($this->key($runId), $status);

        return $status;
    }

    public function fail(string $runId, string $message): array
    {
        $status = $this->status($runId) ?? $this->start($runId);
        $status['state'] = 'failed';
        $status['stage'] = 'Не вдалося оновити кеш';
        $status['message'] = $message;
        $status['finished_at'] = now()->toIso8601String();

        Cache::forever($this->key($runId), $status);

        return $status;
    }

    public function latest(): ?array
    {
        $runId = Cache::get(self::LATEST_KEY);

        return is_string($runId) ? $this->status($runId) : null;
    }

    public function status(string $runId): ?array
    {
        $status = Cache::get($this->key($runId));

        return is_array($status) ? $status : null;
    }

    public function isRunning(): bool
    {
        $status = $this->latest();

        if (! in_array($status['state'] ?? null, ['queued', 'running'], true)) {
            return false;
        }

        return ! now()->subMinutes(35)->isAfter($status['started_at'] ?? now());
    }

    private function key(string $runId): string
    {
        return 'catalog:warm-progress:'.$runId;
    }
}
