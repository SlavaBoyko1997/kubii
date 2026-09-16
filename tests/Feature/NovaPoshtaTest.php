<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NovaPoshtaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.nova_poshta.api_key' => 'test-key',
            'services.nova_poshta.postomat_type_ref' => 'postomat-type-ref',
        ]);
        Cache::flush();
    }

    public function test_city_search_uses_one_cached_api_response(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => true,
                'data' => [
                    ['Ref' => 'kyivka-ref', 'Description' => 'Київка', 'DescriptionRu' => 'Киевка'],
                    ['Ref' => 'brovary-ref', 'Description' => 'Бровари', 'DescriptionRu' => 'Бровары', 'AreaDescription' => 'Київська'],
                    ['Ref' => 'kyiv-ref', 'Description' => 'Київ', 'DescriptionRu' => 'Киев'],
                    ['Ref' => 'lviv-ref', 'Description' => 'Львів', 'DescriptionRu' => 'Львов'],
                ],
            ]),
        ]);

        $this->getJson('/api/nova-poshta/cities?search=Ки')
            ->assertOk()
            ->assertJsonPath('data.0.Ref', 'kyiv-ref');

        $this->getJson('/api/nova-poshta/cities?search=Ль')
            ->assertOk()
            ->assertJsonPath('data.0.Ref', 'lviv-ref');

        Http::assertSentCount(1);
    }

    public function test_warehouse_endpoints_filter_regular_branches_and_postomats(): void
    {
        Http::fake(function (Request $request) {
            $properties = (array) ($request->data()['methodProperties'] ?? []);
            $postomatOnly = isset($properties['TypeOfWarehouseRef']);

            return Http::response([
                'success' => true,
                'data' => $postomatOnly ? [$this->postomat()] : [$this->warehouse(), $this->postomat()],
            ]);
        });

        $this->getJson('/api/nova-poshta/warehouses?city_ref=kyiv-ref')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.Ref', 'warehouse-ref');

        $this->getJson('/api/nova-poshta/postomats?city_ref=kyiv-ref')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.Ref', 'postomat-ref');

        Http::assertSent(fn (Request $request): bool => (
            ((array) ($request->data()['methodProperties'] ?? []))['TypeOfWarehouseRef'] ?? null
        ) === 'postomat-type-ref');
    }

    public function test_delivery_price_endpoint_returns_estimate_for_cart(): void
    {
        config(['services.nova_poshta.sender_city_ref' => 'sender-ref']);

        $category = Category::create(['name' => 'Рюкзаки', 'slug' => 'delivery-price-backpacks']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Рюкзак для розрахунку доставки',
            'slug' => 'delivery-price-backpack',
            'price' => 2000,
            'stock' => 3,
            'weight_grams' => 1500,
        ]);

        $this->post(route('cart.store', $product));

        Http::fake(function (Request $request) {
            if (($request->data()['calledMethod'] ?? null) === 'getDocumentPrice') {
                return Http::response([
                    'success' => true,
                    'data' => [['Cost' => '95.00']],
                ]);
            }

            return Http::response(['success' => true, 'data' => []]);
        });

        $this->getJson('/api/nova-poshta/delivery-price?city_ref=lviv-ref')
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.cost', 95)
            ->assertJsonPath('data.formatted', '~ 95 ₴')
            ->assertJsonPath('data.note', 'орієнтовно');

        Http::assertSent(fn (Request $request): bool => (
            ($request->data()['calledMethod'] ?? null) === 'getDocumentPrice'
            && ((array) ($request->data()['methodProperties'] ?? []))['CityRecipient'] === 'lviv-ref'
            && ((array) ($request->data()['methodProperties'] ?? []))['CitySender'] === 'sender-ref'
        ));
    }

    public function test_delivery_price_uses_delivery_type_and_warehouse_ref(): void
    {
        config(['services.nova_poshta.sender_city_ref' => 'sender-ref']);

        $category = Category::create(['name' => 'Спінінги', 'slug' => 'delivery-price-rods']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Спінінг для доставки',
            'slug' => 'delivery-price-rod',
            'price' => 1500,
            'stock' => 2,
        ]);

        $this->post(route('cart.store', $product));

        Http::fake(function (Request $request) {
            $properties = (array) ($request->data()['methodProperties'] ?? []);
            $method = $request->data()['calledMethod'] ?? null;

            if ($method === 'getWarehouses' && ($properties['Ref'] ?? null) === 'postomat-ref') {
                return Http::response([
                    'success' => true,
                    'data' => [[
                        'Ref' => 'postomat-ref',
                        'CityRef' => 'lviv-ref',
                        'TypeOfWarehouseRef' => 'postomat-type-ref',
                        'CategoryOfWarehouse' => 'Postomat',
                        'Description' => 'Поштомат №1001',
                        'ShortAddress' => 'вул. Test',
                        'Number' => '1001',
                    ]],
                ]);
            }

            if ($method !== 'getDocumentPrice') {
                return Http::response(['success' => true, 'data' => []]);
            }

            $cost = isset($properties['WarehouseRecipient']) ? '110.00' : '95.00';

            return Http::response([
                'success' => true,
                'data' => [['Cost' => $cost]],
            ]);
        });

        $this->getJson('/api/nova-poshta/delivery-price?city_ref=lviv-ref&delivery_type=nova_poshta_warehouse')
            ->assertOk()
            ->assertJsonPath('data.cost', 95);

        $this->getJson('/api/nova-poshta/delivery-price?city_ref=lviv-ref&delivery_type=nova_poshta_postomat&warehouse_ref=postomat-ref')
            ->assertOk()
            ->assertJsonPath('data.cost', 110);

        Http::assertSent(fn (Request $request): bool => (
            ($request->data()['calledMethod'] ?? null) === 'getDocumentPrice'
            && (((array) ($request->data()['methodProperties'] ?? []))['WarehouseRecipient'] ?? null) === 'postomat-ref'
        ));
    }

    public function test_delivery_price_is_free_when_cart_meets_threshold(): void
    {
        config([
            'services.nova_poshta.sender_city_ref' => 'sender-ref',
            'services.free_delivery.threshold' => 3000,
        ]);

        $category = Category::create(['name' => 'Катушки', 'slug' => 'free-delivery-reels']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Катушка для безкоштовної доставки',
            'slug' => 'free-delivery-reel',
            'price' => 3200,
            'stock' => 2,
        ]);

        $this->post(route('cart.store', $product));

        Http::fake(['*' => Http::response(['success' => true, 'data' => [['Cost' => '99.00']]])]);

        $this->getJson('/api/nova-poshta/delivery-price?city_ref=lviv-ref')
            ->assertOk()
            ->assertJsonPath('data.free', true)
            ->assertJsonPath('data.cost', 0)
            ->assertJsonPath('data.formatted', 'Безкоштовно');

        Http::assertNothingSent();
    }

    public function test_endpoint_returns_service_unavailable_when_nova_poshta_fails(): void
    {
        Http::fake(['*' => Http::response([], 503)]);

        $this->getJson('/api/nova-poshta/cities?search=Київ')
            ->assertStatus(503)
            ->assertJsonStructure(['message']);
    }

    public function test_large_cart_returns_only_cargo_warehouses_and_no_postomats(): void
    {
        $category = Category::create(['name' => 'Меблі', 'slug' => 'cargo-api']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Велике крісло',
            'slug' => 'cargo-chair',
            'price' => 5000,
            'stock' => 1,
            'specifications' => [
                'Вага в упаковці, кг' => '25',
                'Довжина в упаковці, м' => '1,40',
                'Ширина в упаковці, м' => '0,80',
                'Висота в упаковці, м' => '0,60',
            ],
        ]);
        $this->post(route('cart.store', $product));

        Http::fake(function (Request $request) {
            $properties = (array) ($request->data()['methodProperties'] ?? []);

            if (isset($properties['TypeOfWarehouseRef'])) {
                return Http::response(['success' => true, 'data' => [$this->postomat()]]);
            }

            return Http::response([
                'success' => true,
                'data' => [
                    [
                        ...$this->warehouse(),
                        'Description' => 'Відділення №3 (до 30 кг)',
                        'PlaceMaxWeightAllowed' => '30',
                        'ReceivingLimitationsOnDimensions' => ['Width' => 70, 'Height' => 70, 'Length' => 120],
                    ],
                    [
                        ...$this->warehouse(),
                        'Ref' => 'cargo-warehouse-ref',
                        'Description' => 'Вантажне відділення №1',
                        'PlaceMaxWeightAllowed' => '1100',
                        'ReceivingLimitationsOnDimensions' => ['Width' => 220, 'Height' => 220, 'Length' => 600],
                    ],
                ],
            ]);
        });

        $this->getJson('/api/nova-poshta/warehouses?city_ref=kyiv-ref')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.Ref', 'cargo-warehouse-ref');

        $this->getJson('/api/nova-poshta/postomats?city_ref=kyiv-ref')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_medium_cart_keeps_regular_warehouses_that_fit_dimensions(): void
    {
        $category = Category::create(['name' => 'Намети', 'slug' => 'medium-api']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Намет великий',
            'slug' => 'medium-tent-api',
            'price' => 4200,
            'stock' => 1,
            'specifications' => [
                'Вага в упаковці, кг' => '5,000',
                'Довжина в упаковці, м' => '0,800',
                'Ширина в упаковці, м' => '0,800',
                'Висота в упаковці, м' => '0,250',
            ],
        ]);
        $this->post(route('cart.store', $product));

        Http::fake(function (Request $request) {
            return Http::response([
                'success' => true,
                'data' => [
                    [
                        ...$this->warehouse(),
                        'Description' => 'Відділення №3 (до 30 кг)',
                        'PlaceMaxWeightAllowed' => '30',
                        'ReceivingLimitationsOnDimensions' => ['Width' => 70, 'Height' => 70, 'Length' => 120],
                    ],
                    [
                        ...$this->warehouse(),
                        'Ref' => 'wide-warehouse-ref',
                        'Description' => 'Відділення №9',
                        'PlaceMaxWeightAllowed' => '30',
                        'ReceivingLimitationsOnDimensions' => ['Width' => 90, 'Height' => 90, 'Length' => 120],
                    ],
                ],
            ]);
        });

        $this->getJson('/api/nova-poshta/warehouses?city_ref=kyiv-ref')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.Ref', 'warehouse-ref')
            ->assertJsonPath('data.1.Ref', 'wide-warehouse-ref');
    }

    private function warehouse(): array
    {
        return [
            'Ref' => 'warehouse-ref',
            'Description' => 'Відділення №1',
            'ShortAddress' => 'вул. Пирогівський шлях, 135',
            'Number' => '1',
            'CityRef' => 'kyiv-ref',
            'TypeOfWarehouseRef' => 'warehouse-type-ref',
            'CategoryOfWarehouse' => 'Branch',
        ];
    }

    private function postomat(): array
    {
        return [
            'Ref' => 'postomat-ref',
            'Description' => 'Поштомат №1001',
            'ShortAddress' => 'вул. Хрещатик, 10',
            'Number' => '1001',
            'CityRef' => 'kyiv-ref',
            'TypeOfWarehouseRef' => 'postomat-type-ref',
            'CategoryOfWarehouse' => 'Postomat',
        ];
    }
}
