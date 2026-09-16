<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google_analytics.measurement_id' => 'G-TEST123456',
            'services.google_analytics.enabled' => true,
            'services.meta_pixel.pixel_id' => null,
            'services.meta_pixel.enabled' => false,
        ]);
    }

    public function test_store_layout_includes_google_analytics_when_configured(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('https://www.googletagmanager.com/gtag/js?id=G-TEST123456', false)
            ->assertSee('gtag(\'config\'', false)
            ->assertSee('trackAnalyticsEvent', false);
    }

    public function test_google_analytics_is_omitted_when_not_configured(): void
    {
        config(['services.google_analytics.measurement_id' => '']);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('googletagmanager.com/gtag/js', false)
            ->assertDontSee('trackAnalyticsEvent', false);
    }

    public function test_product_page_sends_view_item_event(): void
    {
        $category = Category::create(['name' => 'Намети', 'slug' => 'analytics-tents']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Намет аналітичний',
            'slug' => 'analytics-tent',
            'price' => 4000,
            'stock' => 3,
        ]);

        $this->get($product->url())
            ->assertOk()
            ->assertSee('trackAnalyticsEvent?.("view_item"', false)
            ->assertSee('Намет аналітичний', false);
    }

    public function test_success_page_sends_purchase_event_once(): void
    {
        $category = Category::create(['name' => 'Товари', 'slug' => 'analytics-goods']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Товар',
            'slug' => 'analytics-product',
            'price' => 1500,
            'stock' => 1,
        ]);

        $order = Order::create([
            'total' => 1500,
            'status' => 'new',
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'pending',
            'delivery_type' => 'nova_poshta_warehouse',
            'customer_name' => 'Test User',
            'phone' => '+380991234567',
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => 'Товар',
            'price' => 1500,
            'quantity' => 1,
            'subtotal' => 1500,
        ]);

        session(['placed_order_id' => $order->id]);

        $this->get(route('checkout.success', $order))
            ->assertOk()
            ->assertSee('trackAnalyticsEvent?.("purchase"', false)
            ->assertSee((string) $order->number, false);

        $this->get(route('checkout.success', $order))
            ->assertOk()
            ->assertDontSee('trackAnalyticsEvent?.("purchase"', false);
    }

    public function test_production_csp_allows_google_analytics_domains(): void
    {
        $response = $this->get('https://localhost/');
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://www.googletagmanager.com', $csp);
        $this->assertStringContainsString('https://analytics.google.com', $csp);
        $this->assertStringContainsString('https://www.google.com', $csp);
    }
}
