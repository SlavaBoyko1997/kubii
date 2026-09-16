<?php

namespace App\Support;

use App\Models\CatalogCacheLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CatalogCacheLogger
{
    public function recordWarmSuccess(
        string $trigger,
        bool $fresh,
        int $stepsCompleted,
        int $stepsTotal,
        ?Carbon $startedAt = null,
    ): CatalogCacheLog {
        $finishedAt = now();
        $startedAt ??= $finishedAt;

        return CatalogCacheLog::query()->create([
            'action' => CatalogCacheLog::ACTION_WARM,
            'trigger' => $this->normalizeTrigger($trigger),
            'status' => CatalogCacheLog::STATUS_SUCCESS,
            'reason' => null,
            'fresh' => $fresh,
            'steps_completed' => $stepsCompleted,
            'steps_total' => $stepsTotal,
            'error_message' => null,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);
    }

    public function recordWarmFailure(
        string $trigger,
        string $message,
        bool $fresh = false,
        ?Carbon $startedAt = null,
    ): CatalogCacheLog {
        $finishedAt = now();
        $startedAt ??= $finishedAt;

        return CatalogCacheLog::query()->create([
            'action' => CatalogCacheLog::ACTION_WARM,
            'trigger' => $this->normalizeTrigger($trigger),
            'status' => CatalogCacheLog::STATUS_FAILED,
            'reason' => null,
            'fresh' => $fresh,
            'steps_completed' => null,
            'steps_total' => null,
            'error_message' => Str::limit($message, 1000),
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);
    }

    public function recordClear(string $trigger, ?string $reason = null): CatalogCacheLog
    {
        $now = now();

        return CatalogCacheLog::query()->create([
            'action' => CatalogCacheLog::ACTION_CLEAR,
            'trigger' => $this->normalizeTrigger($trigger),
            'status' => CatalogCacheLog::STATUS_SUCCESS,
            'reason' => $reason !== null ? Str::limit($reason, 255) : null,
            'fresh' => false,
            'steps_completed' => null,
            'steps_total' => null,
            'error_message' => null,
            'started_at' => $now,
            'finished_at' => $now,
        ]);
    }

    private function normalizeTrigger(string $trigger): string
    {
        return match ($trigger) {
            CatalogCacheLog::TRIGGER_AUTO,
            CatalogCacheLog::TRIGGER_MANUAL,
            CatalogCacheLog::TRIGGER_CLI,
            CatalogCacheLog::TRIGGER_SYSTEM => $trigger,
            default => CatalogCacheLog::TRIGGER_CLI,
        };
    }
}
