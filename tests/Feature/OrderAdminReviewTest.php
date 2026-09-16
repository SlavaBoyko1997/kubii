<?php

namespace Tests\Feature;

use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderAdminReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_orders_appear_in_new_tab_until_opened(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $newOrder = $this->createOrder('1001');
        $processedOrder = $this->createOrder('1002');
        $processedOrder->update(['admin_reviewed_at' => now()]);

        Livewire::actingAs($admin)
            ->test(ListOrders::class)
            ->assertCanSeeTableRecords([$newOrder])
            ->assertCanNotSeeTableRecords([$processedOrder])
            ->set('activeTab', 'processed')
            ->assertCanSeeTableRecords([$processedOrder])
            ->assertCanNotSeeTableRecords([$newOrder]);
    }

    public function test_opening_order_moves_it_to_processed_tab(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $order = $this->createOrder('2001');

        $this->assertNull($order->fresh()->admin_reviewed_at);

        Livewire::actingAs($admin)
            ->test(EditOrder::class, ['record' => $order->getRouteKey()]);

        $this->assertNotNull($order->fresh()->admin_reviewed_at);

        Livewire::actingAs($admin)
            ->test(ListOrders::class)
            ->assertCanNotSeeTableRecords([$order->fresh()])
            ->set('activeTab', 'processed')
            ->assertCanSeeTableRecords([$order->fresh()]);
    }

    private function createOrder(string $number): Order
    {
        $product = Product::create([
            'name' => 'Товар '.$number,
            'slug' => 'product-'.$number,
            'price' => 500,
            'stock' => 2,
            'is_active' => true,
        ]);

        $order = Order::create([
            'number' => $number,
            'order_type' => 'standard',
            'customer_name' => 'Клієнт '.$number,
            'phone' => '+38099123'.substr($number, -4),
            'email' => 'client'.$number.'@example.com',
            'city' => 'Київ',
            'delivery_address' => 'вул. Тестова, 1',
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'pending',
            'status' => 'new',
            'total' => 500,
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'price' => 500,
            'quantity' => 1,
            'subtotal' => 500,
        ]);

        return $order;
    }
}
