<?php

namespace App\Console\Commands;

use App\Jobs\MirrorProductImagesJob;
use App\Models\Counterparty;
use App\Services\ProductImageMirror;
use App\Support\CounterpartyImageMirrorProgress;
use Illuminate\Console\Command;

class MirrorProductImages extends Command
{
    protected $signature = 'products:mirror-images
        {counterparty? : Slug or ID of a single counterparty}
        {--sync : Mirror images in the current process instead of queueing}
        {--gallery-only : Mirror only gallery images, skip main product photos}
        {--fresh : Start from the beginning, ignoring a saved checkpoint and queued jobs}
        {--probe-url= : Test-download one image URL and print HTTP diagnostics}
        {--no-cache-invalidate : Skip catalog cache invalidation after mirroring}';

    protected $description = 'Download feed product images, optimize them as WebP and store them locally.';

    public function handle(ProductImageMirror $mirror): int
    {
        if ($probeUrl = $this->option('probe-url')) {
            $encoded = ProductImageMirror::encodeUrlForRequest((string) $probeUrl);
            $result = $mirror->probeDownload((string) $probeUrl);

            $this->line('Original: '.$probeUrl);
            $this->line('Request:  '.$encoded);

            if ($result['ok']) {
                $this->info('OK — downloaded '.number_format(strlen($result['bytes'])).' bytes');

                return self::SUCCESS;
            }

            $this->error('Failed: '.($result['reason'] ?? 'unknown'));

            if (! empty($result['request_url'])) {
                $this->line('Tried: '.$result['request_url']);
            }

            if (! empty($result['detail'])) {
                $this->line('Detail: '.$result['detail']);
            }

            return self::FAILURE;
        }

        $counterparty = $this->resolveCounterparty();

        if ($counterparty === null && filled($this->argument('counterparty'))) {
            $this->error('Counterparty not found.');

            return self::FAILURE;
        }

        $phases = $this->option('gallery-only')
            ? [ProductImageMirror::PHASE_GALLERY]
            : [ProductImageMirror::PHASE_MAIN, ProductImageMirror::PHASE_GALLERY];

        $pending = collect($phases)
            ->sum(fn (string $phase): int => $mirror->pendingCount($counterparty?->id, $phase));

        if ($pending < 1) {
            $this->info('No product images are waiting to be mirrored.');

            return self::SUCCESS;
        }

        $this->info(number_format($pending).' product images pending.');

        if ($this->option('sync')) {
            $processed = 0;
            $mirrored = 0;
            $failed = 0;

            foreach ($phases as $phase) {
                if ($mirror->pendingCount($counterparty?->id, $phase) < 1) {
                    continue;
                }

                $afterId = 0;

                do {
                    $previousAfterId = $afterId;
                    $result = $mirror->mirrorBatch($counterparty?->id, $afterId, phase: $phase);
                    $processed += $result['processed'];
                    $mirrored += $result['mirrored'];
                    $failed += $result['failed'];
                    $afterId = $result['last_id'];
                } while ($afterId > $previousAfterId && $mirror->hasMorePending($counterparty?->id, $afterId, $phase));
            }

            if (! $this->option('no-cache-invalidate') && $mirrored > 0) {
                app(\App\Support\CatalogCache::class)->invalidate();
            }

            $this->info("Processed {$processed}, mirrored {$mirrored}, failed {$failed}.");

            return self::SUCCESS;
        }

        $defaultPhase = $this->option('gallery-only')
            ? ProductImageMirror::PHASE_GALLERY
            : ProductImageMirror::PHASE_MAIN;
        $queuePhase = $this->resolveQueuePhase($counterparty?->id, $defaultPhase);

        MirrorProductImagesJob::dispatch(
            counterpartyId: $counterparty?->id,
            afterId: $this->resolveQueueAfterId($counterparty?->id, $queuePhase),
            invalidateCatalogCache: ! $this->option('no-cache-invalidate'),
            phase: $queuePhase,
        );

        $this->info('Queued background image mirroring job.');

        return self::SUCCESS;
    }

    private function resolveQueuePhase(?int $counterpartyId, string $defaultPhase): string
    {
        if ($counterpartyId === null || $this->option('fresh')) {
            return $defaultPhase;
        }

        if ($this->option('gallery-only')) {
            return ProductImageMirror::PHASE_GALLERY;
        }

        $progress = app(CounterpartyImageMirrorProgress::class);

        if ($progress->isMainBatchComplete($counterpartyId)) {
            return ProductImageMirror::PHASE_GALLERY;
        }

        $status = $progress->status($counterpartyId) ?? [];

        if (($status['state'] ?? null) === 'failed' && $progress->isMainBatchCompleteStatus($status)) {
            return ProductImageMirror::PHASE_GALLERY;
        }

        if (($status['state'] ?? null) !== 'failed') {
            return $defaultPhase;
        }

        return (string) ($status['checkpoint_phase'] ?? $defaultPhase);
    }

    private function resolveQueueAfterId(?int $counterpartyId, string $phase): int
    {
        if ($counterpartyId === null || $this->option('fresh')) {
            return 0;
        }

        $progress = app(CounterpartyImageMirrorProgress::class);
        $status = $progress->status($counterpartyId) ?? [];

        if ($phase === ProductImageMirror::PHASE_GALLERY) {
            if (($status['state'] ?? null) === 'failed' || $progress->isMainBatchComplete($counterpartyId)) {
                $progress->resumeFromCheckpoint($counterpartyId, ProductImageMirror::PHASE_GALLERY);
                $this->info('Main photos done. Continuing with gallery.');

                return (int) ($status['checkpoint_after_id'] ?? 0);
            }
        }

        if (($status['state'] ?? null) !== 'failed') {
            return 0;
        }

        $afterId = (int) ($status['checkpoint_after_id'] ?? 0);
        $checkpointPhase = (string) ($status['checkpoint_phase'] ?? $phase);

        if ($afterId < 1) {
            return 0;
        }

        $progress->resumeFromCheckpoint($counterpartyId, $checkpointPhase);
        $this->info('Resuming '.$checkpointPhase.' mirroring from checkpoint after_id='.$afterId.'.');

        return $afterId;
    }

    private function resolveCounterparty(): ?Counterparty
    {
        $argument = $this->argument('counterparty');

        if (blank($argument)) {
            return null;
        }

        return Counterparty::query()
            ->where(function ($query) use ($argument): void {
                $query->where('slug', $argument);

                if (is_numeric($argument)) {
                    $query->orWhereKey((int) $argument);
                }
            })
            ->first();
    }
}
