<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Ім\'я')
                    ->formatStateUsing(fn (User $record): string => $record->fullName())
                    ->searchable(['last_name', 'first_name', 'name', 'email'])
                    ->sortable(['last_name', 'first_name']),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->formatStateUsing(fn (User $record): string => str_contains(mb_strtolower($record->email), '@guest.kubii.local')
                        ? '—'
                        : $record->email)
                    ->placeholder('—'),
                TextColumn::make('is_guest')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Гість' : 'Зареєстрований')
                    ->color(fn (bool $state): string => $state ? 'gray' : 'success'),
                TextColumn::make('phone')
                    ->label('Телефон')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('orders_count')
                    ->label('Замовлення')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('reviews_count')
                    ->label('Відгуки')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('google_id')
                    ->label('Google')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-minus')
                    ->getStateUsing(fn (User $record): bool => filled($record->google_id)),
                IconColumn::make('email_verified_at')
                    ->label('Email підтверджено')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Реєстрація')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_guest')
                    ->label('Тип клієнта')
                    ->nullable()
                    ->placeholder('Усі')
                    ->trueLabel('Лише гості')
                    ->falseLabel('Лише зареєстровані'),
                TernaryFilter::make('email_verified_at')
                    ->label('Email підтверджено')
                    ->nullable(),
                TernaryFilter::make('google_id')
                    ->label('Через Google')
                    ->nullable()
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('google_id'),
                        false: fn ($query) => $query->whereNull('google_id'),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
