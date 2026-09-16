<?php

namespace App\Filament\Resources\ProductVariantGroups\Pages;

use App\Filament\Resources\ProductVariantGroups\ProductVariantGroupResource;
use App\Jobs\DiscoverProductVariantGroupsJob;
use App\Models\Category;
use App\Support\CategoryTreeBuilder;
use App\Support\ProductVariantGroupingWorker;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Throwable;

class ListProductVariantGroups extends ListRecords
{
    protected static string $resource = ProductVariantGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('variantGroupingStatus')
                ->label('Статус групування')
                ->icon(Heroicon::OutlinedClock)
                ->color(fn (): string => match ($this->variantGroupingStatus()['state'] ?? null) {
                    'running' => 'warning',
                    'failed' => 'danger',
                    'finished' => 'success',
                    'stopped' => 'gray',
                    default => 'gray',
                })
                ->badge(fn (): ?string => match ($this->variantGroupingStatus()['state'] ?? null) {
                    'queued' => 'У черзі',
                    'running' => 'Працює',
                    'failed' => 'Помилка',
                    'finished' => 'Готово',
                    'stopped' => 'Неактивно',
                    default => null,
                })
                ->modalHeading('Статус групування товарів')
                ->modalDescription(fn (): HtmlString => new HtmlString(nl2br(e($this->variantGroupingStatusText()))))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Закрити')
                ->visible(fn (): bool => Cache::has(DiscoverProductVariantGroupsJob::latestStatusCacheKey()))
                ->action(fn (): null => null),
            Action::make('discoverVariants')
                ->label('Згрупувати товари')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('info')
                ->schema([
                    Select::make('category_id')
                        ->label('Категорія')
                        ->options(fn (): array => app(CategoryTreeBuilder::class)->optionsForGrouping())
                        ->getSearchResultsUsing(fn (string $search): array => app(CategoryTreeBuilder::class)->searchOptionsForGrouping($search))
                        ->getOptionLabelUsing(function ($value): ?string {
                            $category = Category::query()
                                ->with('parentRecursive')
                                ->withCount('products')
                                ->find($value);

                            return $category ? app(CategoryTreeBuilder::class)->groupingOptionLabel($category, includePath: true) : null;
                        })
                        ->searchable()
                        ->required()
                        ->native(false)
                        ->helperText('Обовʼязково оберіть категорію (з підкатегоріями). Показані всі категорії, включно з фідовими та неактивними.'),
                ])
                ->requiresConfirmation()
                ->modalHeading('Знайти та згрупувати варіанти')
                ->modalDescription('Система знайде схожі товари за feed group id, моделлю або характеристиками (колір, розмір тощо) і створить варіантні групи зі статусом «Підтверджено». Після перевірки змініть статус групи на «Опубліковано», щоб об’єднання з’явилось на сайті. Worker запускається автоматично.')
                ->modalSubmitActionLabel('Запустити групування')
                ->action(function (array $data): void {
                    $userId = Auth::id();

                    if ($userId === null) {
                        Notification::make()
                            ->danger()
                            ->title('Не вдалося запустити групування')
                            ->body('Користувача не авторизовано.')
                            ->send();

                        return;
                    }

                    if (! filled($data['category_id'] ?? null)) {
                        Notification::make()
                            ->danger()
                            ->title('Оберіть категорію')
                            ->body('Групування без категорії вимкнено, щоб не покласти сайт.')
                            ->send();

                        return;
                    }

                    if ((string) config('queue.default') === 'sync') {
                        Notification::make()
                            ->danger()
                            ->title('Черга sync небезпечна')
                            ->body('Увімкніть Redis-чергу (QUEUE_CONNECTION=redis) і worker, або запустіть: php artisan variants:discover --category='.(int) $data['category_id'])
                            ->send();

                        return;
                    }

                    $category = Category::query()->find((int) $data['category_id']);

                    DiscoverProductVariantGroupsJob::putStatus((int) $data['category_id'], [
                        'state' => 'queued',
                        'category_id' => (int) $data['category_id'],
                        'category' => $category?->getRawOriginal('name') ?? 'Обрана категорія',
                        'current_category' => null,
                        'created' => 0,
                        'skipped' => 0,
                        'categories_scanned' => 0,
                        'queued_at' => now()->toDateTimeString(),
                        'updated_at' => now()->toDateTimeString(),
                    ]);

                    DiscoverProductVariantGroupsJob::dispatch(
                        (int) $data['category_id'],
                        (int) $userId,
                        includeInactive: true,
                    );

                    try {
                        $pid = app(ProductVariantGroupingWorker::class)->start();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->danger()
                            ->title('Задача в черзі, але worker не стартував')
                            ->body($exception->getMessage())
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title('Групування запущено')
                        ->body('Завдання у черзі, worker PID '.$pid.'. Прогрес видно у «Статус групування».')
                        ->send();
                }),
            CreateAction::make(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function variantGroupingStatus(): array
    {
        $status = DiscoverProductVariantGroupsJob::status()
            ?? (Cache::get(DiscoverProductVariantGroupsJob::latestStatusCacheKey()) ?: []);

        if (! is_array($status) || $status === []) {
            return [];
        }

        if (in_array($status['state'] ?? null, ['queued', 'running'], true) && $this->variantGroupingStatusIsStale($status)) {
            $status['state'] = 'stopped';
        }

        return $status;
    }

    private function variantGroupingStatusText(): string
    {
        $status = $this->variantGroupingStatus();

        if ($status === []) {
            return 'Групування ще не запускалося.';
        }

        $state = match ($status['state'] ?? null) {
            'queued' => 'У черзі',
            'running' => 'Виконується',
            'finished' => 'Завершено',
            'failed' => 'Помилка',
            'stopped' => 'Не виконується',
            default => 'Невідомо',
        };

        $lines = [
            'Стан: '.$state,
            'Категорія: '.($status['category'] ?? '—'),
        ];

        if (! empty($status['current_category'])) {
            $lines[] = 'Зараз обробляється: '.$status['current_category'];
        }

        $lines[] = 'Перевірено категорій: '.number_format((int) ($status['categories_scanned'] ?? 0));
        $lines[] = 'Створено груп: '.number_format((int) ($status['created'] ?? 0));
        $lines[] = 'Пропущено кандидатів: '.number_format((int) ($status['skipped'] ?? 0));

        if (! empty($status['error'])) {
            $lines[] = 'Помилка: '.$status['error'];
        }

        if (! empty($status['queued_at'])) {
            $lines[] = 'Поставлено в чергу: '.$status['queued_at'];
        }

        if (! empty($status['started_at'])) {
            $lines[] = 'Старт: '.$status['started_at'];
        }

        if (! empty($status['finished_at'])) {
            $lines[] = 'Фініш: '.$status['finished_at'];
        }

        if (! empty($status['updated_at'])) {
            $lines[] = 'Оновлено: '.$status['updated_at'];
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function variantGroupingStatusIsStale(array $status): bool
    {
        if (empty($status['updated_at'])) {
            return false;
        }

        try {
            return Carbon::parse((string) $status['updated_at'])->lt(now()->subMinutes(5));
        } catch (\Throwable) {
            return false;
        }
    }
}
