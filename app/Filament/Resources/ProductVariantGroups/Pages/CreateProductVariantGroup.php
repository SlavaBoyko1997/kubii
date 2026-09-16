<?php

namespace App\Filament\Resources\ProductVariantGroups\Pages;

use App\Filament\Resources\ProductVariantGroups\ProductVariantGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductVariantGroup extends CreateRecord
{
    protected static string $resource = ProductVariantGroupResource::class;
}
