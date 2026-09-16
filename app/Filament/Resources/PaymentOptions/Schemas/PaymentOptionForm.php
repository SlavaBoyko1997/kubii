<?php

namespace App\Filament\Resources\PaymentOptions\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Schema;

class PaymentOptionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Системний код')
                ->disabled()
                ->dehydrated(false),
            TextInput::make('name')
                ->label('Назва')
                ->required()
                ->maxLength(255),
            Textarea::make('description')
                ->label('Пояснення')
                ->columnSpanFull(),
            Toggle::make('is_enabled')
                ->label('Доступний покупцям')
                ->helperText('Вимкнений спосіб зникне з checkout і не пройде серверну перевірку.'),
            TextInput::make('sort_order')
                ->label('Порядок')
                ->numeric()
                ->required(),
            ViewField::make('categories')
                ->label('Доступні категорії')
                ->view('filament.forms.components.payment-option-category-tree')
                ->default([])
                ->columnSpanFull(),
        ]);
    }
}
