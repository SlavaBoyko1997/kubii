<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReorganizeCatalogCategoryTreeTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_merge_keeps_products_and_changes_category(): void
    {
        $source = Category::create([
            'name' => 'Старі футболки',
            'slug' => 'stari-futbolky',
            'is_active' => true,
        ]);
        $target = Category::create([
            'name' => 'Футболки та поло',
            'slug' => 'futbolky-ta-polo',
            'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $source->id,
            'name' => 'Футболка тест',
            'slug' => 'futbolka-test',
            'price' => 100,
            'stock' => 1,
            'is_active' => true,
        ]);

        Product::query()->where('category_id', $source->id)->update(['category_id' => $target->id]);

        $this->assertSame(1, Product::count());
        $this->assertSame($target->id, $product->fresh()->category_id);
    }
}
