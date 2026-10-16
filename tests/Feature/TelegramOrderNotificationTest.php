<?php

namespace Tests\Feature;

use App\Jobs\SendOrderTelegramNotification;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Services\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramOrderNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => '123456:TEST-TOKEN',
            'services.telegram.chat_id' => '1002182207026',
            'services.telegram.orders_enabled' => true,
        ]);
    }

    public function test_creating_order_dispatches_telegram_job_after_commit(): void
    {
        Queue::fake();

        $order = Order::create([
            'total' => 1000,
            'status' => 'new',
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'pending',
            'customer_name' => 'Test',
            'phone' => '+380991111111',
        ]);

        Queue::assertPushed(SendOrderTelegramNotification::class, function (SendOrderTelegramNotification $job) use ($order): bool {
            return $job->orderId === $order->id;
        });
    }

    public function test_job_sends_telegram_message_with_order_payload(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);

        $category = Category::create(['name' => 'TG', 'slug' => 'tg-feat']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Рюкзак',
            'slug' => 'tg-backpack',
            'price' => 2849,
            'stock' => 1,
        ]);

        $order = Order::create([
            'total' => 2849,
            'status' => 'new',
            'payment_method' => 'iban',
            'payment_status' => 'pending',
            'customer_name' => 'Олена',
            'phone' => '+380992222222',
            'email' => 'olena@example.com',
            'delivery_type' => 'nova_poshta_warehouse',
            'nova_poshta_city_name' => 'Львів',
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => 'Рюкзак',
            'price' => 2849,
            'quantity' => 1,
            'subtotal' => 2849,
        ]);

        (new SendOrderTelegramNotification($order->id))->handle(app(TelegramNotifier::class));

        Http::assertSent(function ($request) use ($order): bool {
            if (! str_contains($request->url(), '/bot123456:TEST-TOKEN/sendMessage')) {
                return false;
            }

            $data = $request->data();

            return ($data['chat_id'] ?? null) === '-1002182207026'
                && ($data['parse_mode'] ?? null) === 'HTML'
                && str_contains((string) ($data['text'] ?? ''), '№'.$order->number)
                && str_contains((string) ($data['text'] ?? ''), 'Олена')
                && str_contains((string) ($data['text'] ?? ''), 'Рюкзак')
                && str_contains((string) ($data['text'] ?? ''), '2 849 ₴');
        });
    }

    public function test_job_is_noop_when_telegram_disabled(): void
    {
        config(['services.telegram.orders_enabled' => false]);
        Http::fake();

        $order = Order::create([
            'total' => 500,
            'status' => 'new',
            'payment_method' => 'cash_on_delivery',
            'customer_name' => 'Skip',
            'phone' => '+380993333333',
        ]);

        (new SendOrderTelegramNotification($order->id))->handle(app(TelegramNotifier::class));

        Http::assertNothingSent();
    }

    public function test_quick_order_dispatches_telegram_notification(): void
    {
        Queue::fake();

        $category = Category::create(['name' => 'Checkout TG', 'slug' => 'checkout-tg']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Товар TG',
            'slug' => 'checkout-tg-product',
            'price' => 1200,
            'stock' => 5,
            'is_active' => true,
        ]);

        $this->postJson(route('checkout.quick'), [
            'product_id' => $product->id,
            'phone' => '+380994444444',
            'quantity' => 1,
        ])->assertOk();

        $this->assertDatabaseCount('orders', 1);
        Queue::assertPushed(SendOrderTelegramNotification::class);
    }
}
