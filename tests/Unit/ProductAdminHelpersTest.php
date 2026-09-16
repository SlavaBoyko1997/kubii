<?php

namespace Tests\Unit;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductAdminHelpersTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_unique_product_slug_from_name(): void
    {
        $category = Category::create(['name' => 'Намети', 'slug' => 'tents']);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Намет туристичний',
            'slug' => 'namet-turystychnyy',
            'price' => 100,
            'stock' => 1,
        ]);

        $this->assertSame('namet-turystychnyy-2', ProductResource::generateUniqueProductSlug('Намет туристичний'));
    }
}
