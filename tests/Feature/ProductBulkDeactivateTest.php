<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductBulkDeactivateTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_deactivate_selected_products_in_admin_table(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $category = Category::create(['name' => 'Спінінги', 'slug' => 'spinning-rods']);

        $products = collect([
            Product::create([
                'category_id' => $category->id,
                'name' => 'Спінінг 1',
                'slug' => 'spinning-1',
                'price' => 1200,
                'stock' => 3,
                'is_processed' => true,
                'is_active' => true,
            ]),
            Product::create([
                'category_id' => $category->id,
                'name' => 'Спінінг 2',
                'slug' => 'spinning-2',
                'price' => 1500,
                'stock' => 2,
                'is_processed' => true,
                'is_active' => true,
            ]),
        ]);

        Livewire::actingAs($admin)
            ->test(ListProducts::class)
            ->callTableBulkAction('deactivateSelected', $products);

        foreach ($products as $product) {
            $this->assertFalse($product->fresh()->is_active);
        }
    }

    public function test_bulk_deactivate_applies_to_all_pages_when_all_records_are_selected(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $category = Category::create(['name' => 'Воблери', 'slug' => 'wobblers']);

        $products = collect();
        for ($i = 1; $i <= 15; $i++) {
            $products->push(Product::create([
                'category_id' => $category->id,
                'name' => "Воблер {$i}",
                'slug' => "wobbler-{$i}",
                'price' => 100,
                'stock' => 1,
                'is_processed' => true,
                'is_active' => true,
            ]));
        }

        $allIds = $products->pluck('id')->map(fn (int $id): string => (string) $id)->all();

        Livewire::actingAs($admin)
            ->test(ListProducts::class)
            ->set('tableRecordsPerPage', 10)
            ->set('selectedTableRecords', $allIds)
            ->mountAction(TestAction::make('deactivateSelected')->table()->bulk())
            ->callMountedAction();

        foreach ($products as $product) {
            $this->assertFalse($product->fresh()->is_active, "Product {$product->id} should be inactive");
        }
    }
}
