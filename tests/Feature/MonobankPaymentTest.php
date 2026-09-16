<?php

namespace Tests\Feature;

use App\Exceptions\MonobankException;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\MonobankService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonobankPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.monobank.token' => 'mono-test-token',
            'services.monobank.api_url' => 'https://api.monobank.ua',
            'services.monobank.verify_webhook' => false,
            'services.monobank.redirect_url' => null,
            'services.monobank.webhook_url' => null,
        ]);
    }

    public function test_service_creates_hold_invoice_and_stores_invoice_id(): void
    {
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'p2_test_invoice',
                'pageUrl' => 'https://pay.monobank.ua/test',
            ]),
        ]);

        $order = $this->monoOrder();
        $payment = app(MonobankService::class)->createHoldInvoice($order->fresh());

        $this->assertSame('p2_test_invoice', $payment['invoice_id']);
        $this->assertSame('https://pay.monobank.ua/test', $payment['page_url']);
        $this->assertSame('p2_test_invoice', $order->fresh()->mono_invoice_id);

        Http::assertSent(function ($request) use ($order): bool {
            $payload = $request->data();

            return str_contains($request->url(), '/api/merchant/invoice/create')
                && ($payload['amount'] ?? null) === 125050
                && ($payload['paymentType'] ?? null) === 'hold'
                && ($payload['merchantPaymInfo']['reference'] ?? null) === $order->number;
        });
    }

    public function test_checkout_creates_pending_hold_order_and_redirects_to_monobank(): void
    {
        config([
            'services.nova_poshta.api_key' => 'nova-test-key',
            'services.nova_poshta.postomat_type_ref' => 'postomat-type-ref',
        ]);

        Http::fake(function ($request) {
            if (($request->data()['calledMethod'] ?? null) === 'getCities') {
                return Http::response([
                    'success' => true,
                    'data' => [[
                        'Ref' => 'city-ref',
                        'Description' => 'Київ',
                        'DescriptionRu' => 'Киев',
                    ]],
                ]);
            }

            if (str_contains($request->url(), '/api/merchant/invoice/create')) {
                return Http::response([
                    'invoiceId' => 'p2_checkout_invoice',
                    'pageUrl' => 'https://pay.monobank.ua/checkout',
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

        $category = Category::create(['name' => 'Тести', 'slug' => 'mono-tests']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Тестовий товар',
            'slug' => 'mono-test-product',
            'price' => 1250.50,
            'stock' => 2,
        ]);
        $this->post(route('cart.store', $product), ['quantity' => 1]);

        $response = $this->post(route('checkout.store'), [
            'last_name' => 'Петренко',
            'first_name' => 'Іван',
            'patronymic' => 'Петрович',
            'phone' => '+380991234567',
            'email' => 'mono-checkout@example.com',
            'delivery_type' => 'nova_poshta_warehouse',
            'nova_poshta_city_ref' => 'city-ref',
            'nova_poshta_warehouse_ref' => 'warehouse-ref',
            'payment_method' => 'mono_checkout',
        ]);

        $order = Order::query()->firstOrFail();
        $response->assertRedirect(route('payment.mono.checkout', $order));
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('1250.50', $order->payment_amount);
        $this->assertSame('UAH', $order->payment_currency);
    }

    public function test_account_shows_online_payment_button_only_for_pending_hold(): void
    {
        $customer = User::factory()->create();
        $order = $this->monoOrder([
            'user_id' => $customer->id,
            'payment_status' => 'pending',
        ]);

        $this->actingAs($customer)
            ->get(route('account.index'))
            ->assertOk()
            ->assertSee('Оплатити онлайн')
            ->assertSee(route('payment.mono.checkout', $order, false), false);

        $order->update([
            'payment_status' => 'holded',
            'mono_invoice_id' => 'p2_test_invoice',
            'mono_hold_at' => now(),
        ]);

        $this->actingAs($customer)
            ->get(route('account.index'))
            ->assertOk()
            ->assertDontSee('Оплатити онлайн')
            ->assertSee('Кошти вже заблоковано');
    }

    public function test_holded_webhook_is_idempotent(): void
    {
        $order = $this->monoOrder([
            'mono_invoice_id' => 'p2_test_invoice',
        ]);
        $payload = $this->webhookPayload($order, 'success', '2026-06-25T10:00:00Z');

        $this->postWebhook($payload)->assertOk()->assertSee('OK');
        $firstHoldAt = $order->fresh()->mono_hold_at;

        $this->postWebhook($payload)->assertOk();

        $order->refresh();
        $this->assertSame('holded', $order->payment_status);
        $this->assertSame('p2_test_invoice', $order->mono_invoice_id);
        $this->assertTrue($order->mono_hold_at->equalTo($firstHoldAt));
    }

    public function test_webhook_rejects_invalid_amount(): void
    {
        $order = $this->monoOrder([
            'mono_invoice_id' => 'p2_test_invoice',
        ]);

        $this->postWebhook([...$this->webhookPayload($order, 'success'), 'amount' => 1])
            ->assertStatus(422);

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_manager_can_finalize_hold_only_once(): void
    {
        $order = $this->monoOrder([
            'payment_status' => 'holded',
            'mono_invoice_id' => 'p2_test_invoice',
            'mono_hold_at' => now(),
        ]);

        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/finalize' => Http::response([
                'status' => 'success',
            ]),
        ]);

        $finalized = app(MonobankService::class)->finalizeHold($order);

        $this->assertSame('paid', $finalized->payment_status);
        $this->assertNotNull($finalized->mono_paid_at);
        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.monobank.ua/api/merchant/invoice/finalize'
                && $payload['invoiceId'] === 'p2_test_invoice'
                && $payload['amount'] === 125050;
        });

        $this->expectException(MonobankException::class);
        app(MonobankService::class)->finalizeHold($finalized);
    }

    public function test_manager_can_cancel_hold_and_cannot_cancel_paid_payment(): void
    {
        $order = $this->monoOrder([
            'payment_status' => 'holded',
            'mono_invoice_id' => 'p2_test_invoice',
            'mono_hold_at' => now(),
        ]);

        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/cancel' => Http::response([
                'status' => 'processing',
            ]),
        ]);

        $cancelled = app(MonobankService::class)->cancelHold($order);

        $this->assertSame('reversed', $cancelled->payment_status);
        $this->assertNotNull($cancelled->mono_cancelled_at);

        $paid = $this->monoOrder([
            'payment_status' => 'paid',
            'mono_invoice_id' => 'p2_paid_invoice',
            'mono_paid_at' => now(),
        ]);

        $this->expectException(MonobankException::class);
        app(MonobankService::class)->cancelHold($paid);
    }

    private function monoOrder(array $attributes = []): Order
    {
        return Order::create([
            'customer_name' => 'Іван Петренко',
            'phone' => '+380991234567',
            'email' => 'customer@example.com',
            'city' => 'Київ',
            'delivery_address' => 'Відділення №1',
            'delivery_type' => 'nova_poshta_warehouse',
            'payment_method' => 'mono_checkout',
            'payment_status' => 'pending',
            'payment_amount' => 1250.50,
            'payment_currency' => 'UAH',
            'total' => 1250.50,
            ...$attributes,
        ]);
    }

    private function webhookPayload(Order $order, string $status, ?string $modifiedDate = null): array
    {
        return [
            'invoiceId' => $order->mono_invoice_id ?? 'p2_test_invoice',
            'status' => $status,
            'amount' => 125050,
            'ccy' => 980,
            'reference' => $order->number,
            'modifiedDate' => $modifiedDate ?? '2026-06-25T10:00:00Z',
        ];
    }

    private function postWebhook(array $payload)
    {
        return $this->postJson(route('payment.mono.webhook'), $payload);
    }
}
