<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Support\TelegramOrderMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramOrderMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.orders_enabled' => false]);
    }

    public function test_message_includes_order_details_without_breaking_html(): void
    {
        $category = Category::create(['name' => 'Telegram', 'slug' => 'telegram-msg']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Намет <Pro>',
            'slug' => 'telegram-tent',
            'price' => 1500,
            'stock' => 2,
        ]);

        $order = Order::create([
            'order_type' => 'standard',
            'status' => 'new',
            'customer_name' => 'Іван & Марія',
            'phone' => '+380991112233',
            'email' => 'buyer@example.com',
            'delivery_type' => 'nova_poshta_warehouse',
            'nova_poshta_city_name' => 'Київ',
            'nova_poshta_warehouse_name' => 'Відділення №1',
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'pending',
            'total' => 3000,
            'comment' => 'Подзвоніть <після> 18:00',
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => 'Намет <Pro>',
            'price' => 1500,
            'quantity' => 2,
            'subtotal' => 3000,
        ]);

        $message = TelegramOrderMessage::fromOrder($order->fresh('items'));

        $this->assertStringContainsString('Нове замовлення №'.$order->number, $message);
        $this->assertStringContainsString('+380991112233', $message);
        $this->assertStringContainsString('buyer@example.com', $message);
        $this->assertStringContainsString('Київ', $message);
        $this->assertStringContainsString('Намет &lt;Pro&gt;', $message);
        $this->assertStringContainsString('3 000 ₴', $message);
        $this->assertStringContainsString('Подзвоніть &lt;після&gt; 18:00', $message);
        $this->assertStringContainsString('Іван &amp; Марія', $message);
    }
}
