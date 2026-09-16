<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Support\GoogleCustomerReviews;
use Carbon\Carbon;
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
            'services.google_customer_reviews.merchant_id' => '123456789',
            'services.google_customer_reviews.delivery_days' => 3,
        ]);
    }

    public function test_enabled_requires_flag_and_merchant_id(): void
    {
        $this->assertTrue(GoogleCustomerReviews::enabled());

        config(['services.google_customer_reviews.enabled' => false]);
        $this->assertFalse(GoogleCustomerReviews::enabled());

        config([
            'services.google_customer_reviews.enabled' => true,
            'services.google_customer_reviews.merchant_id' => '',
        ]);
        $this->assertFalse(GoogleCustomerReviews::enabled());

        config(['services.google_customer_reviews.merchant_id' => 'not-a-number']);
        $this->assertFalse(GoogleCustomerReviews::enabled());
    }

    public function test_opt_in_payload_uses_public_order_number_and_ua_country(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-24 12:00:00'));

        $order = Order::create([
            'total' => 1500,
            'status' => 'new',
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'pending',
            'delivery_type' => 'nova_poshta_warehouse',
            'customer_name' => 'Test User',
            'phone' => '+380991234567',
            'email' => 'buyer@example.com',
            'city' => 'Київ',
        ]);

        $payload = GoogleCustomerReviews::optInPayload($order);

        $this->assertNotNull($payload);
        $this->assertSame(123456789, $payload['merchant_id']);
        $this->assertSame((string) $order->number, $payload['order_id']);
        $this->assertSame('buyer@example.com', $payload['email']);
        $this->assertSame('UA', $payload['delivery_country']);
        $this->assertSame('2026-07-27', $payload['estimated_delivery_date']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $payload['estimated_delivery_date']);

        Carbon::setTestNow();
    }

    public function test_opt_in_payload_is_null_without_valid_email(): void
    {
        $order = Order::create([
            'total' => 500,
            'status' => 'new',
            'payment_method' => 'cash_on_delivery',
            'payment_status' => 'pending',
            'delivery_type' => 'nova_poshta_warehouse',
            'customer_name' => 'Quick Buyer',
            'phone' => '+380991111111',
            'email' => null,
        ]);

        $this->assertNull(GoogleCustomerReviews::optInPayload($order));

        $order->forceFill(['email' => 'not-an-email'])->save();
        $this->assertNull(GoogleCustomerReviews::optInPayload($order->fresh()));
    }

    public function test_delivery_days_config_controls_estimated_date(): void
    {
        config(['services.google_customer_reviews.delivery_days' => 5]);

        $order = Order::create([
            'total' => 100,
            'status' => 'new',
            'payment_method' => 'iban',
            'payment_status' => 'pending',
            'delivery_type' => 'nova_poshta_warehouse',
            'customer_name' => 'Test',
            'phone' => '+380992222222',
            'email' => 'ok@example.com',
        ]);

        $this->assertSame(
            $order->created_at->copy()->addDays(5)->format('Y-m-d'),
            GoogleCustomerReviews::estimatedDeliveryDate($order),
        );
    }
}
