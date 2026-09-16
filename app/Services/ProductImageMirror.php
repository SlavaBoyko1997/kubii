<?php

namespace App\Services;

use App\Models\Product;
use App\Support\CounterpartyImageMirrorProgress;
use App\Support\ProductImageMirrorFailure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProductImageMirror
{
    public const PHASE_MAIN = 'main';

    public const PHASE_GALLERY = 'gallery';

    /** @var list<array{reason: string, detail: string, url: string, product_id: int|null}> */
    private array $batchFailures = [];

    public function needsMirror(Product $product): bool
    {
        $sourceUrl = self::normalizeSourceUrl($product->image_url);

        if ($sourceUrl === null) {
            return false;
        }

        if (blank($product->image_path)) {
            return true;
        }

        return self::normalizeSourceUrl($product->mirrored_image_url) !== $sourceUrl;
    }

    public function needsGalleryMirror(Product $product): bool
    {
        $sourceUrls = $this->normalizedGalleryUrls($product);

        if ($sourceUrls === []) {
            return false;
        }

        $mirroredUrls = $this->normalizedMirroredGalleryUrls($product);
        $pendingUrls = array_values(array_diff($sourceUrls, $mirroredUrls));

        return $pendingUrls !== [];
    }

    public static function normalizeSourceUrl(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $url = trim($url);

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }

        foreach ([
            'http://65.108.104.155/' => 'https://file.pobedov.com/',
            'http://file.pobedov.com/' => 'https://file.pobedov.com/',
        ] as $from => $to) {
            if (str_starts_with($url, $from)) {
                return $to.substr($url, strlen($from));
            }
        }

        return $url;
    }

    public static function encodeUrlForRequest(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $path = $parts['path'] ?? '';

        if ($path !== '') {
            $path = implode('/', array_map(
                static fn (string $segment): string => $segment === ''
                    ? ''
                    : rawurlencode(rawurldecode($segment)),
                explode('/', $path),
            ));
        }

        $encoded = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $encoded .= ':'.$parts['port'];
        }

        $encoded .= $path;

        if (isset($parts['query']) && $parts['query'] !== '') {
            $encoded .= '?'.$parts['query'];
        }

        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $encoded .= '#'.rawurlencode(rawurldecode($parts['fragment']));
        }

        return $encoded;
    }

    /**
     * @return list<string>
     */
    public static function normalizeGalleryUrls(?array $urls): array
    {
        return collect($urls ?? [])
            ->map(fn (mixed $url): ?string => self::normalizeSourceUrl(is_string($url) ? $url : null))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  iterable<string>  $images
     */
    public static function sortPicturesForImport(iterable $images): \Illuminate\Support\Collection
    {
        return collect($images)
            ->map(fn (mixed $url): ?string => is_string($url) ? trim($url) : null)
            ->filter()
            ->unique()
            ->sortBy(function (string $url): int {
                $path = (string) parse_url($url, PHP_URL_PATH);

                if (preg_match('#/upload/iblock/[0-9a-f]{3}/[a-z0-9]{16,}/#i', $path)) {
                    return 0;
                }

                if (! preg_match('/[\x{0400}-\x{04FF}]/u', $path)) {
                    return 1;
                }

                return 2;
            })
            ->values();
    }

    public function mirror(Product $product): bool
    {
        if (! $this->needsMirror($product)) {
            return false;
        }

        $product = $this->preferReliablePictureOrder($product);

        if (! $this->needsMirror($product)) {
            return false;
        }

        $referer = is_string($product->external_url) && $product->external_url !== ''
            ? $product->external_url
            : null;

        foreach ($this->mainImageCandidates($product) as $sourceUrl) {
            if ($this->mirrorUrlAsMain($product, $sourceUrl, $referer)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function mainImageCandidates(Product $product): array
    {
        return self::sortPicturesForImport([
            self::normalizeSourceUrl($product->image_url),
            ...self::normalizeGalleryUrls($product->gallery_images),
        ])
            ->map(fn (string $url): ?string => self::normalizeSourceUrl($url))
            ->filter()
            ->unique()
            ->take(3)
            ->values()
            ->all();
    }

    private function preferReliablePictureOrder(Product $product): Product
    {
        $sorted = self::sortPicturesForImport([
            $product->image_url,
            ...($product->gallery_images ?? []),
        ])
            ->map(fn (string $url): ?string => self::normalizeSourceUrl($url))
            ->filter()
            ->unique()
            ->values();

        $newMain = $sorted->first();
        $currentMain = self::normalizeSourceUrl($product->image_url);

        if ($newMain === null || $newMain === $currentMain) {
            return $product;
        }

        $newGallery = $sorted
            ->slice(1)
            ->reject(fn (string $url): bool => $url === $newMain)
            ->values()
            ->all();

        DB::table('products')->where('id', $product->id)->update([
            'image_url' => $newMain,
            'gallery_images' => json_encode($newGallery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'image_path' => null,
            'mirrored_image_url' => null,
            'updated_at' => now(),
        ]);

        return $product->fresh() ?? $product;
    }

    private function mirrorUrlAsMain(Product $product, string $sourceUrl, ?string $referer): bool
    {
        $download = $this->downloadImage($sourceUrl, $referer);

        if (! $download['ok']) {
            $this->recordFailure(
                $sourceUrl,
                $download['reason'],
                $download['detail'],
                $product->id,
                $download['request_url'] ?? null,
            );

            return false;
        }

        $stored = $this->storeOptimizedImage($product, $download['bytes'], 'main');

        if (! $stored['ok']) {
            $this->recordFailure($sourceUrl, $stored['reason'], $stored['detail'], $product->id, self::encodeUrlForRequest($sourceUrl));

            return false;
        }

        $this->deleteStoredPath($product->image_path);

        DB::table('products')->where('id', $product->id)->update([
            'image_path' => $stored['path'],
            'mirrored_image_url' => $sourceUrl,
            'updated_at' => now(),
        ]);

        return true;
    }

    /**
     * @return array{mirrored: int, failed: int, processed: int, completed: bool, failures: list<array{reason: string, detail: string, url: string, product_id: int|null}>}
     */
    public function mirrorGalleryStep(Product $product, int $maxImages): array
    {
        $empty = ['mirrored' => 0, 'failed' => 0, 'processed' => 0, 'completed' => true, 'failures' => []];

        $sourceUrls = $this->normalizedGalleryUrls($product);

        if ($sourceUrls === [] || ! $this->needsGalleryMirror($product)) {
            return $empty;
        }

        $referer = is_string($product->external_url) && $product->external_url !== ''
            ? $product->external_url
            : null;

        $mirroredUrls = $this->normalizedMirroredGalleryUrls($product);
        $staleMirroredUrls = array_values(array_diff($mirroredUrls, $sourceUrls));

        if ($staleMirroredUrls !== []) {
            $this->resetGalleryMirrorState($product);

            return $this->mirrorGalleryStep($product->fresh(), $maxImages);
        }

        $pendingUrls = array_values(array_diff($sourceUrls, $mirroredUrls));

        if ($pendingUrls === []) {
            $this->reconcileGalleryMetadata($product);

            return $empty;
        }

        $paths = collect($product->gallery_paths ?? [])
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->values()
            ->all();
        $mirrored = 0;
        $failed = 0;
        $processed = 0;
        $stepFailures = [];

        foreach (array_slice($pendingUrls, 0, max(1, $maxImages)) as $url) {
            $processed++;
            $download = $this->downloadImage($url, $referer);

            if (! $download['ok']) {
                $failed++;
                $stepFailures[] = ProductImageMirrorFailure::entry(
                    $download['reason'],
                    $url,
                    $download['detail'],
                    $product->id,
                    $download['request_url'] ?? null,
                );

                // A failed URL has still been attempted. Keeping it in this list
                // prevents one permanently broken supplier image from blocking
                // the whole gallery queue forever.
                $mirroredUrls[] = $url;

                continue;
            }

            $stored = $this->storeOptimizedImage($product, $download['bytes'], sprintf('gallery-%02d', count($paths) + 1));

            if (! $stored['ok']) {
                $failed++;
                $stepFailures[] = ProductImageMirrorFailure::entry(
                    $stored['reason'],
                    $url,
                    $stored['detail'],
                    $product->id,
                    self::encodeUrlForRequest($url),
                );

                $mirroredUrls[] = $url;

                continue;
            }

            $paths[] = $stored['path'];
            $mirroredUrls[] = $url;
            $mirrored++;
        }

        $remainingPending = array_values(array_diff($sourceUrls, $mirroredUrls));

        DB::table('products')->where('id', $product->id)->update([
            'gallery_paths' => json_encode($paths, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'gallery_images' => json_encode($sourceUrls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'mirrored_gallery_urls' => json_encode($mirroredUrls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);

        if ($remainingPending === []) {
            $this->reconcileGalleryMetadata($product->fresh());
        }

        return [
            'mirrored' => $mirrored,
            'failed' => $failed,
            'processed' => $processed,
            'completed' => $remainingPending === [],
            'failures' => $stepFailures,
        ];
    }

    /**
     * @return array{mirrored: int, failed: int, processed: int, completed: bool, failures: list<array{reason: string, detail: string, url: string, product_id: int|null, request_url: string|null}>}
     */
    public function mirrorGalleryProduct(Product $product, ?int $maxImagesPerStep = null, ?int $timeBudgetSeconds = null): array
    {
        $maxImagesPerStep ??= (int) config('product-images.mirror_gallery_images_per_job', 3);
        $timeBudgetSeconds ??= (int) config('product-images.mirror_gallery_product_time_budget', 90);
        $mirrored = 0;
        $failed = 0;
        $processed = 0;
        $failures = [];
        $startedAt = microtime(true);
        $counterpartyId = (int) ($product->counterparty_id ?? 0);

        while ($this->needsGalleryMirror($product)) {
            if ($counterpartyId > 0 && app(CounterpartyImageMirrorProgress::class)->isCancelled($counterpartyId)) {
                break;
            }

            if ((microtime(true) - $startedAt) >= $timeBudgetSeconds) {
                break;
            }

            $result = $this->mirrorGalleryStep($product, $maxImagesPerStep);
            $mirrored += $result['mirrored'];
            $failed += $result['failed'];
            $processed += $result['processed'];
            array_push($failures, ...$result['failures']);

            if ($result['processed'] === 0) {
                break;
            }

            $product = $product->fresh() ?? $product;
        }

        return [
            'mirrored' => $mirrored,
            'failed' => $failed,
            'processed' => $processed,
            'completed' => ! $this->needsGalleryMirror($product),
            'failures' => $failures,
        ];
    }

    /**
     * Mark the remaining images of a job that exhausted all queue retries as
     * attempted, so one exceptional product cannot leave the whole run stuck.
     *
     * @return array{failed: int, failures: list<array{reason: string, detail: string, url: string, request_url: string|null, product_id: int|null}>}
     */
    public function skipPendingGalleryProduct(int $counterpartyId, int $productId, string $detail): array
    {
        $product = Product::query()
            ->where('counterparty_id', $counterpartyId)
            ->find($productId);

        if ($product === null) {
            return ['failed' => 0, 'failures' => []];
        }

        $sourceUrls = $this->normalizedGalleryUrls($product);
        $mirroredUrls = $this->normalizedMirroredGalleryUrls($product);
        $pendingUrls = array_values(array_diff($sourceUrls, $mirroredUrls));

        if ($pendingUrls === []) {
            return ['failed' => 0, 'failures' => []];
        }

        DB::table('products')->where('id', $product->id)->update([
            'gallery_images' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'mirrored_gallery_urls' => json_encode(
                array_values(array_unique([...$mirroredUrls, ...$pendingUrls])),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            'updated_at' => now(),
        ]);

        return [
            'failed' => count($pendingUrls),
            'failures' => array_map(
                fn (string $url): array => ProductImageMirrorFailure::entry(
                    'job_failed',
                    $url,
                    Str::limit($detail, 200),
                    $product->id,
                    self::encodeUrlForRequest($url),
                ),
                $pendingUrls,
            ),
        ];
    }

    /**
     * @return array{mirrored: int, failed: int, processed: int}
     */
    public function mirrorGallery(Product $product): array
    {
        $mirrored = 0;
        $failed = 0;
        $processed = 0;

        while ($this->needsGalleryMirror($product)) {
            $result = $this->mirrorGalleryStep($product, PHP_INT_MAX);
            $mirrored += $result['mirrored'];
            $failed += $result['failed'];
            $processed += $result['processed'];

            if ($result['processed'] === 0) {
                break;
            }

            $product = $product->fresh();
        }

        return [
            'mirrored' => $mirrored,
            'failed' => $failed,
            'processed' => $processed,
        ];
    }

    /**
     * @return array{processed: int, mirrored: int, failed: int, scanned: int, last_id: int, failures: list<array{reason: string, detail: string, url: string, product_id: int|null}>}
     */
    public function mirrorBatch(
        ?int $counterpartyId = null,
        int $afterId = 0,
        ?int $limit = null,
        string $phase = self::PHASE_MAIN,
        bool $reportProgress = false,
    ): array {
        $this->resetBatchFailures();

        $limit ??= $phase === self::PHASE_GALLERY
            ? (int) config('product-images.mirror_gallery_batch_size', 8)
            : (int) config('product-images.mirror_batch_size', 25);

        if ($phase === self::PHASE_GALLERY) {
            return $this->mirrorGalleryBatch($counterpartyId, $afterId, $limit, $reportProgress);
        }

        $products = $this->pendingQuery($counterpartyId, $phase)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'source', 'external_id', 'external_url', 'image_url', 'image_path', 'mirrored_image_url', 'gallery_images']);

        $mirrored = 0;
        $failed = 0;
        $processed = 0;
        $scanned = 0;
        $progressBase = ['current' => 0, 'mirrored' => 0, 'failed' => 0];
        $timeBudget = (int) config('product-images.mirror_job_time_budget_seconds', 240);
        $batchStartedAt = microtime(true);

        if ($reportProgress && $counterpartyId !== null) {
            $status = app(CounterpartyImageMirrorProgress::class)->status($counterpartyId) ?? [];
            $progressBase = [
                'current' => (int) ($status['current'] ?? 0),
                'mirrored' => (int) ($status['mirrored'] ?? 0),
                'failed' => (int) ($status['failed'] ?? 0),
            ];
        }

        $lastId = $afterId;

        foreach ($products as $product) {
            if ((microtime(true) - $batchStartedAt) >= $timeBudget) {
                break;
            }

            $scanned++;

            try {
                if (! $this->needsMirror($product)) {
                    $this->reconcileMainMirrorMetadata($product);
                } else {
                    $processed++;

                    if ($this->mirror($product)) {
                        $mirrored++;
                    } else {
                        $failed++;
                    }
                }
            } catch (Throwable) {
                $failed++;
                $processed++;
            }

            $lastId = $product->id;

            if ($reportProgress && $counterpartyId !== null) {
                app(CounterpartyImageMirrorProgress::class)->update(
                    $counterpartyId,
                    $progressBase['current'] + $scanned,
                    $progressBase['mirrored'] + $mirrored,
                    $progressBase['failed'] + $failed,
                    self::PHASE_MAIN,
                );
                app(CounterpartyImageMirrorProgress::class)->checkpoint($counterpartyId, $lastId, self::PHASE_MAIN);
            }
        }

        if ($products->isEmpty()) {
            return [
                'processed' => 0,
                'mirrored' => 0,
                'failed' => 0,
                'scanned' => 0,
                'last_id' => $afterId,
                'failures' => $this->batchFailures,
            ];
        }

        return [
            'processed' => $processed,
            'mirrored' => $mirrored,
            'failed' => $failed,
            'scanned' => $scanned,
            'last_id' => $lastId,
            'failures' => $this->batchFailures,
        ];
    }

    /**
     * @return array{processed: int, mirrored: int, failed: int, scanned: int, last_id: int, failures: list<array{reason: string, detail: string, url: string, product_id: int|null}>}
     */
    private function mirrorGalleryBatch(?int $counterpartyId, int $afterId, int $productScanLimit, bool $reportProgress = false): array
    {
        $imageBudget = (int) config('product-images.mirror_gallery_images_per_job', 12);
        $products = $this->pendingQuery($counterpartyId, self::PHASE_GALLERY)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($productScanLimit)
            ->get(['id', 'source', 'external_id', 'external_url', 'gallery_images', 'gallery_paths', 'mirrored_gallery_urls']);

        $mirrored = 0;
        $failed = 0;
        $processed = 0;
        $lastId = $afterId;
        $progressBase = ['current' => 0, 'mirrored' => 0, 'failed' => 0];
        $timeBudget = (int) config('product-images.mirror_job_time_budget_seconds', 240);
        $batchStartedAt = microtime(true);

        if ($reportProgress && $counterpartyId !== null) {
            $status = app(CounterpartyImageMirrorProgress::class)->status($counterpartyId) ?? [];
            $progressBase = [
                'current' => (int) ($status['current'] ?? 0),
                'mirrored' => (int) ($status['mirrored'] ?? 0),
                'failed' => (int) ($status['failed'] ?? 0),
            ];
        }

        foreach ($products as $product) {
            if ($imageBudget < 1 || (microtime(true) - $batchStartedAt) >= $timeBudget) {
                break;
            }

            try {
                if (! $this->needsGalleryMirror($product)) {
                    $this->reconcileGalleryMetadata($product);
                    $lastId = $product->id;

                    continue;
                }

                $result = $this->mirrorGalleryStep($product, $imageBudget);
                $mirrored += $result['mirrored'];
                $failed += $result['failed'];
                $processed += $result['processed'];
                $imageBudget -= $result['processed'];
                array_push($this->batchFailures, ...$result['failures']);

                if ($reportProgress && $counterpartyId !== null && $result['processed'] > 0) {
                    app(CounterpartyImageMirrorProgress::class)->update(
                        $counterpartyId,
                        $progressBase['current'] + $processed,
                        $progressBase['mirrored'] + $mirrored,
                        $progressBase['failed'] + $failed,
                        self::PHASE_GALLERY,
                    );
                    app(CounterpartyImageMirrorProgress::class)->checkpoint(
                        $counterpartyId,
                        $result['completed'] ? $product->id : $lastId,
                        self::PHASE_GALLERY,
                    );
                }

                if (! $result['completed']) {
                    break;
                }

                $lastId = $product->id;
            } catch (Throwable) {
                $failed++;
                break;
            }
        }

        return [
            'processed' => $processed,
            'mirrored' => $mirrored,
            'failed' => $failed,
            'scanned' => $processed,
            'last_id' => $lastId,
            'failures' => $this->batchFailures,
        ];
    }

    public function pendingCount(?int $counterpartyId = null, string $phase = self::PHASE_MAIN): int
    {
        if ($phase === self::PHASE_GALLERY) {
            return $this->estimatePendingGalleryImageCount($counterpartyId);
        }

        return count($this->pendingMainProductIds($counterpartyId));
    }

    public function estimatePendingGalleryImageCount(?int $counterpartyId = null): int
    {
        if (\Illuminate\Support\Facades\DB::connection()->getDriverName() === 'mysql') {
            $estimate = (int) $this->pendingQuery($counterpartyId, self::PHASE_GALLERY)
                ->selectRaw(
                    'COALESCE(SUM(GREATEST(JSON_LENGTH(gallery_images) - COALESCE(JSON_LENGTH(mirrored_gallery_urls), 0), 0)), 0) as pending',
                )
                ->value('pending');

            if ($estimate > 0) {
                return $estimate;
            }
        }

        return $this->pendingGalleryImageCount($counterpartyId);
    }

    /**
     * @return list<int>
     */
    public function pendingGalleryProductIds(?int $counterpartyId, int $afterId = 0, int $limit = 150): array
    {
        $ids = [];

        $this->pendingQuery($counterpartyId, self::PHASE_GALLERY)
            ->when($afterId > 0, fn ($query) => $query->where('id', '>', $afterId))
            ->select(['id', 'gallery_images', 'mirrored_gallery_urls', 'gallery_paths'])
            ->orderBy('id')
            ->chunkById(200, function ($products) use (&$ids, $limit): bool {
                foreach ($products as $product) {
                    if ($this->needsGalleryMirror($product)) {
                        $ids[] = (int) $product->id;

                        if (count($ids) >= $limit) {
                            return false;
                        }
                    }
                }

                return count($ids) < $limit;
            }, column: 'id');

        return $ids;
    }

    /**
     * @return list<int>
     */
    public function pendingMainProductIds(?int $counterpartyId): array
    {
        $ids = $this->pendingQuery($counterpartyId, self::PHASE_MAIN)
            ->whereNull('image_path')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if (count($ids) >= 50) {
            return $ids;
        }

        $this->pendingQuery($counterpartyId, self::PHASE_MAIN)
            ->whereNotNull('image_path')
            ->select(['id', 'image_url', 'image_path', 'mirrored_image_url'])
            ->orderBy('id')
            ->chunkById(200, function ($products) use (&$ids): void {
                foreach ($products as $product) {
                    if ($this->needsMirror($product)) {
                        $ids[] = (int) $product->id;
                    }
                }
            }, column: 'id');

        return array_values(array_unique($ids));
    }

    public function hasPendingMainFast(?int $counterpartyId): bool
    {
        if ($this->pendingQuery($counterpartyId, self::PHASE_MAIN)->whereNull('image_path')->exists()) {
            return true;
        }

        $found = false;

        $this->pendingQuery($counterpartyId, self::PHASE_MAIN)
            ->whereNotNull('image_path')
            ->select(['id', 'image_url', 'image_path', 'mirrored_image_url'])
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->each(function (Product $product) use (&$found): void {
                if ($this->needsMirror($product)) {
                    $found = true;
                }
            });

        return $found;
    }

    public function hasPendingGalleryFast(?int $counterpartyId): bool
    {
        return $this->hasPendingGalleryAfter($counterpartyId, 0);
    }

    public function hasPendingGalleryAfter(?int $counterpartyId, int $afterId): bool
    {
        $found = false;

        $this->pendingQuery($counterpartyId, self::PHASE_GALLERY)
            ->when($afterId > 0, fn ($query) => $query->where('id', '>', $afterId))
            ->select(['id', 'gallery_images', 'mirrored_gallery_urls', 'gallery_paths'])
            ->orderBy('id')
            ->chunkById(100, function ($products) use (&$found): bool {
                foreach ($products as $product) {
                    if ($this->needsGalleryMirror($product)) {
                        $found = true;

                        return false;
                    }
                }

                return ! $found;
            }, column: 'id');

        return $found;
    }

    public function reconcileMainMirrorMetadata(Product $product): void
    {
        $this->reconcileMainMirrorMetadataInternal($product);
    }

    public function reconcilePendingMainMetadata(?int $counterpartyId = null): int
    {
        $reconciled = 0;

        $this->pendingQuery($counterpartyId, self::PHASE_MAIN)
            ->select(['id', 'image_url', 'image_path', 'mirrored_image_url'])
            ->orderBy('id')
            ->chunkById(500, function ($products) use (&$reconciled): void {
                foreach ($products as $product) {
                    if ($this->needsMirror($product)) {
                        continue;
                    }

                    $this->reconcileMainMirrorMetadata($product);
                    $reconciled++;
                }
            }, column: 'id');

        return $reconciled;
    }

    public function hasMorePending(?int $counterpartyId = null, int $afterId = 0, string $phase = self::PHASE_MAIN): bool
    {
        if ($phase === self::PHASE_GALLERY) {
            if ($this->hasPendingGalleryAfter($counterpartyId, $afterId)) {
                return true;
            }

            return $afterId > 0 && $this->hasPendingGalleryAfter($counterpartyId, 0);
        }

        return $this->hasPendingMainFast($counterpartyId);
    }

    private function pendingMainImageCount(?int $counterpartyId): int
    {
        $count = 0;

        $this->pendingQuery($counterpartyId, self::PHASE_MAIN)
            ->select(['id', 'image_url', 'image_path', 'mirrored_image_url'])
            ->orderBy('id')
            ->chunkById(500, function ($products) use (&$count): void {
                foreach ($products as $product) {
                    if ($this->needsMirror($product)) {
                        $count++;
                    }
                }
            }, column: 'id');

        return $count;
    }

    private function scanForPendingMain(?int $counterpartyId, int $afterId): bool
    {
        $query = $this->pendingQuery($counterpartyId, self::PHASE_MAIN)
            ->when($afterId > 0, fn ($query) => $query->where('id', '>', $afterId));

        if ((clone $query)->whereNull('image_path')->exists()) {
            return true;
        }

        $found = false;

        $query
            ->select(['id', 'image_url', 'image_path', 'mirrored_image_url'])
            ->orderBy('id')
            ->chunkById(100, function ($products) use (&$found): bool {
                foreach ($products as $product) {
                    if ($this->needsMirror($product)) {
                        $found = true;

                        return false;
                    }

                    $this->reconcileMainMirrorMetadata($product);
                }

                return ! $found;
            }, column: 'id');

        return $found;
    }

    public function pendingGalleryImageCount(?int $counterpartyId = null): int
    {
        $count = 0;

        $this->pendingQuery($counterpartyId, self::PHASE_GALLERY)
            ->select(['id', 'gallery_images', 'mirrored_gallery_urls', 'gallery_paths'])
            ->orderBy('id')
            ->chunkById(500, function ($products) use (&$count): void {
                foreach ($products as $product) {
                    $count += $this->pendingGalleryImagesForProduct($product);
                }
            }, column: 'id');

        return $count;
    }

    private function hasMorePendingGallery(?int $counterpartyId, int $afterId): bool
    {
        if ($this->scanForPendingGallery($counterpartyId, $afterId)) {
            return true;
        }

        return $afterId > 0 && $this->scanForPendingGallery($counterpartyId, 0);
    }

    private function scanForPendingGallery(?int $counterpartyId, int $afterId): bool
    {
        $found = false;

        $this->pendingQuery($counterpartyId, self::PHASE_GALLERY)
            ->when($afterId > 0, fn ($query) => $query->where('id', '>', $afterId))
            ->select(['id', 'gallery_images', 'mirrored_gallery_urls', 'gallery_paths'])
            ->orderBy('id')
            ->chunkById(200, function ($products) use (&$found): bool {
                foreach ($products as $product) {
                    if ($this->needsGalleryMirror($product)) {
                        $found = true;

                        return false;
                    }
                }

                return ! $found;
            }, column: 'id');

        return $found;
    }

    private function pendingGalleryImagesForProduct(Product $product): int
    {
        if (! $this->needsGalleryMirror($product)) {
            return 0;
        }

        $sourceUrls = $this->normalizedGalleryUrls($product);
        $mirroredUrls = $this->normalizedMirroredGalleryUrls($product);

        return max(0, count($sourceUrls) - count(array_intersect($sourceUrls, $mirroredUrls)));
    }

    public function reconcileGalleryMetadata(Product $product): void
    {
        $sourceUrls = $this->normalizedGalleryUrls($product);

        if ($sourceUrls === [] || $this->needsGalleryMirror($product)) {
            return;
        }

        DB::table('products')->where('id', $product->id)->update([
            'gallery_images' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'mirrored_gallery_urls' => json_encode($sourceUrls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    private function resetGalleryMirrorState(Product $product): void
    {
        $this->deleteGalleryPaths($product);

        DB::table('products')->where('id', $product->id)->update([
            'gallery_paths' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'mirrored_gallery_urls' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    private function pendingQuery(?int $counterpartyId, string $phase = self::PHASE_MAIN)
    {
        if ($phase === self::PHASE_GALLERY) {
            return Product::query()
                ->when($counterpartyId !== null, fn ($query) => $query->where('counterparty_id', $counterpartyId))
                ->whereNotNull('gallery_images')
                ->where('gallery_images', '!=', '[]')
                ->where('gallery_images', '!=', 'null');
        }

        return Product::query()
            ->when($counterpartyId !== null, fn ($query) => $query->where('counterparty_id', $counterpartyId))
            ->whereNotNull('image_url')
            ->where('image_url', '!=', '')
            ->where(function ($query): void {
                $query
                    ->whereNull('image_path')
                    ->orWhereNull('mirrored_image_url')
                    ->orWhereColumn('mirrored_image_url', '!=', 'image_url');
            });
    }

    /**
     * @return list<string>
     */
    private function normalizedGalleryUrls(Product $product): array
    {
        return self::normalizeGalleryUrls($product->gallery_images);
    }

    /**
     * @return list<string>
     */
    private function normalizedMirroredGalleryUrls(Product $product): array
    {
        return self::normalizeGalleryUrls($product->mirrored_gallery_urls);
    }

    private function reconcileMainMirrorMetadataInternal(Product $product): void
    {
        $sourceUrl = self::normalizeSourceUrl($product->image_url);

        if ($sourceUrl === null || blank($product->image_path)) {
            return;
        }

        $updates = [];

        if ($product->image_url !== $sourceUrl) {
            $updates['image_url'] = $sourceUrl;
        }

        if (self::normalizeSourceUrl($product->mirrored_image_url) !== $sourceUrl) {
            $updates['mirrored_image_url'] = $sourceUrl;
        }

        if ($updates === []) {
            return;
        }

        $updates['updated_at'] = now();

        DB::table('products')->where('id', $product->id)->update($updates);
    }

    /**
     * @return list<array{reason: string, detail: string, url: string, product_id: int|null}>
     */
    public function pullBatchFailures(): array
    {
        $failures = $this->batchFailures;
        $this->batchFailures = [];

        return $failures;
    }

    private function resetBatchFailures(): void
    {
        $this->batchFailures = [];
    }

    private function recordFailure(string $url, string $reason, string $detail = '', ?int $productId = null, ?string $requestUrl = null): void
    {
        $this->batchFailures[] = ProductImageMirrorFailure::entry($reason, $url, $detail, $productId, $requestUrl);
    }

    /**
     * @return list<string>
     */
    private function downloadUrlCandidates(string $url): array
    {
        return array_values(array_unique([
            self::encodeUrlForRequest($url),
        ]));
    }

    /**
     * @return array<string, string>
     */
    private function requestHeadersForUrl(string $requestUrl, ?string $referer = null): array
    {
        $host = mb_strtolower((string) parse_url($requestUrl, PHP_URL_HOST));
        $useBrowserProfile = $host !== '' && (str_contains($host, 'atlantmarket') || str_contains($host, 'ranger'));

        $headers = [
            'User-Agent' => (string) ($useBrowserProfile
                ? config('product-images.browser_user_agent')
                : config('product-images.user_agent')),
            'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
        ];

        if ($referer !== null && $referer !== '') {
            $headers['Referer'] = $referer;
        } elseif ($useBrowserProfile) {
            $headers['Referer'] = 'https://'.$host.'/';
        }

        return $headers;
    }

    /**
     * @return array{ok: true, bytes: string, status: int, content_type: string}|array{ok: false, reason: string, detail: string, status: int}
     */
    private function fetchRemoteUrl(string $requestUrl, ?string $referer = null): array
    {
        $headers = $this->requestHeadersForUrl($requestUrl, $referer);

        if (app()->environment('testing')) {
            return $this->fetchRemoteUrlViaHttp($requestUrl, $headers);
        }

        return $this->fetchRemoteUrlViaCurl($requestUrl, $headers);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{ok: true, bytes: string, status: int, content_type: string}|array{ok: false, reason: string, detail: string, status: int}
     */
    private function fetchRemoteUrlViaHttp(string $requestUrl, array $headers): array
    {
        try {
            $response = Http::timeout((int) config('product-images.http_timeout', 20))
                ->connectTimeout((int) config('product-images.http_connect_timeout', 5))
                ->withHeaders($headers)
                ->withOptions(['allow_redirects' => ['max' => 3]])
                ->get($requestUrl);

            return [
                'ok' => true,
                'bytes' => $response->body(),
                'status' => $response->status(),
                'content_type' => strtolower((string) $response->header('Content-Type')),
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'reason' => 'network',
                'detail' => Str::limit($exception->getMessage(), 120),
                'status' => 0,
            ];
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{ok: true, bytes: string, status: int, content_type: string}|array{ok: false, reason: string, detail: string, status: int}
     */
    private function fetchRemoteUrlViaCurl(string $requestUrl, array $headers): array
    {
        $headerLines = [];

        foreach ($headers as $name => $value) {
            if ($name !== 'User-Agent') {
                $headerLines[] = $name.': '.$value;
            }
        }

        $ch = curl_init();

        if ($ch === false) {
            return ['ok' => false, 'reason' => 'network', 'detail' => 'curl_init failed', 'status' => 0];
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $requestUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => (int) config('product-images.http_timeout', 20),
            CURLOPT_CONNECTTIMEOUT => (int) config('product-images.http_connect_timeout', 5),
            CURLOPT_USERAGENT => $headers['User-Agent'] ?? (string) config('product-images.user_agent'),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'reason' => 'network', 'detail' => Str::limit($error, 120), 'status' => $status];
        }

        return [
            'ok' => true,
            'bytes' => $body,
            'status' => $status,
            'content_type' => $contentType,
        ];
    }

    /**
     * @return array{ok: true, bytes: string}|array{ok: false, reason: string, detail: string, request_url: string|null}
     */
    private function downloadImage(string $url, ?string $referer = null): array
    {
        if ($reason = $this->unsafeUrlReason($url)) {
            return ['ok' => false, 'reason' => $reason, 'detail' => '', 'request_url' => null];
        }

        $lastFailure = ['ok' => false, 'reason' => 'network', 'detail' => '', 'request_url' => null];
        $maxBytes = (int) config('product-images.max_source_bytes', 12_000_000);

        foreach ($this->downloadUrlCandidates($url) as $requestUrl) {
            $fetch = $this->fetchRemoteUrl($requestUrl, $referer);
            $status = (int) ($fetch['status'] ?? 0);

            if (($fetch['ok'] ?? false) !== true) {
                $lastFailure = [
                    'ok' => false,
                    'reason' => (string) ($fetch['reason'] ?? 'network'),
                    'detail' => (string) ($fetch['detail'] ?? ''),
                    'request_url' => $requestUrl,
                ];

                continue;
            }

            if ($status < 200 || $status >= 300) {
                $lastFailure = [
                    'ok' => false,
                    'reason' => 'http:'.$status,
                    'detail' => '',
                    'request_url' => $requestUrl,
                ];

                continue;
            }

            $contentType = (string) ($fetch['content_type'] ?? '');

            if ($contentType !== '' && ! str_starts_with($contentType, 'image/')) {
                $lastFailure = [
                    'ok' => false,
                    'reason' => 'not_image',
                    'detail' => $contentType,
                    'request_url' => $requestUrl,
                ];

                continue;
            }

            $body = (string) ($fetch['bytes'] ?? '');

            if ($body === '') {
                $lastFailure = [
                    'ok' => false,
                    'reason' => 'empty',
                    'detail' => '',
                    'request_url' => $requestUrl,
                ];

                continue;
            }

            if (strlen($body) > $maxBytes) {
                $lastFailure = [
                    'ok' => false,
                    'reason' => 'too_large',
                    'detail' => (string) strlen($body),
                    'request_url' => $requestUrl,
                ];

                continue;
            }

            return ['ok' => true, 'bytes' => $body];
        }

        return $lastFailure;
    }

    /**
     * @return array{ok: true, path: string}|array{ok: false, reason: string, detail: string}
     */
    private function storeOptimizedImage(Product $product, string $bytes, string $filename): array
    {
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return ['ok' => false, 'reason' => 'invalid_image', 'detail' => ''];
        }

        if (imagesx($image) < 40 || imagesy($image) < 40) {
            $width = imagesx($image);
            $height = imagesy($image);
            imagedestroy($image);

            return ['ok' => false, 'reason' => 'too_small', 'detail' => $width.'×'.$height];
        }

        $image = $this->applyOrientation($image, $bytes);

        $directory = $this->storageDirectory($product);
        Storage::disk('public')->makeDirectory($directory);

        $extension = $this->supportsWebp() ? 'webp' : 'jpg';
        $relativePath = $directory.'/'.$filename.'.'.$extension;
        $absolutePath = Storage::disk('public')->path($relativePath);

        $saved = $extension === 'webp'
            ? imagewebp($image, $absolutePath, (int) config('product-images.webp_quality', 88))
            : imagejpeg($image, $absolutePath, (int) config('product-images.jpeg_quality', 90));

        imagedestroy($image);

        if (! $saved) {
            return ['ok' => false, 'reason' => 'save_failed', 'detail' => ''];
        }

        return ['ok' => true, 'path' => $relativePath];
    }

    private function unsafeUrlReason(string $url): ?string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            return 'blocked_url';
        }

        if ($this->isTrustedImageHost($host)) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
                ? null
                : 'blocked_url';
        }

        if (in_array(mb_strtolower($host), ['localhost', 'localhost.localdomain'], true)
            || Str::endsWith(mb_strtolower($host), ['.local', '.internal'])) {
            return 'blocked_url';
        }

        if (app()->environment('testing')) {
            return null;
        }

        static $resolvedHosts = [];

        if (array_key_exists($host, $resolvedHosts)) {
            return $resolvedHosts[$host] ? 'dns_blocked' : null;
        }

        $addresses = @gethostbynamel($host);

        $resolvedHosts[$host] = ! is_array($addresses)
            || $addresses === []
            || ! collect($addresses)->every(
                fn (string $address): bool => filter_var(
                    $address,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
                ) !== false,
            );

        return $resolvedHosts[$host] ? 'dns_blocked' : null;
    }

    /**
     * @return array{ok: true, bytes: string}|array{ok: false, reason: string, detail: string, request_url: string|null}
     */
    public function probeDownload(string $url, ?string $referer = null): array
    {
        return $this->downloadImage($url, $referer);
    }

    private function applyOrientation(\GdImage $image, string $bytes): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes), null, true)['Orientation'] ?? null;

        $rotated = match ((int) $orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => false,
        };

        if ($rotated instanceof \GdImage) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }

    private function storageDirectory(Product $product): string
    {
        $source = Str::slug((string) ($product->source ?: 'manual')) ?: 'manual';
        $externalId = Str::slug((string) ($product->external_id ?: ('product-'.$product->id))) ?: ('product-'.$product->id);

        return 'products/'.$source.'/'.$externalId;
    }

    private function deleteStoredPath(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        $normalized = preg_replace('#^public/#', '', trim($path)) ?? trim($path);

        if (Storage::disk('public')->exists($normalized)) {
            Storage::disk('public')->delete($normalized);
        }
    }

    private function deleteGalleryPaths(Product $product): void
    {
        foreach ($product->gallery_paths ?? [] as $path) {
            $this->deleteStoredPath(is_string($path) ? $path : null);
        }
    }

    private function supportsWebp(): bool
    {
        return function_exists('imagewebp');
    }

    private function isSafeRemoteUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            return false;
        }

        if ($this->isTrustedImageHost($host)) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        if (in_array(mb_strtolower($host), ['localhost', 'localhost.localdomain'], true)
            || Str::endsWith(mb_strtolower($host), ['.local', '.internal'])) {
            return false;
        }

        if (app()->environment('testing')) {
            return true;
        }

        static $resolvedHosts = [];

        if (array_key_exists($host, $resolvedHosts)) {
            return $resolvedHosts[$host];
        }

        $addresses = @gethostbynamel($host);

        $resolvedHosts[$host] = is_array($addresses)
            && $addresses !== []
            && collect($addresses)->every(
                fn (string $address): bool => filter_var(
                    $address,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
                ) !== false,
            );

        return $resolvedHosts[$host];
    }

    private function isTrustedImageHost(string $host): bool
    {
        $host = mb_strtolower($host);

        foreach (config('product-images.trusted_hosts', []) as $trustedHost) {
            if ($host === $trustedHost || Str::endsWith($host, '.'.$trustedHost)) {
                return true;
            }
        }

        return false;
    }
}
