<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UnprocessedProductsDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_header_action_deletes_only_filtered_unprocessed_products(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $category = Category::create(['name' => 'Намети', 'slug' => 'tents-delete']);

        $unprocessed = Product::create([
            'category_id' => $category->id,
            'name' => 'Дубль з фіду',
            'slug' => 'feed-duplicate',
            'price' => 1000,
            'stock' => 1,
            'is_processed' => false,
            'is_active' => false,
        ]);
        $processed = Product::create([
            'category_id' => $category->id,
            'name' => 'Вже оброблений',
            'slug' => 'already-processed',
            'price' => 1200,
            'stock' => 2,
            'is_processed' => true,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ListProducts::class)
            ->set('activeTab', 'unprocessed')
            ->callAction('deleteUnprocessedFiltered');

        $this->assertDatabaseMissing('products', ['id' => $unprocessed->id]);
        $this->assertDatabaseHas('products', ['id' => $processed->id]);
    }

    public function test_delete_unprocessed_query_never_removes_processed_products(): void
    {
        $category = Category::create(['name' => 'Крісла', 'slug' => 'chairs-delete']);

        $unprocessed = Product::create([
            'category_id' => $category->id,
            'name' => 'Необроблений',
            'slug' => 'unprocessed-chair',
            'price' => 800,
            'stock' => 1,
            'is_processed' => false,
            'is_active' => false,
        ]);
        $processed = Product::create([
            'category_id' => $category->id,
            'name' => 'Оброблений',
            'slug' => 'processed-chair',
            'price' => 900,
            'stock' => 1,
            'is_processed' => true,
            'is_active' => true,
        ]);

        $deleted = ProductResource::deleteUnprocessedQuery(Product::query());

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('products', ['id' => $unprocessed->id]);
        $this->assertDatabaseHas('products', ['id' => $processed->id]);
    }

    public function test_bulk_delete_unprocessed_selected_keeps_processed_selection(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $category = Category::create(['name' => 'Ліхтарі', 'slug' => 'lamps-delete']);

        $unprocessed = Product::create([
            'category_id' => $category->id,
            'name' => 'Ліхтар дубль',
            'slug' => 'lamp-duplicate',
            'price' => 500,
            'stock' => 1,
            'is_processed' => false,
            'is_active' => false,
        ]);
        $processed = Product::create([
            'category_id' => $category->id,
            'name' => 'Ліхтар ок',
            'slug' => 'lamp-ok',
            'price' => 600,
            'stock' => 1,
            'is_processed' => true,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ListProducts::class)
            ->callTableBulkAction('deleteUnprocessedSelected', collect([$unprocessed, $processed]));

        $this->assertDatabaseMissing('products', ['id' => $unprocessed->id]);
        $this->assertDatabaseHas('products', ['id' => $processed->id]);
    }
}
