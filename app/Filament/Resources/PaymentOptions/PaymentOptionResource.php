<?php

namespace App\Filament\Resources\PaymentOptions;

use App\Filament\Resources\PaymentOptions\Pages\EditPaymentOption;
use App\Filament\Resources\PaymentOptions\Pages\ListPaymentOptions;
use App\Filament\Resources\PaymentOptions\Schemas\PaymentOptionForm;
use App\Filament\Resources\PaymentOptions\Tables\PaymentOptionsTable;
use App\Models\PaymentOption;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PaymentOptionResource extends Resource
{
    protected static ?string $model = PaymentOption::class;

    protected static ?string $navigationLabel = 'Способи оплати';

    protected static ?string $modelLabel = 'спосіб оплати';

    protected static ?string $pluralModelLabel = 'способи оплати';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 40;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return PaymentOptionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentOptionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentOptions::route('/'),
            'edit' => EditPaymentOption::route('/{record}/edit'),
        ];
    }
}
