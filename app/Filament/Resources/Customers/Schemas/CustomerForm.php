<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Models\User;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('last_name')->label('Прізвище')->disabled(),
                TextInput::make('first_name')->label('Ім\'я')->disabled(),
                TextInput::make('patronymic')->label('По батькові')->disabled(),
                TextInput::make('email')->label('Email')->disabled()
                    ->formatStateUsing(fn (User $record): string => str_contains(mb_strtolower($record->email), '@guest.kubii.local')
                        ? '—'
                        : $record->email),
                TextInput::make('phone')->label('Телефон')->disabled(),
                Placeholder::make('customer_type')
                    ->label('Тип')
                    ->content(fn (User $record): string => $record->is_guest ? 'Гість (оформив замовлення без реєстрації)' : 'Зареєстрований клієнт'),
                Placeholder::make('google_auth')
                    ->label('Авторизація')
                    ->content(fn (User $record): string => match (true) {
                        filled($record->google_id) => 'Google',
                        $record->is_guest => 'Профіль створено автоматично',
                        default => 'Email і пароль',
                    }),
                Placeholder::make('email_verified')
                    ->label('Email підтверджено')
                    ->content(fn (User $record): string => $record->email_verified_at?->format('d.m.Y H:i') ?? 'Ні'),
                Placeholder::make('registered_at')
                    ->label(fn (User $record): string => $record->is_guest ? 'Профіль створено' : 'Дата реєстрації')
                    ->content(fn (User $record): string => $record->created_at?->format('d.m.Y H:i') ?? '—'),
                Placeholder::make('orders_total')
                    ->label('Замовлень')
                    ->content(fn (User $record): string => (string) $record->orders()->count()),
                Placeholder::make('reviews_total')
                    ->label('Відгуків')
                    ->content(fn (User $record): string => (string) $record->reviews()->count()),
            ]);
    }
}
