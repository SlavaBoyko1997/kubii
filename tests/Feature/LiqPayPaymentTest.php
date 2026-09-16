<?php

namespace Tests\Feature;

use App\Exceptions\LiqPayException;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\LiqPayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LiqPayPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.liqpay.public_key' => 'public-test-key',
            'services.liqpay.private_key' => 'private-test-key',
            'services.liqpay.sandbox' => true,
            'services.liqpay.result_url' => null,
            'services.liqpay.server_url' => null,
        ]);
    }

    public function test_service_creates_hold_payment_with_signed_data(): void
    {
        $order = $this->liqPayOrder();
        $payment = app(LiqPayService::class)->createHoldPayment($order);
        $payload = app(LiqPayService::class)->decodeData($payment['data']);

        $this->assertSame('hold', $payload['action']);
        $this->assertSame('UAH', $payload['currency']);
        $this->assertSame('1250.50', $payload['amount']);
        $this->assertSame($order->number, $payload['order_id']);
        $this->assertSame(1, $payload['sandbox']);
        $this->assertArrayNotHasKey('result_url', $payload);
        $this->assertArrayNotHasKey('server_url', $payload);
        $this->assertTrue(app(LiqPayService::class)->verifySignature($payment['data'], $payment['signature']));
        $this->assertStringNotContainsString('private-test-key', $payment['data']);
        $this->assertStringStartsWith('https://www.liqpay.ua/api/3/checkout?', $payment['checkout_url']);
        $this->assertStringContainsString('data=', $payment['checkout_url']);
        $this->assertStringContainsString('signature=', $payment['checkout_url']);
    }

    public function test_checkout_creates_pending_hold_order_and_redirects_to_liqpay(): void
    {
        config([
            'services.nova_poshta.api_key' => 'nova-test-key',
            'services.nova_poshta.postomat_type_ref' => 'postomat-type-ref',
        ]);
        Http::fake(function ($request) {
            $payload = $request->data();

            if (($payload['calledMethod'] ?? null) === 'getCities') {
                return Http::response([
                    'success' => true,
                    'data' => [[
                        'Ref' => 'city-ref',
                        'Description' => 'Київ',
                        'DescriptionRu' => 'Киев',
                    ]],
                ]);
            }

            return Http::response([
                'success' => true,
                'data' => [[
                    'Ref' => 'warehouse-ref',
                    'Description' => 'Відділення №1',
                    'ShortAddress' => 'вул. Хрещатик, 1',
                    'Number' => '1',
                    'CityRef' => 'city-ref',
                    'TypeOfWarehouseRef' => 'branch-type-ref',
                    'CategoryOfWarehouse' => 'Branch',
                ]],
            ]);
        });

        $category = Category::create(['name' => 'Тести', 'slug' => 'liqpay-tests']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Тестовий товар',
            'slug' => 'liqpay-test-product',
            'price' => 1250.50,
            'stock' => 2,
        ]);
        $this->post(route('cart.store', $product), ['quantity' => 1]);

        $response = $this->post(route('checkout.store'), [
            'last_name' => 'Петренко',
            'first_name' => 'Іван',
            'patronymic' => 'Петрович',
            'phone' => '+380991234567',
            'email' => 'liqpay-checkout@example.com',
            'delivery_type' => 'nova_poshta_warehouse',
            'nova_poshta_city_ref' => 'city-ref',
            'nova_poshta_warehouse_ref' => 'warehouse-ref',
            'payment_method' => 'liqpay_hold',
        ]);

        $order = Order::query()->firstOrFail();
        $response->assertRedirect(route('payment.liqpay.checkout', $order));
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('1250.50', $order->payment_amount);
        $this->assertSame('UAH', $order->payment_currency);
        $this->assertSame($order->id, session('placed_order_id'));
    }

    public function test_account_shows_online_payment_button_only_for_pending_hold(): void
    {
        $customer = User::factory()->create();
        $order = $this->liqPayOrder([
            'user_id' => $customer->id,
            'payment_status' => 'pending',
        ]);

        $this->actingAs($customer)
            ->get(route('account.index'))
            ->assertOk()
            ->assertSee('Оплатити онлайн')
            ->assertSee(route('payment.liqpay.checkout', $order, false), false);

        $order->update([
            'payment_status' => 'holded',
            'liqpay_payment_id' => 'payment-123',
            'liqpay_hold_at' => now(),
        ]);

        $this->actingAs($customer)
            ->get(route('account.index'))
            ->assertOk()
            ->assertDontSee('Оплатити онлайн')
            ->assertSee('Кошти вже заблоковано');
    }

    public function test_holded_callback_is_verified_and_idempotent(): void
    {
        $order = $this->liqPayOrder();
        $payload = $this->callbackPayload($order, 'holded');

        $this->postCallback($payload)->assertOk()->assertSee('OK');
        $firstHoldAt = $order->fresh()->liqpay_hold_at;

        $this->postCallback($payload)->assertOk();

        $order->refresh();
        $this->assertSame('holded', $order->payment_status);
        $this->assertSame('payment-123', $order->liqpay_payment_id);
        $this->assertSame('transaction-123', $order->liqpay_transaction_id);
        $this->assertTrue($order->liqpay_hold_at->equalTo($firstHoldAt));
    }

    public function test_callback_rejects_invalid_signature_amount_and_currency(): void
    {
        $order = $this->liqPayOrder();
        $payload = $this->callbackPayload($order, 'holded');
        $data = app(LiqPayService::class)->encodeData($payload);

        $this->post(route('payment.liqpay.callback'), [
            'data' => $data,
            'signature' => 'invalid',
        ])->assertStatus(400);

        $this->postCallback([...$payload, 'amount' => 1])->assertStatus(422);
        $this->postCallback([...$payload, 'currency' => 'USD'])->assertStatus(422);

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_manager_can_capture_hold_only_once(): void
    {
        $order = $this->liqPayOrder([
            'payment_status' => 'holded',
            'liqpay_payment_id' => 'payment-123',
            'liqpay_hold_at' => now(),
        ]);

        Http::fake([
            'https://www.liqpay.ua/api/request' => Http::response([
                'status' => 'success',
                'payment_id' => 'payment-123',
                'transaction_id' => 'capture-123',
            ]),
        ]);

        $captured = app(LiqPayService::class)->captureHold($order);

        $this->assertSame('paid', $captured->payment_status);
        $this->assertNotNull($captured->liqpay_paid_at);
        Http::assertSent(function ($request): bool {
            $payload = app(LiqPayService::class)->decodeData($request['data']);

            return $payload['action'] === 'hold_completion' && $payload['amount'] === '1250.50';
        });

        $this->expectException(LiqPayException::class);
        app(LiqPayService::class)->captureHold($captured);
    }

    public function test_manager_can_cancel_hold_and_cannot_cancel_paid_payment(): void
    {
        $order = $this->liqPayOrder([
            'payment_status' => 'holded',
            'liqpay_payment_id' => 'payment-123',
            'liqpay_hold_at' => now(),
        ]);

        Http::fake([
            'https://www.liqpay.ua/api/request' => Http::response([
                'status' => 'reversed',
                'payment_id' => 'payment-123',
            ]),
        ]);

        $cancelled = app(LiqPayService::class)->cancelHold($order);

        $this->assertSame('reversed', $cancelled->payment_status);
        $this->assertNotNull($cancelled->liqpay_cancelled_at);
        Http::assertSent(function ($request): bool {
            $payload = app(LiqPayService::class)->decodeData($request['data']);

            return $payload['action'] === 'hold_completion' && $payload['amount'] === '0.00';
        });

        $paid = $this->liqPayOrder([
            'payment_status' => 'paid',
            'liqpay_payment_id' => 'payment-paid',
            'liqpay_paid_at' => now(),
        ]);

        $this->expectException(LiqPayException::class);
        app(LiqPayService::class)->cancelHold($paid);
    }

    private function liqPayOrder(array $attributes = []): Order
    {
        return Order::create([
            'customer_name' => 'Іван Петренко',
            'phone' => '+380991234567',
            'email' => 'customer@example.com',
            'city' => 'Київ',
            'delivery_address' => 'Відділення №1',
            'delivery_type' => 'nova_poshta_warehouse',
            'payment_method' => 'liqpay_hold',
            'payment_status' => 'pending',
            'payment_amount' => 1250.50,
            'payment_currency' => 'UAH',
            'total' => 1250.50,
            ...$attributes,
        ]);
    }

    private function callbackPayload(Order $order, string $status): array
    {
        return [
            'version' => 3,
            'action' => 'hold',
            'status' => $status,
            'order_id' => $order->number,
            'amount' => 1250.50,
            'currency' => 'UAH',
            'payment_id' => 'payment-123',
            'transaction_id' => 'transaction-123',
        ];
    }

    private function postCallback(array $payload)
    {
        $service = app(LiqPayService::class);
        $data = $service->encodeData($payload);

        return $this->post(route('payment.liqpay.callback'), [
            'data' => $data,
            'signature' => $service->generateSignature($data),
        ]);
    }
}
