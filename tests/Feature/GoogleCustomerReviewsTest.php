<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleCustomerReviewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google_customer_reviews.enabled' => true,
            'services.google_customer_reviews.merchant_id' => '987654321',
            'services.google_customer_reviews.delivery_days' => 3,
        ]);
    }

    public function test_success_page_includes_opt_in_module_with_order_data(): void
    {
        $order = $this->createOrder([
            'email' => 'customer@tripfish.test',
        ]);

        session(['placed_order_id' => $order->id]);

        $response = $this->get(route('checkout.success', $order))
            ->assertOk()
            ->assertSee('https://apis.google.com/js/platform.js?onload=renderOptIn', false)
            ->assertSee('window.gapi.surveyoptin.render', false)
            ->assertSee('"merchant_id":987654321', false)
            ->assertSee('"order_id":"'.$order->number.'"', false)
            ->assertSee('"email":"customer@tripfish.test"', false)
            ->assertSee('"delivery_country":"UA"', false);

        preg_match('/"estimated_delivery_date":"(\d{4}-\d{2}-\d{2})"/', $response->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $matches[1]);
        $this->assertSame(
            $order->created_at->copy()->addDays(3)->format('Y-m-d'),
            $matches[1],
        );
    }

    public function test_opt_in_module_is_omitted_when_disabled(): void
    {
        config(['services.google_customer_reviews.enabled' => false]);

        $order = $this->createOrder(['email' => 'customer@tripfish.test']);
        session(['placed_order_id' => $order->id]);

        $this->get(route('checkout.success', $order))
            ->assertOk()
            ->assertDontSee('apis.google.com/js/platform.js', false)
            ->assertDontSee('surveyoptin', false);
    }

    public function test_opt_in_module_is_omitted_when_merchant_id_missing(): void
    {
        config(['services.google_customer_reviews.merchant_id' => '']);

        $order = $this->createOrder(['email' => 'customer@tripfish.test']);
        session(['placed_order_id' => $order->id]);

        $this->get(route('checkout.success', $order))
            ->assertOk()
            ->assertDontSee('apis.google.com/js/platform.js', false)
            ->assertDontSee('surveyoptin', false);
    }

    public function test_opt_in_module_is_omitted_without_order_email(): void
    {
        $order = $this->createOrder(['email' => null]);
        session(['placed_order_id' => $order->id]);

        $this->get(route('checkout.success', $order))
            ->assertOk()
            ->assertDontSee('apis.google.com/js/platform.js', false)
            ->assertDontSee('surveyoptin', false);
    }

    public function test_success_page_rejects_foreign_order_id_without_session(): void
    {
        $order = $this->createOrder(['email' => 'secret@tripfish.test']);

        $this->get(route('checkout.success', $order))
            ->assertNotFound()
            ->assertDontSee('secret@tripfish.test', false)
            ->assertDontSee('apis.google.com/js/platform.js', false);
    }

    public function test_production_csp_allows_google_customer_reviews_on_success_page(): void
    {
        $order = $this->createOrder(['email' => 'customer@tripfish.test']);
        session(['placed_order_id' => $order->id]);

        $response = $this->get('https://localhost/checkout/success/'.$order->id);
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://apis.google.com', $csp);
        $this->assertStringContainsString('frame-src', $csp);
        // gapi javascript: URLs cannot run when a nonce is present in script-src.
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringNotContainsString("'nonce-", $csp);
        $this->assertStringNotContainsString('script-src-elem', $csp);
    }

    public function test_production_csp_keeps_nonce_outside_success_page_when_gcr_enabled(): void
    {
        $response = $this->get('https://localhost/');
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("'nonce-", $csp);
        $this->assertStringNotContainsString('https://apis.google.com', $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
    }

    /**
     * @param  array{email?: ?string}  $attributes
     */
    private function createOrder(array $attributes = []): Order
    {
        $category = Category::create(['name' => 'GCR', 'slug' => 'gcr-'.uniqid()]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'GCR Product',
            'slug' => 'gcr-product-'.uniqid(),
            'price' => 1000,
            'stock' => 2,
        ]);

        $order = Order::create([
            'total' => 1000,
            'status' => 'new',
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'pending',
            'delivery_type' => 'nova_poshta_warehouse',
            'customer_name' => 'GCR User',
            'phone' => '+380993333333',
            'email' => array_key_exists('email', $attributes) ? $attributes['email'] : 'customer@tripfish.test',
            'city' => 'Львів',
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => 'GCR Product',
            'price' => 1000,
            'quantity' => 1,
            'subtotal' => 1000,
        ]);

        return $order->fresh();
    }
}
