<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListCategories extends ListRecords
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('tree')
                ->label('Дерево категорій')
                ->icon(Heroicon::OutlinedQueueList)
                ->url(CategoryResource::getUrl('tree')),
            CreateAction::make()
                ->label('Нова категорія'),
        ];
    }
}
