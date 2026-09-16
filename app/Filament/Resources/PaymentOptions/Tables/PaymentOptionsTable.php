<?php

namespace App\Filament\Resources\PaymentOptions\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class PaymentOptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Спосіб оплати')
                    ->description(fn ($record): ?string => $record->description),
                TextColumn::make('code')
                    ->label('Код')
                    ->badge(),
                ToggleColumn::make('is_enabled')
                    ->label('Увімкнено'),
                TextColumn::make('categories_count')
                    ->label('Категорії')
                    ->counts('categories')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? "{$state} обмеж." : 'Усі'),
                TextColumn::make('sort_order')
                    ->label('Порядок')
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
