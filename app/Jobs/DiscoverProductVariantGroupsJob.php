<?php

namespace App\Jobs;

use App\Models\Category;
use App\Models\User;
use App\Services\ProductVariantGrouper;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class DiscoverProductVariantGroupsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public readonly ?int $categoryId,
        public readonly int $userId,
        public readonly bool $includeInactive = true,
    ) {
        $this->onQueue('default');
    }

    public function handle(ProductVariantGrouper $grouper): void
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);

        $category = $this->categoryId
            ? Category::query()->find($this->categoryId)
            : null;

        if ($this->categoryId !== null && $category === null) {
            $this->notifyUser(
                danger: true,
                title: 'Групування не виконано',
                body: 'Обрану категорію не знайдено.',
            );

            return;
        }

        static::putStatus($this->categoryId, [
            'state' => 'running',
            'category_id' => $this->categoryId,
            'category' => $category?->getRawOriginal('name') ?? 'Усі категорії',
            'current_category' => null,
            'created' => 0,
            'skipped' => 0,
            'categories_scanned' => 0,
            'started_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $result = $grouper->discover(
            $category,
            function (string $categoryName, int $created, int $skipped, int $categoriesScanned) use ($category): void {
                static::putStatus($this->categoryId, [
                    'state' => 'running',
                    'category_id' => $this->categoryId,
                    'category' => $category?->getRawOriginal('name') ?? 'Усі категорії',
                    'current_category' => $categoryName,
                    'created' => $created,
                    'skipped' => $skipped,
                    'categories_scanned' => $categoriesScanned,
                    'started_at' => static::status($this->categoryId)['started_at'] ?? now()->toDateTimeString(),
                    'updated_at' => now()->toDateTimeString(),
                ]);
            },
            includeInactive: $this->includeInactive,
        );
        $scopeLabel = $category
            ? 'категорія «'.$category->getRawOriginal('name').'» і '.max(0, ($result['categories_scanned'] ?? 1) - 1).' підкатегорій'
            : number_format($result['categories_scanned'] ?? 0).' категорій';

        if ($result['created'] === 0) {
            static::putStatus($this->categoryId, [
                'state' => 'finished',
                'category_id' => $this->categoryId,
                'category' => $category?->getRawOriginal('name') ?? 'Усі категорії',
                'current_category' => null,
                'created' => (int) ($result['created'] ?? 0),
                'skipped' => (int) ($result['skipped'] ?? 0),
                'categories_scanned' => (int) ($result['categories_scanned'] ?? 0),
                'finished_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);

            $this->notifyUser(
                danger: false,
                title: 'Нових груп не знайдено',
                body: 'Перевірено '.$scopeLabel.'. Пропущено '.number_format($result['skipped']).' кандидатів. Можливо, товари вже згруповані або не мають спільних ознак.',
                warning: true,
            );

            return;
        }

        static::putStatus($this->categoryId, [
            'state' => 'finished',
            'category_id' => $this->categoryId,
            'category' => $category?->getRawOriginal('name') ?? 'Усі категорії',
            'current_category' => null,
            'created' => (int) ($result['created'] ?? 0),
            'skipped' => (int) ($result['skipped'] ?? 0),
            'categories_scanned' => (int) ($result['categories_scanned'] ?? 0),
            'finished_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $this->notifyUser(
            danger: false,
            title: 'Групування завершено',
            body: 'Перевірено '.$scopeLabel.'. Створено '.number_format($result['created']).' груп, пропущено '.number_format($result['skipped']).'. Перевірте список і опублікуйте потрібні групи.',
        );
    }

    public function failed(?Throwable $exception): void
    {
        static::putStatus($this->categoryId, [
            'state' => 'failed',
            'category_id' => $this->categoryId,
            'error' => $exception?->getMessage() ?: 'Невідома помилка під час групування.',
            'updated_at' => now()->toDateTimeString(),
        ]);

        $this->notifyUser(
            danger: true,
            title: 'Помилка групування варіантів',
            body: $exception?->getMessage() ?: 'Невідома помилка під час групування.',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function status(?int $categoryId = null): ?array
    {
        $status = Cache::get(static::statusCacheKey($categoryId));

        return is_array($status) ? $status : null;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    public static function putStatus(?int $categoryId, array $status): void
    {
        Cache::put(static::statusCacheKey($categoryId), $status, now()->addHours(6));
        Cache::put(static::latestStatusCacheKey(), $status, now()->addHours(6));
    }

    public static function statusCacheKey(?int $categoryId = null): string
    {
        return 'product-variant-grouping:'.($categoryId ?? 'all');
    }

    public static function latestStatusCacheKey(): string
    {
        return 'product-variant-grouping:latest';
    }

    private function notifyUser(bool $danger, string $title, string $body, bool $warning = false): void
    {
        $user = User::query()->find($this->userId);

        if (! $user) {
            return;
        }

        try {
            $notification = Notification::make()
                ->title($title)
                ->body($body);

            if ($danger) {
                $notification->danger();
            } elseif ($warning) {
                $notification->warning();
            } else {
                $notification->success();
            }

            $notification->sendToDatabase($user);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
