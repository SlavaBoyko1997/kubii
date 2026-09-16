<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnprocessedProductsActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_activate_unprocessed_query_enables_products_and_category(): void
    {
        $category = Category::create([
            'name' => 'Футболки',
            'slug' => 'tshirts',
            'is_active' => false,
        ]);

        $products = collect([
            Product::create([
                'category_id' => $category->id,
                'name' => 'Футболка 1',
                'slug' => 'tshirt-1',
                'price' => 650,
                'stock' => 3,
                'is_processed' => false,
                'is_active' => false,
            ]),
            Product::create([
                'category_id' => $category->id,
                'name' => 'Футболка 2',
                'slug' => 'tshirt-2',
                'price' => 650,
                'stock' => 5,
                'is_processed' => false,
                'is_active' => false,
            ]),
        ]);

        $updated = ProductResource::activateUnprocessedQuery(
            Product::query()->where('category_id', $category->id),
        );

        $this->assertSame(2, $updated);
        $this->assertTrue($category->fresh()->is_active);

        foreach ($products as $product) {
            $product->refresh();
            $this->assertTrue($product->is_processed);
            $this->assertTrue($product->is_active);
        }
    }

    public function test_enabling_active_flag_marks_product_as_processed(): void
    {
        $category = Category::create(['name' => 'Куртки', 'slug' => 'jackets']);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Куртка',
            'slug' => 'jacket',
            'price' => 2500,
            'stock' => 2,
            'is_processed' => false,
            'is_active' => false,
        ]);

        $product->update(['is_active' => true]);

        $product->refresh();

        $this->assertTrue($product->is_active);
        $this->assertTrue($product->is_processed);
    }

    public function test_deactivate_query_disables_active_products_only(): void
    {
        $category = Category::create(['name' => 'Шапки', 'slug' => 'hats']);

        $active = Product::create([
            'category_id' => $category->id,
            'name' => 'Шапка активна',
            'slug' => 'hat-active',
            'price' => 400,
            'stock' => 2,
            'is_processed' => true,
            'is_active' => true,
        ]);
        $inactive = Product::create([
            'category_id' => $category->id,
            'name' => 'Шапка неактивна',
            'slug' => 'hat-inactive',
            'price' => 400,
            'stock' => 2,
            'is_processed' => true,
            'is_active' => false,
        ]);

        $updated = ProductResource::deactivateQuery(Product::query()->where('category_id', $category->id));

        $this->assertSame(1, $updated);
        $this->assertFalse($active->fresh()->is_active);
        $this->assertTrue($active->fresh()->is_processed);
        $this->assertFalse($inactive->fresh()->is_active);
    }

    public function test_products_in_category_query_includes_descendants(): void
    {
        $root = Category::create(['name' => 'Одяг', 'slug' => 'clothes']);
        $child = Category::create(['name' => 'Шапки', 'slug' => 'hats', 'parent_id' => $root->id]);

        $rootProduct = Product::create([
            'category_id' => $root->id,
            'name' => 'Куртка',
            'slug' => 'jacket-root',
            'price' => 1000,
            'stock' => 1,
            'is_active' => true,
        ]);
        $childProduct = Product::create([
            'category_id' => $child->id,
            'name' => 'Шапка',
            'slug' => 'hat-child',
            'price' => 400,
            'stock' => 1,
            'is_active' => true,
        ]);

        $updated = ProductResource::deactivateQuery(
            ProductResource::productsInCategoryQuery($root->id),
        );

        $this->assertSame(2, $updated);
        $this->assertFalse($rootProduct->fresh()->is_active);
        $this->assertFalse($childProduct->fresh()->is_active);
    }

    public function test_unprocessed_category_options_only_lists_categories_with_pending_products(): void
    {
        $withPending = Category::create(['name' => 'Штани', 'slug' => 'pants']);
        Category::create(['name' => 'Пуста', 'slug' => 'empty']);

        Product::create([
            'category_id' => $withPending->id,
            'name' => 'Штани 1',
            'slug' => 'pants-1',
            'price' => 900,
            'stock' => 1,
            'is_processed' => false,
        ]);

        $options = ProductResource::unprocessedCategoryOptions();

        $this->assertSame(['Штани'], array_values($options));
        $this->assertArrayHasKey($withPending->id, $options);
    }

    public function test_activate_query_ignores_table_sorting_when_collecting_categories(): void
    {
        $category = Category::create(['name' => 'Катушки', 'slug' => 'reels', 'is_active' => false]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Катушка 1',
            'slug' => 'reel-1',
            'price' => 900,
            'stock' => 2,
            'is_processed' => false,
            'is_active' => false,
        ]);

        $updated = ProductResource::activateUnprocessedQuery(
            Product::query()
                ->where('category_id', $category->id)
                ->orderBy('id'),
        );

        $this->assertSame(1, $updated);
        $this->assertTrue($category->fresh()->is_active);
    }

    public function test_processed_category_filter_options_include_categories_with_processed_products_only(): void
    {
        $processedCategory = Category::create(['name' => 'Спінінги', 'slug' => 'spinning-rods']);
        $unprocessedCategory = Category::create(['name' => 'Воблери', 'slug' => 'wobblers']);

        Product::create([
            'category_id' => $processedCategory->id,
            'name' => 'Спінінг',
            'slug' => 'spinning-rod',
            'price' => 1200,
            'stock' => 2,
            'is_processed' => true,
            'is_active' => true,
        ]);
        Product::create([
            'category_id' => $unprocessedCategory->id,
            'name' => 'Воблер',
            'slug' => 'wobbler',
            'price' => 400,
            'stock' => 1,
            'is_processed' => false,
        ]);

        $processedOptions = ProductResource::productCategoryFilterOptions('processed');
        $allOptions = ProductResource::productCategoryFilterOptions('all');

        $this->assertSame(['Спінінги'], array_values($processedOptions));
        $this->assertArrayHasKey($processedCategory->id, $processedOptions);
        $this->assertArrayNotHasKey($unprocessedCategory->id, $processedOptions);
        $this->assertArrayHasKey($processedCategory->id, $allOptions);
        $this->assertArrayHasKey($unprocessedCategory->id, $allOptions);
    }
}
