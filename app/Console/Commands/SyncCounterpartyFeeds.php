<?php

namespace App\Console\Commands;

use App\Models\Counterparty;
use App\Models\CounterpartyFeedSyncLog;
use App\Services\CounterpartyFeedImporter;
use App\Services\IbisFeedImporter;
use App\Services\VoltmarketFeedImporter;
use App\Support\CounterpartyFeedImportProgress;
use App\Support\CounterpartyFeedSyncLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class SyncCounterpartyFeeds extends Command
{
    protected $signature = 'counterparties:sync-feeds
        {counterparty? : Slug or ID of a single counterparty}
        {--file= : Import from a local file instead of downloading}
        {--with-progress : Update admin import progress while running}
        {--counterparty-id= : Counterparty ID for progress tracking}
        {--trigger=cli : Sync source: auto, manual, cli}';

    protected $description = 'Import products from counterparty YML feeds';

    public function handle(
        CounterpartyFeedImporter $importer,
        IbisFeedImporter $ibisImporter,
        VoltmarketFeedImporter $voltmarketImporter,
        CounterpartyFeedImportProgress $progress,
        CounterpartyFeedSyncLogger $logger,
    ): int {
        $counterpartyArg = $this->argument('counterparty');
        $file = (string) $this->option('file');
        $trigger = $this->normalizeTrigger((string) $this->option('trigger'));

        $counterparties = Counterparty::query()
            ->when(
                filled($counterpartyArg),
                fn ($query) => $query->where(function ($query) use ($counterpartyArg): void {
                    $query->where('slug', $counterpartyArg);

                    if (is_numeric($counterpartyArg)) {
                        $query->orWhereKey((int) $counterpartyArg);
                    }
                }),
                fn ($query) => $query
                    ->where('is_active', true)
                    ->where('auto_sync', true)
                    ->whereNotNull('feed_url'),
            )
            ->get();

        if ($counterparties->isEmpty()) {
            $this->warn('No counterparties matched the sync criteria.');

            return self::SUCCESS;
        }

        $failed = 0;
        $trackProgress = (bool) $this->option('with-progress');
        $progressCounterpartyId = (int) $this->option('counterparty-id');

        foreach ($counterparties as $counterparty) {
            $this->info("Importing {$counterparty->name} ({$counterparty->slug})...");

            $activeImporter = match ($counterparty->feed_profile) {
                'ibis' => $ibisImporter,
                'voltmarket' => $voltmarketImporter,
                default => $importer,
            };

            $activeImporter->withCacheClearTrigger($trigger);

            $progressId = $trackProgress
                ? ($progressCounterpartyId > 0 ? $progressCounterpartyId : (int) $counterparty->getKey())
                : null;
            $startedAt = now();

            if ($trackProgress && $progressId !== null) {
                $progress->markRunning($progressId);
                $activeImporter->trackProgressFor($progressId);

                $progressStatus = $progress->status($progressId);
                $progressStartedAt = $progressStatus['started_at'] ?? null;

                if (is_string($progressStartedAt) && $progressStartedAt !== '') {
                    $startedAt = Carbon::parse($progressStartedAt);
                }
            }

            try {
                if ($counterparty->feed_profile === 'ibis') {
                    $result = $file !== ''
                        ? $ibisImporter->importCounterpartyFile(
                            $counterparty,
                            $file,
                            fn (int $imported) => $this->line(number_format($imported).' products imported'),
                        )
                        : $ibisImporter->importCounterparty(
                            $counterparty,
                            fn (int $imported) => $this->line(number_format($imported).' products imported'),
                        );
                } else {
                    $result = $file !== ''
                        ? $activeImporter->importFile(
                            $counterparty,
                            $file,
                            fn (int $imported) => $this->line(number_format($imported).' products imported'),
                        )
                        : $activeImporter->import(
                            $counterparty,
                            fn (int $imported) => $this->line(number_format($imported).' products imported'),
                        );
                }

                if ($trackProgress && $progressId !== null) {
                    $progress->complete($progressId, $result);
                }

                $logger->recordSuccess($counterparty, $trigger, $result, $startedAt);

                $this->info(number_format($result['products']).' products imported.');

                if (($result['variant_groups_created'] ?? 0) > 0) {
                    $this->info(number_format($result['variant_groups_created']).' variant groups created.');
                }

            } catch (Throwable $exception) {
                $failed++;

                if ($trackProgress && $progressId !== null) {
                    $progress->fail($progressId, $exception->getMessage());
                }

                $logger->recordFailure($counterparty, $trigger, $exception->getMessage(), $startedAt);

                $this->error($exception->getMessage());
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
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
