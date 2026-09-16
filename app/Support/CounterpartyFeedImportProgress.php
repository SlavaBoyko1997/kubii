<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class CounterpartyFeedImportProgress
{
    private const TTL_SECONDS = 7200;

    public function start(int $counterpartyId): array
    {
        $status = [
            'counterparty_id' => $counterpartyId,
            'state' => 'queued',
            'stage' => 'У черзі на обробку',
            'current' => 0,
            'total' => 0,
            'percent' => 0,
            'products_imported' => 0,
            'categories_count' => null,
            'variant_groups_created' => null,
            'message' => null,
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
        ];

        $this->put($counterpartyId, $status);

        return $status;
    }

    public function markRunning(int $counterpartyId): array
    {
        $status = $this->status($counterpartyId) ?? $this->start($counterpartyId);
        $status['state'] = 'running';

        $this->put($counterpartyId, $status);

        return $status;
    }

    public function update(int $counterpartyId, string $stage, int $current = 0, int $total = 0, array $extra = []): array
    {
        $status = $this->status($counterpartyId) ?? $this->start($counterpartyId);
        $status['state'] = 'running';
        $status['stage'] = $stage;
        $status['current'] = max(0, $current);
        $status['total'] = max(0, $total);
        $status['percent'] = $this->percentForStage($stage, $current, $total);

        foreach ($extra as $key => $value) {
            $status[$key] = $value;
        }

        $this->put($counterpartyId, $status);

        return $status;
    }

    public function complete(int $counterpartyId, array $result): array
    {
        $status = $this->status($counterpartyId) ?? $this->start($counterpartyId);
        $products = (int) ($result['products'] ?? 0);
        $status['state'] = 'completed';
        $status['stage'] = 'Імпорт завершено';
        $status['current'] = $products;
        $status['total'] = $products;
        $status['percent'] = 100;
        $status['products_imported'] = $products;
        $status['categories_count'] = (int) ($result['categories'] ?? 0);
        $status['variant_groups_created'] = (int) ($result['variant_groups_created'] ?? 0);
        $status['finished_at'] = now()->toIso8601String();
        $status['message'] = null;

        Cache::put($this->key($counterpartyId), $status, now()->addDay());

        return $status;
    }

    public function fail(int $counterpartyId, string $message): array
    {
        $status = $this->status($counterpartyId) ?? $this->start($counterpartyId);
        $status['state'] = 'failed';
        $status['stage'] = 'Помилка імпорту';
        $status['message'] = $message;
        $status['finished_at'] = now()->toIso8601String();

        Cache::put($this->key($counterpartyId), $status, now()->addDay());

        return $status;
    }

    public function status(int $counterpartyId): ?array
    {
        $status = Cache::get($this->key($counterpartyId));

        return is_array($status) ? $status : null;
    }

    public function isRunning(int $counterpartyId): bool
    {
        $status = $this->status($counterpartyId);

        if (! in_array($status['state'] ?? null, ['queued', 'running'], true)) {
            return false;
        }

        $startedAt = $status['started_at'] ?? null;

        if (! is_string($startedAt) || $startedAt === '') {
            return false;
        }

        return ! now()->subSeconds(self::TTL_SECONDS)->isAfter($startedAt);
    }

    public function isStaleQueued(int $counterpartyId, int $seconds = 45): bool
    {
        $status = $this->status($counterpartyId);

        if (($status['state'] ?? null) !== 'queued') {
            return false;
        }

        $startedAt = $status['started_at'] ?? null;

        if (! is_string($startedAt) || $startedAt === '') {
            return false;
        }

        return now()->subSeconds($seconds)->isAfter($startedAt);
    }

    public function failStaleQueued(int $counterpartyId): ?array
    {
        if (! $this->isStaleQueued($counterpartyId)) {
            return null;
        }

        return $this->fail(
            $counterpartyId,
            'Імпорт не стартував. Натисніть «Обробити фід» ще раз.',
        );
    }

    public function key(int $counterpartyId): string
    {
        return 'counterparty:'.$counterpartyId.':feed-import-progress';
    }

    private function put(int $counterpartyId, array $status): void
    {
        Cache::put($this->key($counterpartyId), $status, now()->addSeconds(self::TTL_SECONDS));
    }

    private function percentForStage(string $stage, int $current, int $total): int
    {
        return match ($stage) {
            'У черзі на обробку' => 1,
            'Завантаження фіду' => 8,
            'Синхронізація категорій' => 15,
            'Групування варіантів' => 92,
            'Оновлення фільтрів' => 97,
            default => $this->productImportPercent($current, $total),
        };
    }

    private function productImportPercent(int $current, int $total): int
    {
        if ($total <= 0) {
            return $current > 0 ? 50 : 18;
        }

        $ratio = min(1, $current / $total);

        return min(90, 18 + (int) floor($ratio * 72));
    }
}
