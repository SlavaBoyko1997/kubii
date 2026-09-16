<?php

namespace App\Support;

use App\Jobs\MirrorProductImagesJob;
use App\Services\ProductImageMirror;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CounterpartyImageMirrorProgress
{
    private const TTL_SECONDS = 7200;

    public function start(int $counterpartyId, int $total): array
    {
        $status = [
            'counterparty_id' => $counterpartyId,
            'state' => 'running',
            'stage' => 'Завантаження головних фото',
            'phase' => 'main',
            'current' => 0,
            'total' => max(0, $total),
            'percent' => $total > 0 ? 1 : 0,
            'mirrored' => 0,
            'failed' => 0,
            'processed' => 0,
            'phase_counters_initialized' => true,
            'message' => null,
            'failure_reasons' => [],
            'failure_samples' => [],
            'started_at' => now()->toIso8601String(),
            'last_activity_at' => now()->toIso8601String(),
            'finished_at' => null,
        ];

        $this->put($counterpartyId, $status);

        return $status;
    }

    public function startPhase(int $counterpartyId, string $phase, int $total, bool $resetCounters = true): array
    {
        $status = $this->status($counterpartyId) ?? $this->start($counterpartyId, $total);
        $status['state'] = 'running';
        $status['phase'] = $phase;
        $status['stage'] = $phase === 'gallery' ? 'Завантаження галереї' : 'Завантаження головних фото';
        $status['total'] = max(0, $total);
        $status['phase_counters_initialized'] = true;

        if ($resetCounters) {
            $status['current'] = 0;
            $status['percent'] = $total > 0 ? 1 : 0;
            $status['mirrored'] = 0;
            $status['failed'] = 0;
            $status['processed'] = 0;
            $status['failure_reasons'] = [];
            $status['failure_samples'] = [];
        } else {
            $done = (int) ($status['mirrored'] ?? 0) + (int) ($status['failed'] ?? 0);
            $status['current'] = max((int) ($status['current'] ?? 0), $done);
            $status['percent'] = $status['total'] > 0
                ? min(99, (int) floor(($status['current'] / $status['total']) * 100))
                : 1;
        }

        $status['message'] = null;
        $status['finished_at'] = null;
        $status['last_activity_at'] = now()->toIso8601String();

        $this->put($counterpartyId, $status);

        return $status;
    }

    public function update(int $counterpartyId, int $current, int $mirrored, int $failed, ?string $phase = null, array $failures = []): array
    {
        $status = $this->status($counterpartyId) ?? $this->start($counterpartyId, $current);
        $status['state'] = 'running';
        $status['phase'] = $phase ?? ($status['phase'] ?? 'main');
        $status['stage'] = ($status['phase'] ?? 'main') === 'gallery'
            ? 'Завантаження галереї'
            : 'Завантаження головних фото';
        $status['mirrored'] = max(0, $mirrored);
        $status['failed'] = max(0, $failed);
        $status['processed'] = $status['mirrored'] + $status['failed'];
        $status['current'] = max(max(0, $current), $status['processed']);
        $status['percent'] = $status['total'] > 0
            ? max(1, min(99, (int) floor(($status['current'] / $status['total']) * 100)))
            : ($status['current'] > 0 ? 50 : 1);

        $this->mergeFailures($status, $failures);
        $status['last_activity_at'] = now()->toIso8601String();

        $this->put($counterpartyId, $status);

        return $status;
    }

    public function tickProduct(int $counterpartyId, int $productId, bool $mirrored, bool $failed): array
    {
        $status = $this->status($counterpartyId) ?? $this->start($counterpartyId, 1);

        if (($status['state'] ?? null) === 'cancelled') {
            return $status;
        }

        $processedIds = is_array($status['processed_product_ids'] ?? null)
            ? array_map(intval(...), $status['processed_product_ids'])
            : [];

        if (in_array($productId, $processedIds, true)) {
            return $status;
        }

        $processedIds[] = $productId;
        $status['processed_product_ids'] = $processedIds;
        $status['state'] = 'running';
        $status['phase'] = 'main';
        $status['stage'] = 'Завантаження головних фото';
        $status['current'] = min((int) ($status['total'] ?? 0), count($processedIds));
        $status['mirrored'] = (int) ($status['mirrored'] ?? 0) + ($mirrored ? 1 : 0);
        $status['failed'] = (int) ($status['failed'] ?? 0) + ($failed ? 1 : 0);
        $status['processed'] = $status['mirrored'] + $status['failed'];
        $status['percent'] = ($status['total'] ?? 0) > 0
            ? max(1, min(99, (int) floor(($status['current'] / $status['total']) * 100)))
            : 1;
        $status['message'] = null;
        $status['timeout_streak'] = 0;
        $status['mirrored_at_streak_start'] = null;
        $status['last_activity_at'] = now()->toIso8601String();

        $this->put($counterpartyId, $status);

        return $status;
    }

    /**
     * @param  list<array{reason: string, detail: string, url: string, product_id: int|null}>  $failures
     */
    public function mergeFailures(array &$status, array $failures): void
    {
        if ($failures === []) {
            return;
        }

        $reasons = is_array($status['failure_reasons'] ?? null) ? $status['failure_reasons'] : [];
        $samples = is_array($status['failure_samples'] ?? null) ? $status['failure_samples'] : [];

        foreach ($failures as $failure) {
            $reason = (string) ($failure['reason'] ?? 'unknown');
            $reasons[$reason] = (int) ($reasons[$reason] ?? 0) + 1;

            if (count($samples) >= 20) {
                continue;
            }

            $samples[] = [
                'reason' => $reason,
                'detail' => (string) ($failure['detail'] ?? ''),
                'url' => (string) ($failure['url'] ?? ''),
                'request_url' => isset($failure['request_url']) ? (string) $failure['request_url'] : null,
                'product_id' => $failure['product_id'] ?? null,
            ];
        }

        $status['failure_reasons'] = $reasons;
        $status['failure_samples'] = $samples;
    }

    public function checkpoint(int $counterpartyId, int $afterId, string $phase): void
    {
        $status = $this->status($counterpartyId);

        if ($status === null) {
            return;
        }

        $status['checkpoint_after_id'] = max(0, $afterId);
        $status['checkpoint_phase'] = $phase;
        $status['last_activity_at'] = now()->toIso8601String();

        $this->put($counterpartyId, $status);
    }

    public function recordRecoverableError(int $counterpartyId, string $message, int $afterId, string $phase): void
    {
        $status = $this->status($counterpartyId) ?? $this->start($counterpartyId, 0);
        $mirrored = (int) ($status['mirrored'] ?? 0);
        $previousStreak = (int) ($status['timeout_streak'] ?? 0);
        $mirroredAtStreakStart = (int) ($status['mirrored_at_streak_start'] ?? $mirrored);

        if ($previousStreak === 0) {
            $mirroredAtStreakStart = $mirrored;
        }

        $status['state'] = 'running';
        $status['message'] = 'Тимчасова помилка, продовжуємо через хвилину: '.$message;
        $status['checkpoint_after_id'] = max(0, $afterId);
        $status['checkpoint_phase'] = $phase;
        $status['timeout_streak'] = $previousStreak + 1;
        $status['mirrored_at_streak_start'] = $mirroredAtStreakStart;
        $status['last_activity_at'] = now()->toIso8601String();

        $this->put($counterpartyId, $status);
    }

    public function clearRecoverableError(int $counterpartyId): void
    {
        $status = $this->status($counterpartyId);

        if ($status === null) {
            return;
        }

        $status['message'] = null;
        $status['timeout_streak'] = 0;
        $status['mirrored_at_streak_start'] = null;
        $status['last_activity_at'] = now()->toIso8601String();

        $this->put($counterpartyId, $status);
    }

    public function isStalled(int $counterpartyId, int $limit = 8): bool
    {
        $status = $this->status($counterpartyId);

        if ($status === null) {
            return false;
        }

        $streak = (int) ($status['timeout_streak'] ?? 0);

        if ($streak < $limit) {
            return false;
        }

        return (int) ($status['mirrored'] ?? 0) <= (int) ($status['mirrored_at_streak_start'] ?? 0);
    }

    public function resumeFromCheckpoint(int $counterpartyId, string $phase): array
    {
        $status = $this->status($counterpartyId) ?? $this->start($counterpartyId, 0);
        $done = (int) ($status['mirrored'] ?? 0) + (int) ($status['failed'] ?? 0);
        $status['state'] = 'running';
        $status['phase'] = $phase;
        $status['stage'] = $phase === 'gallery' ? 'Завантаження галереї' : 'Завантаження головних фото';
        $status['current'] = max((int) ($status['current'] ?? 0), $done);
        $status['message'] = null;
        $status['finished_at'] = null;
        $status['timeout_streak'] = 0;
        $status['mirrored_at_streak_start'] = null;
        $status['percent'] = ($status['total'] ?? 0) > 0
            ? min(99, (int) floor(($status['current'] / $status['total']) * 100))
            : 1;
        $status['last_activity_at'] = now()->toIso8601String();

        $this->put($counterpartyId, $status);

        return $status;
    }

    public function complete(int $counterpartyId, int $mirrored, int $failed, int $processed): array
    {
        $status = $this->status($counterpartyId) ?? $this->start($counterpartyId, $processed);

        if (($status['state'] ?? null) === 'cancelled') {
            return $status;
        }

        $status['state'] = 'completed';
        $status['stage'] = 'Фото та галерея збережені локально';
        $status['processed'] = max(0, $processed);
        $status['mirrored'] = max(0, $mirrored);
        $status['failed'] = max(0, $failed);
        $status['current'] = max($status['current'] ?? 0, $processed);
        $status['percent'] = 100;
        $status['message'] = null;
        $status['finished_at'] = now()->toIso8601String();

        if (($status['phase'] ?? null) === 'main') {
            unset($status['processed_product_ids']);
        }

        Cache::put($this->key($counterpartyId), $status, now()->addDay());

        return $status;
    }

    public function isMainBatchComplete(int $counterpartyId): bool
    {
        $status = $this->status($counterpartyId);

        if ($status === null || ($status['phase'] ?? null) !== 'main') {
            return false;
        }

        return $this->isMainBatchCompleteStatus($status);
    }

    /**
     * @param  array<string, mixed>  $status
     */
    public function isMainBatchCompleteStatus(array $status): bool
    {
        $total = (int) ($status['total'] ?? 0);

        if ($total < 1) {
            return false;
        }

        $processedIds = is_array($status['processed_product_ids'] ?? null)
            ? count($status['processed_product_ids'])
            : 0;

        if ($processedIds >= $total) {
            return true;
        }

        return (int) ($status['current'] ?? 0) >= $total;
    }

    /**
     * @param  array<string, mixed>|null  $status
     */
    public function kickoffGalleryPhase(int $counterpartyId, ?array $status = null): array
    {
        $status = $status ?? $this->status($counterpartyId) ?? $this->start($counterpartyId, 0);

        if (($status['state'] ?? null) === 'cancelled') {
            return $status;
        }

        $status['main_total'] = (int) ($status['total'] ?? 0);
        $status['main_processed'] = (int) ($status['current'] ?? 0);
        $status['main_mirrored'] = (int) ($status['mirrored'] ?? 0);
        $status['main_failed'] = (int) ($status['failed'] ?? 0);
        $status['main_finished_at'] = now()->toIso8601String();
        $status['state'] = 'running';
        $status['phase'] = ProductImageMirror::PHASE_GALLERY;
        $status['stage'] = 'Завантаження галереї';
        $status['total'] = 0;
        $status['current'] = 0;
        $status['percent'] = 1;
        $status['mirrored'] = 0;
        $status['failed'] = 0;
        $status['processed'] = 0;
        $status['failure_reasons'] = [];
        $status['failure_samples'] = [];
        $status['phase_counters_initialized'] = false;
        $status['message'] = null;
        $status['finished_at'] = null;
        $status['last_activity_at'] = now()->toIso8601String();

        $this->put($counterpartyId, $status);

        MirrorProductImagesJob::dispatch(
            counterpartyId: $counterpartyId,
            phase: ProductImageMirror::PHASE_GALLERY,
        );

        return $status;
    }

    public function tickGalleryImages(int $counterpartyId, int $processed, int $mirrored, int $failed, array $failures = []): array
    {
        $status = $this->status($counterpartyId) ?? $this->startPhase($counterpartyId, ProductImageMirror::PHASE_GALLERY, max(1, $processed));

        if (($status['state'] ?? null) === 'cancelled') {
            return $status;
        }


        // Repair progress records created by the old transition code, which
        // carried main-photo totals and counters into the gallery phase.
        if (($status['phase_counters_initialized'] ?? false) !== true) {
            $status['total'] = max(
                max(0, $processed),
                app(ProductImageMirror::class)->estimatePendingGalleryImageCount($counterpartyId) + max(0, $processed),
            );
            $status['current'] = 0;
            $status['mirrored'] = 0;
            $status['failed'] = 0;
            $status['processed'] = 0;
            $status['failure_reasons'] = [];
            $status['failure_samples'] = [];
            $status['phase_counters_initialized'] = true;
        }

        $status['state'] = 'running';
        $status['phase'] = ProductImageMirror::PHASE_GALLERY;
        $status['stage'] = 'Завантаження галереї';
        $status['current'] = min(
            (int) ($status['total'] ?? 0),
            (int) ($status['current'] ?? 0) + max(0, $processed),
        );
        $status['mirrored'] = (int) ($status['mirrored'] ?? 0) + max(0, $mirrored);
        $status['failed'] = (int) ($status['failed'] ?? 0) + max(0, $failed);
        $status['processed'] = $status['mirrored'] + $status['failed'];
        $status['percent'] = ($status['total'] ?? 0) > 0
            ? max(1, min(99, (int) floor(($status['current'] / $status['total']) * 100)))
            : 1;
        $status['message'] = null;
        $status['timeout_streak'] = 0;
        $status['last_activity_at'] = now()->toIso8601String();

        $this->mergeFailures($status, $failures);
        $this->put($counterpartyId, $status);

        return $status;
    }

    public function recoverIfMainFinished(int $counterpartyId): ?array
    {
        $status = $this->status($counterpartyId);

        if ($status === null || ! $this->isMainBatchCompleteStatus($status)) {
            return null;
        }

        if (! in_array($status['state'] ?? null, ['failed', 'running'], true)) {
            return null;
        }

        if (! app(ProductImageMirror::class)->hasPendingGalleryFast($counterpartyId)) {
            if (($status['state'] ?? null) === 'failed') {
                return $this->complete(
                    $counterpartyId,
                    (int) ($status['mirrored'] ?? 0),
                    (int) ($status['failed'] ?? 0),
                    (int) ($status['current'] ?? 0),
                );
            }

            return null;
        }

        $status['state'] = 'running';
        $status['message'] = null;
        $status['finished_at'] = null;

        return $this->kickoffGalleryPhase($counterpartyId, $status);
    }

    public function recoverIfStuck(int $counterpartyId, int $idleSeconds = 60): ?array
    {
        $status = $this->status($counterpartyId);

        if ($status === null || ($status['state'] ?? null) !== 'running') {
            return null;
        }

        if (! $this->isIdle($status, $idleSeconds)) {
            return null;
        }

        if (($status['phase'] ?? null) === 'main') {
            return $this->recoverStuckMain($counterpartyId, $status);
        }

        if (($status['phase'] ?? null) === ProductImageMirror::PHASE_GALLERY) {
            return $this->recoverStuckGallery($counterpartyId, $status);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function recoverStuckGallery(int $counterpartyId, array $status): array
    {
        Log::warning('Recovering stalled product gallery mirror', [
            'counterparty_id' => $counterpartyId,
            'current' => (int) ($status['current'] ?? 0),
            'total' => (int) ($status['total'] ?? 0),
            'mirrored' => (int) ($status['mirrored'] ?? 0),
            'failed' => (int) ($status['failed'] ?? 0),
            'last_activity_at' => $status['last_activity_at'] ?? null,
            'checkpoint_after_id' => (int) ($status['checkpoint_after_id'] ?? 0),
        ]);

        $mirror = app(ProductImageMirror::class);

        if ((int) ($status['total'] ?? 0) < 1) {
            $total = $mirror->estimatePendingGalleryImageCount($counterpartyId);

            if ($total > 0) {
                $this->startPhase($counterpartyId, ProductImageMirror::PHASE_GALLERY, $total);
            }
        }

        $afterId = (int) ($status['checkpoint_after_id'] ?? 0);

        foreach ($mirror->pendingGalleryProductIds($counterpartyId, $afterId, 50) as $productId) {
            \App\Jobs\MirrorOneProductGalleryJob::dispatch($counterpartyId, $productId);
        }

        MirrorProductImagesJob::dispatch(
            counterpartyId: $counterpartyId,
            afterId: $afterId,
            phase: ProductImageMirror::PHASE_GALLERY,
        );

        $status = $this->status($counterpartyId) ?? $status;
        $status['message'] = 'Продовжуємо завантаження галереї...';
        $status['last_activity_at'] = now()->toIso8601String();
        $this->put($counterpartyId, $status);

        return $status;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function recoverStuckMain(int $counterpartyId, array $status): array
    {
        if ($this->isMainBatchCompleteStatus($status)) {
            return $this->recoverIfMainFinished($counterpartyId) ?? $status;
        }

        $mirror = app(ProductImageMirror::class);
        $pending = $mirror->pendingMainProductIds($counterpartyId);

        if ($pending === []) {
            $status['current'] = (int) ($status['total'] ?? 0);
            $this->put($counterpartyId, $status);

            return $this->recoverIfMainFinished($counterpartyId) ?? $status;
        }

        $status['message'] = 'Дозавантажуємо решту товарів ('.count($pending).')...';
        $status['last_activity_at'] = now()->toIso8601String();
        $this->put($counterpartyId, $status);

        foreach ($pending as $productId) {
            \App\Jobs\MirrorOneProductImageJob::dispatch($counterpartyId, $productId);
        }

        return $this->status($counterpartyId) ?? $status;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function isIdle(array $status, int $idleSeconds): bool
    {
        $lastActivity = $status['last_activity_at'] ?? $status['started_at'] ?? null;

        if (! is_string($lastActivity) || $lastActivity === '') {
            return false;
        }

        return now()->subSeconds($idleSeconds)->isAfter($lastActivity);
    }

    public function failStale(int $counterpartyId, int $idleSeconds = 900): ?array
    {
        $status = $this->status($counterpartyId);

        if (($status['state'] ?? null) !== 'running') {
            return null;
        }

        if (($status['phase'] ?? null) === 'main' && $this->isMainBatchCompleteStatus($status)) {
            return $this->kickoffGalleryPhase($counterpartyId, $status);
        }

        if ($this->recoverIfStuck($counterpartyId, idleSeconds: 60) !== null) {
            return $this->status($counterpartyId);
        }

        $lastActivity = $status['last_activity_at'] ?? $status['started_at'] ?? null;

        if (! is_string($lastActivity) || $lastActivity === '') {
            return null;
        }

        if (! now()->subSeconds($idleSeconds)->isAfter($lastActivity)) {
            return null;
        }

        return $this->fail(
            $counterpartyId,
            'Завантаження фото не оновлювалось понад '.(int) ($idleSeconds / 60).' хв. Перезапустіть: php artisan products:mirror-images '.$counterpartyId,
        );
    }

    public function completeIfIdle(int $counterpartyId): ?array
    {
        $status = $this->status($counterpartyId);

        if (($status['state'] ?? null) === 'running') {
            return $this->complete($counterpartyId, 0, 0, 0);
        }

        return null;
    }

    public function fail(int $counterpartyId, string $message): array
    {
        $status = $this->status($counterpartyId) ?? $this->start($counterpartyId, 0);

        if (($status['state'] ?? null) === 'cancelled') {
            return $status;
        }

        $status['state'] = 'failed';
        $status['stage'] = 'Помилка завантаження фото';
        $status['message'] = $message;
        $status['finished_at'] = now()->toIso8601String();

        Cache::put($this->key($counterpartyId), $status, now()->addDay());

        return $status;
    }

    public function cancel(int $counterpartyId): array
    {
        $status = $this->status($counterpartyId) ?? $this->start($counterpartyId, 0);
        $status['state'] = 'cancelled';
        $status['stage'] = 'Завантаження фото зупинено';
        $status['message'] = 'Імпорт фото зупинено користувачем. Його можна запустити повторно.';
        $status['last_activity_at'] = now()->toIso8601String();
        $status['finished_at'] = now()->toIso8601String();

        Cache::put($this->key($counterpartyId), $status, now()->addDay());

        Log::notice('Product image mirror cancelled by user', [
            'counterparty_id' => $counterpartyId,
            'phase' => $status['phase'] ?? null,
            'current' => (int) ($status['current'] ?? 0),
            'total' => (int) ($status['total'] ?? 0),
        ]);

        return $status;
    }

    public function isCancelled(int $counterpartyId): bool
    {
        return ($this->status($counterpartyId)['state'] ?? null) === 'cancelled';
    }

    public function status(int $counterpartyId): ?array
    {
        $status = Cache::get($this->key($counterpartyId));

        return is_array($status) ? $status : null;
    }

    public function isRunning(int $counterpartyId): bool
    {
        $status = $this->status($counterpartyId);

        if (($status['state'] ?? null) !== 'running') {
            return false;
        }

        $startedAt = $status['started_at'] ?? null;

        if (! is_string($startedAt) || $startedAt === '') {
            return false;
        }

        return ! now()->subSeconds(self::TTL_SECONDS)->isAfter($startedAt);
    }

    public function key(int $counterpartyId): string
    {
        return 'counterparty:'.$counterpartyId.':image-mirror-progress';
    }

    private function put(int $counterpartyId, array $status): void
    {
        Cache::put($this->key($counterpartyId), $status, now()->addSeconds(self::TTL_SECONDS));
    }
}
