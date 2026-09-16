<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Support\CategoryTreeBuilder;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Усі товари'),
            'unprocessed' => Tab::make('Необроблені')
                ->badge(fn (): int => Product::query()->where('is_processed', false)->count())
                ->badgeColor('warning')
                ->query(fn (Builder $query): Builder => $query->where('is_processed', false)),
            'processed' => Tab::make('Оброблені')
                ->query(fn (Builder $query): Builder => $query->where('is_processed', true)),
            'catalog_issues' => Tab::make('Без категорії / у вимкненій')
                ->badge(fn (): int => Product::query()->catalogPlacementIssues()->count())
                ->badgeColor('danger')
                ->query(fn (Builder $query): Builder => $query->catalogPlacementIssues()),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('assignCatalogAttributesFiltered')
                ->label('Присвоїти бренд і модель')
                ->icon(Heroicon::OutlinedTag)
                ->color('info')
                ->schema([
                    ProductResource::brandField(),
                    ProductResource::modelField(),
                ])
                ->action(function (array $data): void {
                    $updated = ProductResource::assignCatalogAttributesQuery(
                        $this->getFilteredTableQuery(),
                        $data,
                    );

                    if ($updated === 0) {
                        Notification::make()
                            ->warning()
                            ->title('Нічого не змінено')
                            ->body('Заповніть бренд або модель.')
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title('Дані оновлено')
                        ->body('Оновлено '.number_format($updated).' товарів.')
                        ->send();
                }),
            Action::make('enableAllInCategory')
                ->label('Увімкнути всі в категорії')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->schema([
                    Select::make('category_id')
                        ->label('Категорія')
                        ->options(fn (): array => $this->activeTab === 'unprocessed'
                            ? ProductResource::unprocessedCategoryOptions()
                            : app(CategoryTreeBuilder::class)->options())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->native(false)
                        ->helperText($this->activeTab === 'unprocessed'
                            ? 'Увімкне всі необроблені товари цієї категорії та саму категорію на сайті.'
                            : 'Увімкне всі неактивні товари обраної категорії.'),
                ])
                ->action(function (array $data): void {
                    $query = Product::query()->where('category_id', $data['category_id']);

                    $updated = $this->activeTab === 'unprocessed'
                        ? ProductResource::activateUnprocessedQuery($query)
                        : ProductResource::activateQuery($query);

                    if ($updated === 0) {
                        Notification::make()
                            ->warning()
                            ->title('Немає товарів для увімкнення')
                            ->body('У цій категорії немає товарів, які можна увімкнути.')
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title('Товари увімкнено')
                        ->body('Активовано '.number_format($updated).' товарів.')
                        ->send();
                }),
            Action::make('enableAllFiltered')
                ->label('Увімкнути відфільтровані')
                ->icon(Heroicon::OutlinedBolt)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Увімкнути відфільтровані товари')
                ->modalDescription(function (): string {
                    $query = $this->getFilteredTableQuery()->whereNotNull('category_id');

                    if ($this->activeTab === 'unprocessed') {
                        $query->where('is_processed', false);
                    }

                    return 'Буде увімкнено '.number_format((int) $query->count()).' товарів з поточного фільтра.';
                })
                ->action(function (): void {
                    $updated = $this->activeTab === 'unprocessed'
                        ? ProductResource::activateUnprocessedQuery($this->getFilteredTableQuery())
                        : ProductResource::activateQuery($this->getFilteredTableQuery());

                    if ($updated === 0) {
                        Notification::make()
                            ->warning()
                            ->title('Немає товарів для увімкнення')
                            ->body('Застосуйте фільтр категорії або оберіть товари з призначеною категорією.')
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title('Товари увімкнено')
                        ->body('Активовано '.number_format($updated).' товарів.')
                        ->send();
                }),
            Action::make('disableAllInCategory')
                ->label('Вимкнути всі в категорії')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->schema([
                    Select::make('category_id')
                        ->label('Категорія')
                        ->options(fn (): array => ProductResource::activeProductCategoryOptions() ?: app(CategoryTreeBuilder::class)->options())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->native(false)
                        ->helperText('Приховає всі активні товари обраної категорії та її підкатегорій на сайті.'),
                ])
                ->action(function (array $data): void {
                    $query = ProductResource::productsInCategoryQuery((int) $data['category_id']);
                    $updated = $this->activeTab === 'unprocessed'
                        ? ProductResource::deactivateUnprocessedQuery($query)
                        : ProductResource::deactivateQuery($query);

                    if ($updated === 0) {
                        Notification::make()
                            ->warning()
                            ->title('Немає товарів для вимкнення')
                            ->body($this->activeTab === 'unprocessed'
                                ? 'У цій категорії немає необроблених товарів.'
                                : 'У цій категорії немає активних товарів.')
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title('Товари вимкнено')
                        ->body('Вимкнено '.number_format($updated).' товарів.')
                        ->send();
                }),
            Action::make('disableAllFiltered')
                ->label('Вимкнути відфільтровані')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Вимкнути відфільтровані товари')
                ->modalDescription(function (): string {
                    $count = (int) $this->getFilteredTableQuery()
                        ->where('is_active', true)
                        ->count();

                    return 'Буде вимкнено '.number_format($count).' активних товарів з поточного фільтра.';
                })
                ->action(function (): void {
                    $updated = $this->activeTab === 'unprocessed'
                        ? ProductResource::deactivateUnprocessedQuery($this->getFilteredTableQuery())
                        : ProductResource::deactivateQuery($this->getFilteredTableQuery());

                    if ($updated === 0) {
                        Notification::make()
                            ->warning()
                            ->title('Немає товарів для вимкнення')
                            ->body($this->activeTab === 'unprocessed'
                                ? 'Серед відфільтрованих товарів немає необроблених.'
                                : 'Серед відфільтрованих товарів немає активних.')
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title('Товари вимкнено')
                        ->body('Вимкнено '.number_format($updated).' товарів.')
                        ->send();
                }),
            Action::make('deleteUnprocessedFiltered')
                ->label('Видалити необроблені')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->visible(fn (): bool => $this->activeTab === 'unprocessed')
                ->requiresConfirmation()
                ->modalHeading('Видалити необроблені товари')
                ->modalDescription(function (): string {
                    $count = (int) $this->getFilteredTableQuery()
                        ->where('is_processed', false)
                        ->count();

                    return 'Буде остаточно видалено '.number_format($count).' необроблених товарів з поточного фільтра. Оброблені товари не чіпаються. Після цього можна знову завантажити фід.';
                })
                ->modalSubmitActionLabel('Видалити')
                ->action(function (): void {
                    $deleted = ProductResource::deleteUnprocessedQuery($this->getFilteredTableQuery());

                    if ($deleted === 0) {
                        Notification::make()
                            ->warning()
                            ->title('Немає товарів для видалення')
                            ->body('У поточному фільтрі немає необроблених товарів.')
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title('Необроблені видалено')
                        ->body('Видалено '.number_format($deleted).' товарів. Можна знову імпортувати фід.')
                        ->send();
                }),
            CreateAction::make()
                ->label('Новий товар'),
        ];
    }
}
