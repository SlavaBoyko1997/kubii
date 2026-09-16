<?php

namespace Tests\Feature;

use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_order_page_shows_product_links_and_customer_actions(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $customer = User::factory()->create([
            'last_name' => 'Шевченко',
            'first_name' => 'Тарас',
            'phone' => '+380991234567',
        ]);
        $product = Product::create([
            'name' => 'Спінінг Pro',
            'slug' => 'spinning-pro',
            'price' => 1200,
            'stock' => 5,
            'is_active' => true,
            'sku' => 'SP-001',
        ]);
        $order = Order::create([
            'order_type' => 'standard',
            'user_id' => $customer->id,
            'customer_name' => 'Шевченко Тарас',
            'phone' => '+380991234567',
            'email' => $customer->email,
            'delivery_type' => 'nova_poshta_warehouse',
            'nova_poshta_city_name' => 'Київ',
            'nova_poshta_warehouse_number' => '15',
            'nova_poshta_warehouse_name' => 'Відділення №15',
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'pending',
            'status' => 'new',
            'total' => 2400,
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'price' => 1200,
            'quantity' => 2,
            'subtotal' => 2400,
        ]);

        Livewire::actingAs($admin)
            ->test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertSee('Спінінг Pro')
            ->assertSee('SP-001')
            ->assertSee('Відкрити на сайті')
            ->assertSee('Редагувати в адмінці')
            ->assertSee('Відкрити профіль клієнта')
            ->assertSee('Клієнт');
    }
}
