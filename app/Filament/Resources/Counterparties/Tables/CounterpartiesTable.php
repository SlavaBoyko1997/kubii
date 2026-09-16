<?php

namespace App\Filament\Resources\Counterparties\Tables;

use App\Filament\Resources\Counterparties\CounterpartyResource;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CounterpartiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('latestFeedSyncLog'))
            ->columns([
                TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Код')
                    ->badge()
                    ->searchable(),
                TextColumn::make('feed_url')
                    ->label('Фід')
                    ->limit(40)
                    ->tooltip(fn ($record): ?string => $record->feed_url)
                    ->toggleable(),
                TextColumn::make('products_count')
                    ->label('Товари')
                    ->counts('products')
                    ->sortable(),
                TextColumn::make('last_synced_at')
                    ->label('Синхронізація')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('latestFeedSyncLog.finished_at')
                    ->label('Останній імпорт')
                    ->formatStateUsing(function ($state, $record): string {
                        $log = $record->latestFeedSyncLog;

                        if (! $log) {
                            return '—';
                        }

                        $time = $log->finished_at?->format('d.m.Y H:i') ?? '—';

                        return $time.' · '.$log->triggerLabel().' · '.$log->statusLabel();
                    })
                    ->toggleable(),
                IconColumn::make('auto_sync')
                    ->label('Авто')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Активний')
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ])
            ->emptyStateHeading('Контрагентів ще немає')
            ->emptyStateDescription('Додайте постачальника та підключіть YML-фід для імпорту товарів.')
            ->emptyStateActions([
                \Filament\Actions\CreateAction::make()
                    ->label('Додати контрагента')
                    ->url(CounterpartyResource::getUrl('create')),
            ]);
    }
}
