<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Orders\OrderResource;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $relatedResource = OrderResource::class;

    protected static ?string $title = 'Замовлення';

    public function table(Table $table): Table
    {
        return OrderResource::table($table)
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record): string => OrderResource::getUrl('edit', ['record' => $record])),
            ]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
