<?php

namespace App\Filament\Resources\ProductVariantGroups;

use App\Filament\Resources\ProductVariantGroups\Pages\CreateProductVariantGroup;
use App\Filament\Resources\ProductVariantGroups\Pages\EditProductVariantGroup;
use App\Filament\Resources\ProductVariantGroups\Pages\ListProductVariantGroups;
use App\Filament\Resources\ProductVariantGroups\Schemas\ProductVariantGroupForm;
use App\Filament\Resources\ProductVariantGroups\Tables\ProductVariantGroupsTable;
use App\Models\ProductVariantGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductVariantGroupResource extends Resource
{
    protected static ?string $model = ProductVariantGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Варіантні групи';

    protected static ?string $modelLabel = 'варіантна група';

    protected static ?string $pluralModelLabel = 'варіантні групи';

    public static function form(Schema $schema): Schema
    {
        return ProductVariantGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductVariantGroupsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductVariantGroups::route('/'),
            'create' => CreateProductVariantGroup::route('/create'),
            'edit' => EditProductVariantGroup::route('/{record}/edit'),
        ];
    }
}
