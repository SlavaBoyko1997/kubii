<?php

namespace App\Filament\Resources\ProductVariantGroups\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductVariantGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Група')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->sortable(),
                TextColumn::make('confidence')
                    ->label('Впевненість')
                    ->suffix('%')
                    ->sortable(),
                TextColumn::make('products_count')
                    ->label('Товарів')
                    ->counts('products')
                    ->sortable(),
                TextColumn::make('brand')
                    ->label('Бренд')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category.name')
                    ->label('Категорія')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('primaryProduct.name')
                    ->label('Головний товар')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('grouping_level')
                    ->label('Рівень')
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Оновлено')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
