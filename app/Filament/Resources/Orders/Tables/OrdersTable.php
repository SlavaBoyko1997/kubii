<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Support\OrderPresentation;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label('Номер')
                    ->prefix('#')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Створено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('order_type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'quick' ? 'Швидке' : 'Стандартне')
                    ->color(fn (string $state): string => $state === 'quick' ? 'warning' : 'gray'),
                TextColumn::make('customer_name')
                    ->label('Покупець')
                    ->placeholder('Уточнити телефоном')
                    ->description(fn (Order $record): ?string => $record->phone)
                    ->searchable(['customer_name', 'phone', 'email'])
                    ->url(fn (Order $record): ?string => $record->user_id
                        ? CustomerResource::getUrl('view', ['record' => $record->user_id])
                        : null)
                    ->openUrlInNewTab(false),
                TextColumn::make('items_count')
                    ->label('Товари')
                    ->counts('items')
                    ->sortable(),
                TextColumn::make('total')
                    ->label('Сума')
                    ->money('UAH')
                    ->sortable(),
                TextColumn::make('payment_method')
                    ->label('Оплата')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => OrderPresentation::paymentMethodLabel($state))
                    ->toggleable(),
                TextColumn::make('payment_status')
                    ->label('Статус оплати')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => OrderPresentation::paymentStatusLabel($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'paid' => 'success',
                        'holded' => 'info',
                        'failed' => 'danger',
                        'cancelled', 'reversed' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => OrderPresentation::statusLabel($state))
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'confirmed', 'shipped' => 'info',
                        'cancelled' => 'danger',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус замовлення')
                    ->options([
                        'new' => 'Нове',
                        'confirmed' => 'Підтверджене',
                        'shipped' => 'Відправлене',
                        'completed' => 'Виконане',
                        'cancelled' => 'Скасоване',
                    ]),
                SelectFilter::make('payment_status')
                    ->label('Статус оплати')
                    ->options([
                        'pending' => 'Очікується',
                        'holded' => 'Заблоковано',
                        'paid' => 'Сплачено',
                        'failed' => 'Помилка',
                        'cancelled' => 'Скасовано',
                        'reversed' => 'Холд скасовано',
                    ]),
                SelectFilter::make('order_type')
                    ->label('Тип')
                    ->options([
                        'standard' => 'Стандартне',
                        'quick' => 'Швидке',
                    ]),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Відкрити'),
            ])
            ->recordUrl(fn (Order $record): string => OrderResource::getUrl('edit', ['record' => $record]))
            ->toolbarActions([]);
    }
}
