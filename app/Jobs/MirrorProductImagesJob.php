<?php

namespace App\Jobs;

use App\Services\ProductImageMirror;
use App\Support\CatalogCache;
use App\Support\CounterpartyImageMirrorProgress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MirrorProductImagesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout;

    public int $tries = 1;

    public function __construct(
        public readonly ?int $counterpartyId = null,
        public readonly int $afterId = 0,
        public readonly bool $invalidateCatalogCache = true,
        public readonly string $phase = ProductImageMirror::PHASE_MAIN,
    ) {
        $this->timeout = (int) config('product-images.mirror_job_timeout', 180);
        $this->onQueue('feeds');
    }

    public function handle(ProductImageMirror $mirror, CounterpartyImageMirrorProgress $progress): void
    {
        @set_time_limit(0);

        if ($this->counterpartyId === null) {
            return;
        }

        if ($progress->isCancelled($this->counterpartyId)) {
            return;
        }

        if ($this->phase === ProductImageMirror::PHASE_MAIN) {
            $this->dispatchMainProductJobs($mirror, $progress);

            return;
        }

        $this->dispatchGalleryProductJobs($mirror, $progress);
    }

    private function dispatchMainProductJobs(ProductImageMirror $mirror, CounterpartyImageMirrorProgress $progress): void
    {
        if ($progress->isMainBatchComplete($this->counterpartyId)) {
            $this->finishMainPhase($mirror, $progress);

            return;
        }

        $productIds = $mirror->pendingMainProductIds($this->counterpartyId);

        if ($productIds === []) {
            $this->finishMainPhase($mirror, $progress);

            return;
        }

        $existing = $progress->status($this->counterpartyId) ?? [];

        if (
            ($existing['state'] ?? null) !== 'running'
            || ($existing['phase'] ?? null) !== ProductImageMirror::PHASE_MAIN
            || (int) ($existing['total'] ?? 0) < 1
        ) {
            $progress->start($this->counterpartyId, count($productIds));
        } else {
            $progress->resumeFromCheckpoint($this->counterpartyId, ProductImageMirror::PHASE_MAIN);
        }

        foreach ($productIds as $productId) {
            MirrorOneProductImageJob::dispatch($this->counterpartyId, $productId);
        }
    }

    private function finishMainPhase(ProductImageMirror $mirror, CounterpartyImageMirrorProgress $progress): void
    {
        if ($mirror->hasPendingGalleryFast($this->counterpartyId)) {
            $progress->kickoffGalleryPhase($this->counterpartyId, $progress->status($this->counterpartyId) ?? []);

            return;
        }

        $status = $progress->status($this->counterpartyId) ?? [];
        $progress->complete(
            $this->counterpartyId,
            (int) ($status['mirrored'] ?? 0),
            (int) ($status['failed'] ?? 0),
            (int) ($status['current'] ?? 0),
        );
    }

    private function dispatchGalleryProductJobs(ProductImageMirror $mirror, CounterpartyImageMirrorProgress $progress): void
    {
        $existing = $progress->status($this->counterpartyId) ?? [];
        $samePhase = ($existing['phase'] ?? null) === ProductImageMirror::PHASE_GALLERY;
        $hasTotal = (int) ($existing['total'] ?? 0) > 0;
        $hasGalleryCounters = ($existing['phase_counters_initialized'] ?? false) === true;

        if (! $samePhase || ! $hasTotal || ! $hasGalleryCounters) {
            $total = $mirror->estimatePendingGalleryImageCount($this->counterpartyId);

            if ($total < 1) {
                $this->completeGallery($progress);

                return;
            }

            $progress->startPhase($this->counterpartyId, ProductImageMirror::PHASE_GALLERY, $total);
        } else {
            $progress->resumeFromCheckpoint($this->counterpartyId, ProductImageMirror::PHASE_GALLERY);
        }

        $progress->clearRecoverableError($this->counterpartyId);

        $productIds = $mirror->pendingGalleryProductIds($this->counterpartyId, $this->afterId, 150);

        if ($productIds === []) {
            if ($this->afterId > 0 && $mirror->hasPendingGalleryAfter($this->counterpartyId, 0)) {
                self::dispatch(
                    counterpartyId: $this->counterpartyId,
                    afterId: 0,
                    invalidateCatalogCache: $this->invalidateCatalogCache,
                    phase: ProductImageMirror::PHASE_GALLERY,
                );
            } else {
                $this->completeGallery($progress);
            }

            return;
        }

        foreach ($productIds as $productId) {
            MirrorOneProductGalleryJob::dispatch($this->counterpartyId, $productId);
        }

        $lastId = (int) $productIds[array_key_last($productIds)];

        if (count($productIds) >= 150 && $mirror->hasPendingGalleryAfter($this->counterpartyId, $lastId)) {
            self::dispatch(
                counterpartyId: $this->counterpartyId,
                afterId: $lastId,
                invalidateCatalogCache: $this->invalidateCatalogCache,
                phase: ProductImageMirror::PHASE_GALLERY,
            );
        }
    }

    private function completeGallery(CounterpartyImageMirrorProgress $progress): void
    {
        $status = $progress->status($this->counterpartyId) ?? [];

        $progress->complete(
            $this->counterpartyId,
            (int) ($status['mirrored'] ?? 0),
            (int) ($status['failed'] ?? 0),
            (int) ($status['current'] ?? 0),
        );

        if ($this->invalidateCatalogCache && (int) ($status['mirrored'] ?? 0) > 0) {
            app(CatalogCache::class)->invalidate();
        }
    }
}
