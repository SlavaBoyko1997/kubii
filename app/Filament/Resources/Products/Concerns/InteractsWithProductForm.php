<?php

namespace App\Filament\Resources\Products\Concerns;

use App\Models\Product;
use Illuminate\Support\Str;

trait InteractsWithProductForm
{
    /**
     * @return array<string, mixed>
     */
    public static function defaultProductFormData(?int $categoryId = null): array
    {
        return [
            'category_id' => $categoryId,
            'stock' => 1,
            'discount_percent' => 0,
            'is_active' => true,
            'is_processed' => true,
            'is_featured' => false,
            'is_indexable' => true,
            'canonical_type' => 'self',
        ];
    }

    public static function generateUniqueProductSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name, '-', 'uk') ?: Str::slug($name) ?: 'product';
        $base = Str::limit($base, 220, '');
        $slug = $base;
        $suffix = 2;

        while (Product::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = Str::limit($base, 220 - strlen((string) $suffix), '').'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
