<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Support\CatalogCache;
use App\Support\CategoryTreeBuilder;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Livewire;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('counterparty.name')
                    ->label('Контрагент')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('category.name')
                    ->label('Категорія')
                    ->searchable(),
                TextColumn::make('sourceFeedCategory.name')
                    ->label('Категорія фіду')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('name')
                    ->label('Назва')
                    ->searchable(),
                TextColumn::make('name_ru')
                    ->label('Название RU')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sku')
                    ->label('Код Kubii')
                    ->searchable(),
                TextColumn::make('external_id')
                    ->label('Код постачальника')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('variant_id')
                    ->label('Variant ID')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('variantGroup.title')
                    ->label('Варіантна група')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('brand')
                    ->label('Бренд')
                    ->searchable(),
                TextColumn::make('model')
                    ->label('Модель')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('price')
                    ->label('Ціна')
                    ->money('UAH')
                    ->sortable(),
                TextColumn::make('discount_percent')
                    ->label('Знижка')
                    ->suffix('%')
                    ->sortable(),
                ImageColumn::make('image_path')
                    ->label('Фото')
                    ->getStateUsing(fn (Product $record): string => $record->imageUrl())
                    ->square(),
                TextColumn::make('stock')
                    ->label('Залишок')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_featured')
                    ->label('Популярний')
                    ->boolean(),
                IconColumn::make('is_primary_variant')
                    ->label('Головний варіант')
                    ->boolean()
                    ->toggleable(),
                IconColumn::make('is_indexable')
                    ->label('SEO index')
                    ->boolean()
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Активний')
                    ->boolean(),
                IconColumn::make('is_processed')
                    ->label('Оброблений')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('source_feed_category_id')
                    ->label('Категорія фіду')
                    ->options(fn (): array => ProductResource::feedCategoryFilterOptions())
                    ->searchable()
                    ->preload(),
                SelectFilter::make('category_id')
                    ->label('Категорія')
                    ->options(function (): array {
                        $livewire = Livewire::current();
                        $tab = $livewire instanceof ListProducts ? ($livewire->activeTab ?? 'all') : 'all';
                        $options = ProductResource::productCategoryFilterOptions($tab);

                        return $options !== []
                            ? $options
                            : app(CategoryTreeBuilder::class)->options();
                    })
                    ->searchable()
                    ->preload(),
                SelectFilter::make('counterparty')
                    ->label('Контрагент')
                    ->relationship('counterparty', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->recordActions([
                Action::make('assignCatalogAttributes')
                    ->label('Категорія, бренд, модель')
                    ->icon('heroicon-o-tag')
                    ->color('info')
                    ->fillForm(fn (Product $record): array => [
                        'category_id' => $record->category_id,
                        'brand' => $record->brand,
                        'model' => $record->model,
                    ])
                    ->schema(ProductResource::catalogAssignmentSchema())
                    ->action(function (Product $record, array $data): void {
                        ProductResource::assignCatalogAttributesQuery(
                            Product::query()->whereKey($record->id),
                            $data,
                            allowClear: true,
                        );

                        Notification::make()
                            ->success()
                            ->title('Дані оновлено')
                            ->body('Категорію, бренд або модель збережено.')
                            ->send();
                    }),
                Action::make('process')
                    ->label('Прив’язати категорію')
                    ->icon('heroicon-o-link')
                    ->color('warning')
                    ->visible(fn (Product $record): bool => ! $record->is_processed)
                    ->fillForm(fn (Product $record): array => [
                        'category_id' => $record->category_id,
                        'brand' => $record->brand,
                        'model' => $record->model,
                    ])
                    ->schema([
                        ...ProductResource::catalogAssignmentSchema(categoryRequired: true),
                    ])
                    ->action(function (Product $record, array $data): void {
                        $record->update([
                            'category_id' => $data['category_id'],
                            'brand' => filled($data['brand'] ?? null) ? trim((string) $data['brand']) : null,
                            'model' => filled($data['model'] ?? null) ? trim((string) $data['model']) : null,
                            'is_processed' => true,
                            'is_active' => true,
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Товар оброблено')
                            ->body('Категорію, бренд і модель збережено, товар активовано.')
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('activateSelected')
                        ->label('Увімкнути обрані')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->fetchSelectedRecords(false)
                        ->action(function (Builder $recordsQuery, HasTable $livewire): void {
                            $updated = ProductResource::activateQuery(
                                ProductResource::resolveBulkActionQuery($livewire, $recordsQuery),
                            );

                            if ($updated === 0) {
                                Notification::make()
                                    ->warning()
                                    ->title('Немає товарів для увімкнення')
                                    ->body('Обрані товари вже активні або не мають категорії.')
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->success()
                                ->title('Товари увімкнено')
                                ->body('Активовано '.number_format($updated).' товарів.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deactivateSelected')
                        ->label('Вимкнути обрані')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->fetchSelectedRecords(false)
                        ->action(function (Builder $recordsQuery, HasTable $livewire): void {
                            $query = ProductResource::resolveBulkActionQuery($livewire, $recordsQuery);
                            $isUnprocessedTab = $livewire instanceof ListProducts && ($livewire->activeTab ?? null) === 'unprocessed';
                            $updated = $isUnprocessedTab
                                ? ProductResource::deactivateUnprocessedQuery($query)
                                : ProductResource::deactivateQuery($query);

                            if ($updated === 0) {
                                Notification::make()
                                    ->warning()
                                    ->title('Немає товарів для вимкнення')
                                    ->body($isUnprocessedTab
                                        ? 'Серед обраних немає необроблених товарів.'
                                        : 'Обрані товари вже неактивні.')
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->success()
                                ->title('Товари вимкнено')
                                ->body('Вимкнено '.number_format($updated).' товарів.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('activateWithCurrentCategory')
                        ->label('Увімкнути з поточною категорією')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->fetchSelectedRecords(false)
                        ->modalDescription('Обрані товари буде позначено обробленими та активними з категорією, яка вже призначена з фіду.')
                        ->action(function (Builder $recordsQuery, HasTable $livewire): void {
                            $query = ProductResource::resolveBulkActionQuery($livewire, $recordsQuery);

                            $updated = ProductResource::activateUnprocessedQuery(
                                (clone $query)
                                    ->where('is_processed', false)
                                    ->whereNotNull('category_id'),
                            );

                            if ($updated === 0) {
                                Notification::make()
                                    ->warning()
                                    ->title('Немає товарів для увімкнення')
                                    ->body('Оберіть необроблені товари з уже призначеною категорією.')
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->success()
                                ->title('Товари увімкнено')
                                ->body('Активовано '.number_format($updated).' товарів.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('moveToCatalogCategory')
                        ->label('Перенести в категорію')
                        ->icon('heroicon-o-folder-arrow-down')
                        ->color('primary')
                        ->fetchSelectedRecords(false)
                        ->schema([
                            ProductResource::categorySelect()
                                ->required()
                                ->helperText('Обраним товарам буде призначено категорію каталогу. При наступному імпорті фіду категорія збережеться.'),
                            Toggle::make('remember_feed_category_mapping')
                                ->label('Запам\'ятати для категорії фіду')
                                ->helperText('Нові та необроблені товари з тієї ж категорії фіду автоматично потраплятимуть у цю категорію при імпорті.')
                                ->default(true),
                        ])
                        ->action(function (Builder $recordsQuery, HasTable $livewire, array $data): void {
                            $updated = ProductResource::moveToCategoryQuery(
                                ProductResource::resolveBulkActionQuery($livewire, $recordsQuery),
                                (int) $data['category_id'],
                                (bool) ($data['remember_feed_category_mapping'] ?? true),
                            );

                            if ($updated === 0) {
                                Notification::make()
                                    ->warning()
                                    ->title('Немає товарів для перенесення')
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->success()
                                ->title('Категорію оновлено')
                                ->body('Перенесено '.number_format($updated).' товарів.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('assignCatalogAttributes')
                        ->label('Присвоїти категорію, бренд і модель')
                        ->icon('heroicon-o-tag')
                        ->color('info')
                        ->fetchSelectedRecords(false)
                        ->schema(ProductResource::catalogAssignmentSchema())
                        ->action(function (Builder $recordsQuery, HasTable $livewire, array $data): void {
                            $updated = ProductResource::assignCatalogAttributesQuery(
                                ProductResource::resolveBulkActionQuery($livewire, $recordsQuery),
                                $data,
                            );

                            if ($updated === 0) {
                                Notification::make()
                                    ->warning()
                                    ->title('Нічого не змінено')
                                    ->body('Заповніть хоча б одне поле: категорію, бренд або модель.')
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->success()
                                ->title('Дані оновлено')
                                ->body('Оновлено '.number_format($updated).' товарів.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('process')
                        ->label('Призначити категорію й активувати')
                        ->icon('heroicon-o-link')
                        ->color('warning')
                        ->fetchSelectedRecords(false)
                        ->schema(ProductResource::catalogAssignmentSchema(categoryRequired: true))
                        ->action(function (Builder $recordsQuery, HasTable $livewire, array $data): void {
                            $updated = ProductResource::resolveBulkActionQuery($livewire, $recordsQuery)
                                ->reorder()
                                ->update([
                                    'category_id' => $data['category_id'],
                                    'brand' => filled($data['brand'] ?? null) ? trim((string) $data['brand']) : null,
                                    'model' => filled($data['model'] ?? null) ? trim((string) $data['model']) : null,
                                    'is_processed' => true,
                                    'is_active' => true,
                                    'updated_at' => now(),
                                ]);

                            if ($updated === 0) {
                                Notification::make()
                                    ->warning()
                                    ->title('Немає товарів для обробки')
                                    ->send();

                                return;
                            }

                            app(CatalogCache::class)->invalidate();

                            Notification::make()
                                ->success()
                                ->title('Товари оброблено')
                                ->body('Обраним товарам призначено категорію, бренд і модель.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deleteUnprocessedSelected')
                        ->label('Видалити необроблені')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->fetchSelectedRecords(false)
                        ->modalHeading('Видалити обрані необроблені')
                        ->modalDescription('Буде остаточно видалено лише необроблені товари з виділених. Оброблені залишаться.')
                        ->modalSubmitActionLabel('Видалити')
                        ->action(function (Builder $recordsQuery, HasTable $livewire): void {
                            $deleted = ProductResource::deleteUnprocessedQuery(
                                ProductResource::resolveBulkActionQuery($livewire, $recordsQuery),
                            );

                            if ($deleted === 0) {
                                Notification::make()
                                    ->warning()
                                    ->title('Немає товарів для видалення')
                                    ->body('Серед обраних немає необроблених товарів.')
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->success()
                                ->title('Необроблені видалено')
                                ->body('Видалено '.number_format($deleted).' товарів.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
