<?php

namespace App\Support;

use App\Models\Counterparty;
use App\Models\CounterpartyFeedSyncLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CounterpartyFeedSyncLogger
{
    public function recordSuccess(
        Counterparty $counterparty,
        string $trigger,
        array $result,
        ?Carbon $startedAt = null,
    ): CounterpartyFeedSyncLog {
        $finishedAt = now();
        $startedAt ??= $finishedAt;

        return CounterpartyFeedSyncLog::query()->create([
            'counterparty_id' => $counterparty->id,
            'trigger' => $this->normalizeTrigger($trigger),
            'status' => CounterpartyFeedSyncLog::STATUS_SUCCESS,
            'products_count' => (int) ($result['products'] ?? 0),
            'categories_count' => (int) ($result['categories'] ?? 0),
            'variant_groups_created' => (int) ($result['variant_groups_created'] ?? 0),
            'error_message' => null,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);
    }

    public function recordFailure(
        Counterparty $counterparty,
        string $trigger,
        string $message,
        ?Carbon $startedAt = null,
    ): CounterpartyFeedSyncLog {
        $finishedAt = now();
        $startedAt ??= $finishedAt;

        return CounterpartyFeedSyncLog::query()->create([
            'counterparty_id' => $counterparty->id,
            'trigger' => $this->normalizeTrigger($trigger),
            'status' => CounterpartyFeedSyncLog::STATUS_FAILED,
            'products_count' => null,
            'categories_count' => null,
            'variant_groups_created' => null,
            'error_message' => Str::limit($message, 1000),
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);
    }

    private function normalizeTrigger(string $trigger): string
    {
        return match ($trigger) {
            CounterpartyFeedSyncLog::TRIGGER_AUTO,
            CounterpartyFeedSyncLog::TRIGGER_MANUAL,
            CounterpartyFeedSyncLog::TRIGGER_CLI => $trigger,
            default => CounterpartyFeedSyncLog::TRIGGER_CLI,
        };
    }
}
