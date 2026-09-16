<?php

namespace App\Jobs;

use App\Models\Counterparty;
use App\Models\CounterpartyFeedSyncLog;
use App\Services\CounterpartyFeedImporter;
use App\Services\VoltmarketFeedImporter;
use App\Support\CounterpartyFeedImportProgress;
use App\Support\CounterpartyFeedSyncLogger;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportCounterpartyFeedJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $counterpartyId)
    {
        $this->onQueue('feeds');
    }

    public function uniqueId(): string
    {
        return 'counterparty-feed-'.$this->counterpartyId;
    }

    public function handle(
        CounterpartyFeedImporter $importer,
        VoltmarketFeedImporter $voltmarketImporter,
        CounterpartyFeedImportProgress $progress,
        CounterpartyFeedSyncLogger $logger,
    ): void {
        $counterparty = Counterparty::query()->findOrFail($this->counterpartyId);
        $activeImporter = $counterparty->feed_profile === 'voltmarket'
            ? $voltmarketImporter
            : $importer;

        $progress->markRunning($this->counterpartyId);
        $progressStatus = $progress->status($this->counterpartyId);
        $startedAt = isset($progressStatus['started_at'])
            ? \Illuminate\Support\Carbon::parse((string) $progressStatus['started_at'])
            : now();

        try {
            @ini_set('memory_limit', '1024M');
            @set_time_limit(0);

            $result = $activeImporter
                ->trackProgressFor($this->counterpartyId)
                ->import($counterparty);

            $progress->complete($this->counterpartyId, $result);
            $logger->recordSuccess($counterparty, CounterpartyFeedSyncLog::TRIGGER_MANUAL, $result, $startedAt);
        } catch (Throwable $exception) {
            $progress->fail($this->counterpartyId, $exception->getMessage());
            $logger->recordFailure($counterparty, CounterpartyFeedSyncLog::TRIGGER_MANUAL, $exception->getMessage(), $startedAt);

            report($exception);

            throw $exception;
        }
    }

    public static function cacheKeyFor(int $counterpartyId): string
    {
        return app(CounterpartyFeedImportProgress::class)->key($counterpartyId);
    }
}
