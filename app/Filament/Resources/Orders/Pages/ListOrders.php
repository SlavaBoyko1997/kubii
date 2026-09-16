<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    public function getTabs(): array
    {
        return [
            'new' => Tab::make('Нові')
                ->badge(fn (): int => Order::query()->whereNull('admin_reviewed_at')->count())
                ->badgeColor('warning')
                ->query(fn (Builder $query): Builder => $query->whereNull('admin_reviewed_at')),
            'processed' => Tab::make('Оброблені')
                ->query(fn (Builder $query): Builder => $query->whereNotNull('admin_reviewed_at')),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
