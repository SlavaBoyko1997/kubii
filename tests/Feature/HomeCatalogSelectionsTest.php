<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HomeCatalogSelectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_home_sliders_exclude_out_of_stock_products(): void
    {
        $category = Category::create(['name' => 'Спорядження', 'slug' => 'gear']);

        $inStockSale = Product::create([
            'category_id' => $category->id,
            'name' => 'Акційний товар у наявності',
            'slug' => 'sale-in-stock',
            'price' => 1000,
            'discount_percent' => 15,
            'stock' => 3,
        ]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Акційний товар без наявності',
            'slug' => 'sale-out-of-stock',
            'price' => 900,
            'discount_percent' => 25,
            'stock' => 0,
        ]);

        $inStockPopular = Product::create([
            'category_id' => $category->id,
            'name' => 'Популярний у наявності',
            'slug' => 'popular-in-stock',
            'brand' => 'Naturehike',
            'price' => 2000,
            'stock' => 5,
        ]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Популярний без наявності',
            'slug' => 'popular-out-of-stock',
            'brand' => 'Shimano',
            'price' => 1800,
            'stock' => 0,
        ]);

        DB::table('product_views_daily')->insert([
            ['product_id' => $inStockPopular->id, 'viewed_on' => today()->toDateString(), 'views' => 50],
            ['product_id' => Product::query()->where('slug', 'popular-out-of-stock')->value('id'), 'viewed_on' => today()->toDateString(), 'views' => 100],
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Акційний товар у наявності')
            ->assertDontSee('Акційний товар без наявності')
            ->assertSee('Популярний у наявності')
            ->assertDontSee('Популярний без наявності');
    }

    public function test_home_sale_slider_prefers_in_stock_over_higher_discount(): void
    {
        $category = Category::create(['name' => 'Спорядження', 'slug' => 'gear-sale']);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Акція без наявності',
            'slug' => 'sale-oos',
            'price' => 1000,
            'discount_percent' => 30,
            'stock' => 0,
        ]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Акція в наявності',
            'slug' => 'sale-available',
            'price' => 1000,
            'discount_percent' => 10,
            'stock' => 2,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Акція в наявності')
            ->assertDontSee('Акція без наявності');
    }
}
