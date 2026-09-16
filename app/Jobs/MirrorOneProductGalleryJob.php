<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\ProductImageMirror;
use App\Support\CatalogCache;
use App\Support\CounterpartyImageMirrorProgress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class MirrorOneProductGalleryJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 3;

    public function __construct(
        public readonly int $counterpartyId,
        public readonly int $productId,
    ) {
        $this->onQueue('feeds');
    }

    public function handle(ProductImageMirror $mirror, CounterpartyImageMirrorProgress $progress): void
    {
        @set_time_limit(0);

        if ($progress->isCancelled($this->counterpartyId)) {
            return;
        }

        $lock = Cache::lock('mirror:gallery-product:'.$this->counterpartyId.':'.$this->productId, 180);

        if (! $lock->get()) {
            Log::debug('Skipping duplicate product gallery mirror job', [
                'counterparty_id' => $this->counterpartyId,
                'product_id' => $this->productId,
            ]);

            return;
        }

        try {
            $this->process($mirror, $progress);
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        if (app(CounterpartyImageMirrorProgress::class)->isCancelled($this->counterpartyId)) {
            return;
        }

        $message = $exception?->getMessage() ?? 'Unknown gallery job failure';

        Log::error('Product gallery mirror job exhausted retries', [
            'counterparty_id' => $this->counterpartyId,
            'product_id' => $this->productId,
            'exception' => $exception !== null ? $exception::class : null,
            'message' => $message,
        ]);

        try {
            $result = app(ProductImageMirror::class)->skipPendingGalleryProduct(
                $this->counterpartyId,
                $this->productId,
                $message,
            );

            app(CounterpartyImageMirrorProgress::class)->tickGalleryImages(
                $this->counterpartyId,
                $result['failed'],
                0,
                $result['failed'],
                $result['failures'],
            );

            MirrorProductImagesJob::dispatch(
                counterpartyId: $this->counterpartyId,
                phase: ProductImageMirror::PHASE_GALLERY,
            );
        } catch (Throwable $recoveryException) {
            Log::critical('Could not recover failed product gallery mirror job', [
                'counterparty_id' => $this->counterpartyId,
                'product_id' => $this->productId,
                'exception' => $recoveryException::class,
                'message' => $recoveryException->getMessage(),
            ]);

            app(CounterpartyImageMirrorProgress::class)->fail(
                $this->counterpartyId,
                'Галерея зупинилась на товарі #'.$this->productId.': '.$message,
            );
        }
    }

    private function process(ProductImageMirror $mirror, CounterpartyImageMirrorProgress $progress): void
    {
        $product = Product::query()
            ->where('counterparty_id', $this->counterpartyId)
            ->find($this->productId);

        if ($product === null) {
            $this->maybeCompleteGallery($mirror, $progress);

            return;
        }

        if (! $mirror->needsGalleryMirror($product)) {
            $mirror->reconcileGalleryMetadata($product);
            $this->maybeCompleteGallery($mirror, $progress);

            return;
        }

        $result = $mirror->mirrorGalleryProduct($product);

        if ($progress->isCancelled($this->counterpartyId)) {
            return;
        }

        $progress->tickGalleryImages(
            $this->counterpartyId,
            $result['processed'],
            $result['mirrored'],
            $result['failed'],
            $result['failures'],
        );

        if ($result['failures'] !== []) {
            Log::warning('Product gallery image download failures', [
                'counterparty_id' => $this->counterpartyId,
                'product_id' => $this->productId,
                'failure_count' => count($result['failures']),
                'failures' => array_slice($result['failures'], 0, 10),
            ]);
        }

        if (! $result['completed']) {
            self::dispatch($this->counterpartyId, $this->productId)->delay(now()->addSeconds(5));

            return;
        }

        $this->maybeCompleteGallery($mirror, $progress);
    }

    private function maybeCompleteGallery(ProductImageMirror $mirror, CounterpartyImageMirrorProgress $progress): void
    {
        if ($progress->isCancelled($this->counterpartyId)) {
            return;
        }

        if ($mirror->hasPendingGalleryAfter($this->counterpartyId, 0)) {
            return;
        }

        Cache::lock('mirror:gallery-complete:'.$this->counterpartyId, 30)->block(5, function () use ($mirror, $progress): void {
            if ($progress->isCancelled($this->counterpartyId)) {
                return;
            }

            if ($mirror->hasPendingGalleryAfter($this->counterpartyId, 0)) {
                return;
            }

            $status = $progress->status($this->counterpartyId) ?? [];

            $progress->complete(
                $this->counterpartyId,
                (int) ($status['mirrored'] ?? 0),
                (int) ($status['failed'] ?? 0),
                (int) ($status['current'] ?? 0),
            );

            if ((int) ($status['mirrored'] ?? 0) > 0) {
                app(CatalogCache::class)->invalidate();
            }
        });
    }
}
