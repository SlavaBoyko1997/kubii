<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductCatalogIssuesTabTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_issues_tab_shows_products_without_category_or_active_in_disabled_category(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $activeCategory = Category::create(['name' => 'Активна', 'slug' => 'active-category', 'is_active' => true]);
        $disabledCategory = Category::create(['name' => 'Вимкнена', 'slug' => 'disabled-category', 'is_active' => false]);

        $withoutCategory = Product::create([
            'name' => 'Без категорії',
            'slug' => 'without-category',
            'price' => 100,
            'stock' => 1,
            'is_active' => false,
            'is_processed' => false,
        ]);

        $activeInDisabledCategory = Product::create([
            'category_id' => $disabledCategory->id,
            'name' => 'Увімкнений у вимкненій',
            'slug' => 'active-in-disabled-category',
            'price' => 200,
            'stock' => 1,
            'is_active' => true,
            'is_processed' => true,
        ]);

        $inactiveInDisabledCategory = Product::create([
            'category_id' => $disabledCategory->id,
            'name' => 'Вимкнений у вимкненій',
            'slug' => 'inactive-in-disabled-category',
            'price' => 250,
            'stock' => 1,
            'is_active' => false,
            'is_processed' => true,
        ]);

        $healthyProduct = Product::create([
            'category_id' => $activeCategory->id,
            'name' => 'Нормальний товар',
            'slug' => 'healthy-product',
            'price' => 300,
            'stock' => 1,
            'is_active' => true,
            'is_processed' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ListProducts::class)
            ->set('activeTab', 'catalog_issues')
            ->assertCanSeeTableRecords([$withoutCategory, $activeInDisabledCategory])
            ->assertCanNotSeeTableRecords([$inactiveInDisabledCategory, $healthyProduct]);
    }
}
