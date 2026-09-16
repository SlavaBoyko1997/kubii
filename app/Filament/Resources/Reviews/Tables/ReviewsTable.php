<?php

namespace App\Filament\Resources\Reviews\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ReviewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')->label('Товар')->searchable()->limit(35),
                TextColumn::make('user.name')->label('Клієнт')->searchable(),
                TextColumn::make('parent.user.name')->label('Відповідь на')->placeholder('Кореневий відгук'),
                TextColumn::make('rating')->label('Оцінка')->formatStateUsing(fn (?int $state): string => $state ? str_repeat('★', $state) : 'Відповідь'),
                IconColumn::make('is_verified_purchase')->label('Куплено')->boolean(),
                IconColumn::make('is_demo')->label('Demo')->boolean(),
                IconColumn::make('is_ai_generated')->label('AI')->boolean(),
                TextColumn::make('title')->label('Заголовок')->limit(35),
                TextColumn::make('generation_batch_id')->label('Batch')->limit(8)->copyable(),
                IconColumn::make('is_visible')->label('Видимий')->boolean(),
                TextColumn::make('created_at')->label('Створено')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_visible')->label('Видимий'),
                TernaryFilter::make('is_demo')->label('Тестовий'),
                TernaryFilter::make('is_ai_generated')->label('AI'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('show')
                        ->label('Увімкнути')
                        ->action(fn (Collection $records): mixed => $records->each->update(['is_visible' => true])),
                    BulkAction::make('hide')
                        ->label('Вимкнути')
                        ->color('warning')
                        ->action(fn (Collection $records): mixed => $records->each->update(['is_visible' => false])),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
