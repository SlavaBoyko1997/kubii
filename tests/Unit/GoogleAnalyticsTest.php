<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Support\GoogleAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_only_when_measurement_id_is_configured(): void
    {
        config([
            'services.google_analytics.measurement_id' => '',
            'services.google_analytics.enabled' => true,
        ]);
        $this->assertFalse(GoogleAnalytics::enabled());

        config(['services.google_analytics.measurement_id' => 'G-TEST123']);
        $this->assertTrue(GoogleAnalytics::enabled());

        config(['services.google_analytics.enabled' => false]);
        $this->assertFalse(GoogleAnalytics::enabled());
    }

    public function test_purchase_payload_uses_order_number_and_items(): void
    {
        $category = Category::create(['name' => 'Котушки', 'slug' => 'reels']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Котушка',
            'slug' => 'reel-test',
            'price' => 3500,
            'stock' => 1,
        ]);

        $order = Order::create([
            'total' => 3500,
            'status' => 'new',
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'pending',
            'delivery_type' => 'nova_poshta_warehouse',
            'customer_name' => 'Test User',
            'phone' => '+380991234567',
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => 'Котушка',
            'price' => 3500,
            'quantity' => 1,
            'subtotal' => 3500,
        ]);

        $payload = GoogleAnalytics::purchasePayload($order->fresh('items'));

        $this->assertSame((string) $order->number, $payload['transaction_id']);
        $this->assertSame(3500.0, $payload['value']);
        $this->assertSame('UAH', $payload['currency']);
        $this->assertSame((string) $product->id, $payload['items'][0]['item_id']);
        $this->assertSame('Котушка', $payload['items'][0]['item_name']);
    }

    public function test_product_item_payload_contains_category_and_price(): void
    {
        $category = Category::create(['name' => 'Спінінги', 'slug' => 'spinning-rods']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Спінінг Pro',
            'slug' => 'spinning-pro',
            'price' => 2200,
            'discount_percent' => 10,
            'stock' => 2,
        ]);

        $item = GoogleAnalytics::productItem($product, 2);

        $this->assertSame((string) $product->id, $item['item_id']);
        $this->assertSame('Спінінг Pro', $item['item_name']);
        $this->assertSame('Спінінги', $item['item_category']);
        $this->assertSame(1980.0, $item['price']);
        $this->assertSame(2, $item['quantity']);
    }
}
