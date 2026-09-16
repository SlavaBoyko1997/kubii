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

class ProductBulkActivateTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_activate_selected_unprocessed_products(): void
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
                'is_processed' => false,
                'is_active' => false,
            ]),
            Product::create([
                'category_id' => $category->id,
                'name' => 'Спінінг 2',
                'slug' => 'spinning-2',
                'price' => 1500,
                'stock' => 2,
                'is_processed' => false,
                'is_active' => false,
            ]),
        ]);

        Livewire::actingAs($admin)
            ->test(ListProducts::class)
            ->callTableBulkAction('activateSelected', $products);

        foreach ($products as $product) {
            $product->refresh();
            $this->assertTrue($product->is_active);
            $this->assertTrue($product->is_processed);
        }
    }

    public function test_bulk_activate_applies_to_all_pages_when_all_records_are_selected(): void
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
                'is_processed' => false,
                'is_active' => false,
            ]));
        }

        Livewire::actingAs($admin)
            ->test(ListProducts::class)
            ->set('tableRecordsPerPage', 10)
            ->set('isTrackingDeselectedTableRecords', true)
            ->set('selectedTableRecords', [])
            ->set('deselectedTableRecords', [])
            ->mountAction(TestAction::make('activateSelected')->table()->bulk())
            ->callMountedAction();

        foreach ($products as $product) {
            $product->refresh();
            $this->assertTrue($product->is_active, "Product {$product->id} should be active");
            $this->assertTrue($product->is_processed);
        }
    }

    public function test_bulk_activate_uses_filtered_query_when_all_keys_are_selected(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $category = Category::create(['name' => 'Блешні', 'slug' => 'spinners']);

        $products = collect();
        for ($i = 1; $i <= 12; $i++) {
            $products->push(Product::create([
                'category_id' => $category->id,
                'name' => "Блешня {$i}",
                'slug' => "spinner-{$i}",
                'price' => 100,
                'stock' => 1,
                'is_processed' => false,
                'is_active' => false,
            ]));
        }

        $allIds = $products->pluck('id')->map(fn (int $id): string => (string) $id)->all();

        Livewire::actingAs($admin)
            ->test(ListProducts::class)
            ->set('tableRecordsPerPage', 10)
            ->set('selectedTableRecords', $allIds)
            ->mountAction(TestAction::make('activateSelected')->table()->bulk())
            ->callMountedAction();

        foreach ($products as $product) {
            $product->refresh();
            $this->assertTrue($product->is_active);
        }
    }
}
