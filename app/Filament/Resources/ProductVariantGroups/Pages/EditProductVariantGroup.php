<?php

namespace App\Filament\Resources\ProductVariantGroups\Pages;

use App\Filament\Resources\ProductVariantGroups\ProductVariantGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProductVariantGroup extends EditRecord
{
    protected static string $resource = ProductVariantGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
