<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\ProductImageMirror;
use App\Support\CounterpartyImageMirrorProgress;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class MirrorOneProductImageJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $counterpartyId,
        public readonly int $productId,
    ) {
        $this->onQueue('feeds');
    }

    public function uniqueId(): string
    {
        return 'mirror-main:'.$this->counterpartyId.':'.$this->productId;
    }

    public function handle(ProductImageMirror $mirror, CounterpartyImageMirrorProgress $progress): void
    {
        @set_time_limit(0);

        if ($progress->isCancelled($this->counterpartyId)) {
            return;
        }

        Cache::lock('mirror:product:'.$this->counterpartyId.':'.$this->productId, 120)->block(10, function () use ($mirror, $progress): void {
            if ($progress->isCancelled($this->counterpartyId)) {
                return;
            }

            $this->process($mirror, $progress);
        });
    }

    public function failed(?Throwable $exception): void
    {
        $progress = app(CounterpartyImageMirrorProgress::class);

        if ($progress->isCancelled($this->counterpartyId)) {
            return;
        }

        $progress->tickProduct($this->counterpartyId, $this->productId, mirrored: false, failed: true);

        $this->maybeAdvanceToGallery(app(ProductImageMirror::class), $progress);
    }

    private function process(ProductImageMirror $mirror, CounterpartyImageMirrorProgress $progress): void
    {
        $product = Product::query()
            ->where('counterparty_id', $this->counterpartyId)
            ->find($this->productId);

        if ($product === null) {
            $progress->tickProduct($this->counterpartyId, $this->productId, mirrored: false, failed: true);
            $this->maybeAdvanceToGallery($mirror, $progress);

            return;
        }

        if (! $mirror->needsMirror($product)) {
            $mirror->reconcileMainMirrorMetadata($product);
            $progress->tickProduct($this->counterpartyId, $this->productId, mirrored: false, failed: false);
            $this->maybeAdvanceToGallery($mirror, $progress);

            return;
        }

        $mirrored = $mirror->mirror($product);

        if ($progress->isCancelled($this->counterpartyId)) {
            return;
        }

        $progress->tickProduct($this->counterpartyId, $this->productId, mirrored: $mirrored, failed: ! $mirrored);
        $this->maybeAdvanceToGallery($mirror, $progress);
    }

    private function maybeAdvanceToGallery(ProductImageMirror $mirror, CounterpartyImageMirrorProgress $progress): void
    {
        if ($progress->isCancelled($this->counterpartyId)) {
            return;
        }

        $status = $progress->status($this->counterpartyId) ?? [];
        $mainBatchDone = $progress->isMainBatchComplete($this->counterpartyId);

        if (! $mainBatchDone && $mirror->hasPendingMainFast($this->counterpartyId)) {
            return;
        }

        Cache::lock('mirror:advance:'.$this->counterpartyId, 30)->block(5, function () use ($mirror, $progress, $status, $mainBatchDone): void {
            if (! $mainBatchDone && $mirror->hasPendingMainFast($this->counterpartyId)) {
                return;
            }

            $latest = $progress->status($this->counterpartyId) ?? $status;

            if (($latest['state'] ?? null) === 'cancelled') {
                return;
            }

            if (($latest['phase'] ?? null) === ProductImageMirror::PHASE_GALLERY) {
                return;
            }

            if ($mirror->hasPendingGalleryFast($this->counterpartyId)) {
                $progress->kickoffGalleryPhase($this->counterpartyId, $latest);

                return;
            }

            $progress->complete(
                $this->counterpartyId,
                (int) ($latest['mirrored'] ?? 0),
                (int) ($latest['failed'] ?? 0),
                (int) ($latest['current'] ?? 0),
            );
        });
    }
}
