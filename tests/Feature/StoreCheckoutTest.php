<?php

namespace Tests\Feature;

use App\Exceptions\NovaPoshtaException;
use App\Models\Category;
use App\Models\Order;
use App\Models\PaymentOption;
use App\Models\Product;
use App\Models\User;
use App\Support\CatalogCache;
use App\Services\CatalogSpecificationFacets;
use App\Services\IbisFeedImporter;
use App\Services\NovaPoshtaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StoreCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const CITY_REF = '8d5a980d-391c-11dd-90d9-001a92567626';

    private const WAREHOUSE_REF = 'warehouse-ref-1';

    private const POSTOMAT_REF = 'postomat-ref-1';

    private const STREET_REF = 'street-ref-1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.nova_poshta.api_key' => 'test-key',
            'services.nova_poshta.postomat_type_ref' => 'postomat-type-ref',
        ]);

        Http::fake(function ($request) {
            $payload = $request->data();
            $properties = (array) ($payload['methodProperties'] ?? []);

            if (($payload['calledMethod'] ?? null) === 'getCities') {
                return Http::response([
                    'success' => true,
                    'data' => [[
                        'Ref' => self::CITY_REF,
                        'Description' => 'Київ',
                        'DescriptionRu' => 'Киев',
                        'AreaDescription' => 'Київська',
                        'RegionsDescription' => '',
                    ]],
                ]);
            }

            if (($payload['calledMethod'] ?? null) === 'getStreet') {
                if (($properties['Ref'] ?? null) === self::STREET_REF) {
                    return Http::response([
                        'success' => true,
                        'data' => [[
                            'Ref' => self::STREET_REF,
                            'Description' => 'Хрещатик',
                            'StreetsType' => 'вул.',
                        ]],
                    ]);
                }

                $findByString = mb_strtolower(trim((string) ($properties['FindByString'] ?? '')));

                if ($findByString !== 'хрещатик') {
                    return Http::response([
                        'success' => true,
                        'data' => [],
                    ]);
                }

                return Http::response([
                    'success' => true,
                    'data' => [[
                        'Ref' => self::STREET_REF,
                        'Description' => 'Хрещатик',
                        'StreetsType' => 'вул.',
                    ]],
                ]);
            }

            $postomat = ($properties['Ref'] ?? null) === self::POSTOMAT_REF
                || isset($properties['TypeOfWarehouseRef']);

            return Http::response([
                'success' => true,
                'data' => [[
                    'Ref' => $postomat ? self::POSTOMAT_REF : self::WAREHOUSE_REF,
                    'Description' => $postomat ? 'Поштомат №1001' : 'Відділення №1',
                    'ShortAddress' => $postomat ? 'вул. Хрещатик, 10' : 'вул. Пирогівський шлях, 135',
                    'Number' => $postomat ? '1001' : '1',
                    'CityRef' => self::CITY_REF,
                    'TypeOfWarehouseRef' => $postomat ? 'postomat-type-ref' : 'warehouse-type-ref',
                    'CategoryOfWarehouse' => $postomat ? 'Postomat' : 'Branch',
                    'TotalMaxWeightAllowed' => $postomat ? '20' : '0',
                    'PlaceMaxWeightAllowed' => $postomat ? '0' : '1100',
                    'ReceivingLimitationsOnDimensions' => $postomat
                        ? ['Width' => 40, 'Height' => 30, 'Length' => 60]
                        : ['Width' => 220, 'Height' => 220, 'Length' => 600],
                ]],
            ]);
        });
    }

    public function test_customer_can_browse_category_add_product_and_place_order(): void
    {
        $category = Category::create([
            'name' => 'Намети',
            'slug' => 'tents',
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Намет туристичний',
            'slug' => 'tourist-tent',
            'price' => 4200,
            'stock' => 5,
            'is_featured' => true,
        ]);

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertSee('Намет туристичний');

        $this->post(route('cart.store', $product), ['quantity' => 2])
            ->assertSessionHas('cart', [$product->id => 2]);

        $this->get(route('cart.index'))
            ->assertOk()
            ->assertSee('8 400');

        $response = $this->post(route('checkout.store'), [
            'last_name' => 'Петренко',
            'first_name' => 'Іван',
            'patronymic' => 'Петрович',
            'phone' => '+380991234567',
            'email' => 'ivan@example.com',
            ...$this->novaPoshtaDelivery(),
            'payment_method' => 'cash_on_delivery',
        ]);

        $order = Order::query()->firstOrFail();
        $response->assertRedirect(route('checkout.success', $order));
        $this->assertDatabaseHas('users', [
            'email' => 'ivan@example.com',
            'phone' => '+380991234567',
            'is_guest' => true,
        ]);
        $this->assertDatabaseHas('orders', [
            'customer_name' => 'Петренко Іван Петрович',
            'user_id' => User::query()->where('email', 'ivan@example.com')->value('id'),
            'total' => 8400,
            'delivery_type' => 'nova_poshta_warehouse',
            'nova_poshta_city_ref' => self::CITY_REF,
            'nova_poshta_city_name' => 'Київ',
            'nova_poshta_warehouse_ref' => self::WAREHOUSE_REF,
            'nova_poshta_warehouse_name' => 'Відділення №1',
            'nova_poshta_warehouse_number' => '1',
            'nova_poshta_warehouse_address' => 'вул. Пирогівський шлях, 135',
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'subtotal' => 8400,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 3,
        ]);
        $response->assertSessionMissing('cart');
    }

    public function test_success_continue_shopping_link_uses_current_host_instead_of_app_url(): void
    {
        config(['app.url' => 'http://localhost']);
        $category = Category::create([
            'name' => 'Намети',
            'slug' => 'success-current-host-tents',
        ]);
        $order = Order::create([
            'customer_name' => 'Іван Петренко',
            'phone' => '+380991234567',
            'city' => 'Київ',
            'delivery_address' => 'Відділення №1',
            'payment_method' => 'cash_on_delivery',
            'total' => 1000,
        ]);
        $order->items()->create([
            'product_name' => 'Намет тестовий',
            'price' => 1000,
            'quantity' => 1,
            'subtotal' => 1000,
        ]);

        $this->withSession(['placed_order_id' => $order->id])
            ->get('https://shop.example.test/checkout/success/'.$order->id)
            ->assertOk()
            ->assertSee('href="/'.$category->slug.'/"', false)
            ->assertDontSee('href="http://localhost', false)
            ->assertSee('Замовлення успішно оформлено')
            ->assertSee('Намет тестовий')
            ->assertSee('1 000 ₴')
            ->assertSee('Післяплата у Новій Пошті')
            ->assertSee('Продовжити покупки');
    }

    public function test_cached_catalog_product_and_cart_links_do_not_contain_localhost(): void
    {
        config(['app.url' => 'http://localhost']);
        $category = Category::create([
            'name' => 'Рибальство',
            'slug' => 'cached-link-fishing',
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Силікон Select Kraken',
            'slug' => 'cached-link-select-kraken',
            'price' => 250,
            'stock' => 3,
        ]);

        $response = $this->get('https://shop.example.test'.$category->catalogUrl(absolute: false))
            ->assertOk()
            ->assertDontSee('http://localhost', false)
            ->assertSee('href="'.$product->url(false).'"', false)
            ->assertSee('action="'.localized_route('cart.store', $product, false).'"', false);

        $this->get('https://another-host.example'.$category->catalogUrl(absolute: false))
            ->assertOk()
            ->assertDontSee('http://localhost', false)
            ->assertSee('href="'.$product->url(false).'"', false);
    }

    public function test_catalog_pagination_and_load_more_do_not_use_localhost_when_app_url_is_localhost(): void
    {
        config(['app.url' => 'http://localhost']);
        $parent = Category::create([
            'name' => 'Туризм',
            'slug' => 'pagination-host-tourism',
            'is_active' => true,
        ]);
        $category = Category::create([
            'name' => 'Намети',
            'slug' => 'pagination-host-tents',
            'parent_id' => $parent->id,
            'is_active' => true,
        ]);

        foreach (range(1, 22) as $index) {
            Product::create([
                'category_id' => $category->id,
                'name' => "Намет {$index}",
                'slug' => "pagination-tent-{$index}",
                'price' => 1000 + $index,
                'stock' => 2,
            ]);
        }

        $this->get('https://shop.example.test'.$parent->catalogUrl(absolute: false))
            ->assertOk()
            ->assertSee('Показати ще 20 товарів')
            ->assertSee('page=2', false)
            ->assertDontSee('http://localhost', false);
    }

    public function test_authenticated_checkout_uses_profile_email_and_phone_without_allowing_changes(): void
    {
        $customer = User::factory()->create([
            'email' => 'profile@example.com',
            'phone' => '+380991111111',
            'patronymic' => 'Петрович',
        ]);
        $category = Category::create(['name' => 'Термоси', 'slug' => 'thermoses']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Термос сталевий',
            'slug' => 'steel-thermos',
            'price' => 1000,
            'stock' => 2,
        ]);

        $this->actingAs($customer)->post(route('cart.store', $product));

        $this->actingAs($customer)->get(route('checkout.create'))
            ->assertOk()
            ->assertSee('readonly', false)
            ->assertSee('+380991111111')
            ->assertSee('profile@example.com');

        $this->actingAs($customer)->post(route('checkout.store'), [
            'customer_name' => 'Іван Петренко',
            'phone' => '+380000000000',
            'email' => 'changed@example.com',
            ...$this->novaPoshtaDelivery(),
            'payment_method' => 'cash_on_delivery',
        ])->assertRedirect();

        $this->assertDatabaseHas('orders', [
            'phone' => '+380991111111',
            'email' => 'profile@example.com',
        ]);
    }

    public function test_authenticated_checkout_prefills_last_delivery_and_payment_method(): void
    {
        $customer = User::factory()->create(['phone' => '+380991111111']);
        $category = Category::create(['name' => 'Рюкзаки', 'slug' => 'checkout-prefill-backpacks']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Рюкзак Test',
            'slug' => 'checkout-prefill-backpack',
            'price' => 1500,
            'stock' => 2,
        ]);
        Order::create([
            'order_type' => 'standard',
            'user_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'city' => 'Київ',
            'delivery_address' => 'вул. Хрещатик, 10',
            'delivery_type' => 'nova_poshta_postomat',
            'nova_poshta_city_ref' => self::CITY_REF,
            'nova_poshta_city_name' => 'Київ',
            'nova_poshta_warehouse_ref' => self::POSTOMAT_REF,
            'nova_poshta_warehouse_name' => 'Поштомат №1001',
            'nova_poshta_warehouse_number' => '1001',
            'nova_poshta_warehouse_address' => 'вул. Хрещатик, 10',
            'payment_method' => 'iban',
            'total' => 1000,
        ]);

        $this->actingAs($customer)->post(route('cart.store', $product));

        $this->actingAs($customer)->get(route('checkout.create'))
            ->assertOk()
            ->assertSee('value="'.self::CITY_REF.'"', false)
            ->assertSee('value="'.self::POSTOMAT_REF.'"', false)
            ->assertSee('value="nova_poshta_postomat" checked', false)
            ->assertSee('value="iban" checked', false)
            ->assertSee('Поштомат №1001');
    }

    public function test_authenticated_checkout_can_fill_missing_name_parts_once(): void
    {
        $customer = User::create([
            'name' => 'Коваль',
            'last_name' => 'Коваль',
            'email' => 'checkout-name@example.com',
            'phone' => '+380991111112',
            'password' => 'password123',
        ]);
        $category = Category::create(['name' => 'Пальники', 'slug' => 'burners']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Пальник туристичний',
            'slug' => 'tourist-burner',
            'price' => 1200,
            'stock' => 2,
        ]);

        $this->actingAs($customer)->post(route('cart.store', $product));

        $this->actingAs($customer)->get(route('checkout.create'))
            ->assertOk()
            ->assertSee('name="first_name"', false)
            ->assertSee('name="patronymic"', false);

        $this->actingAs($customer)->post(route('checkout.store'), [
            'first_name' => 'Олена',
            'patronymic' => 'Петрівна',
            'phone' => '+380000000000',
            'email' => 'changed@example.com',
            ...$this->novaPoshtaDelivery(),
            'payment_method' => 'cash_on_delivery',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $customer->id,
            'first_name' => 'Олена',
            'patronymic' => 'Петрівна',
            'name' => 'Коваль Олена Петрівна',
        ]);
        $this->assertDatabaseHas('orders', [
            'user_id' => $customer->id,
            'customer_name' => 'Коваль Олена Петрівна',
            'phone' => '+380991111112',
            'email' => 'checkout-name@example.com',
        ]);
    }

    public function test_checkout_requires_every_customer_contact_field(): void
    {
        $category = Category::create(['name' => 'Рюкзаки', 'slug' => 'required-contact-backpacks']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Рюкзак для перевірки контактів',
            'slug' => 'required-contact-backpack',
            'price' => 1800,
            'stock' => 2,
        ]);

        $this->post(route('cart.store', $product));

        $this->post(route('checkout.store'), [
            'last_name' => 'Петренко',
            'first_name' => 'Іван',
            'phone' => '+380991234567',
            ...$this->novaPoshtaDelivery(),
            'payment_method' => 'cash_on_delivery',
        ])->assertSessionHasErrors(['patronymic', 'email']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_detects_existing_customer_and_prepares_login_return(): void
    {
        $customer = User::create([
            'name' => 'Існуючий клієнт',
            'email' => 'existing-checkout@example.com',
            'phone' => '+380991234500',
            'password' => 'password123',
        ]);

        $this->postJson(route('checkout.account-check'), [
            'email' => mb_strtoupper($customer->email),
            'phone' => '+380 99 123 45 00',
        ])
            ->assertOk()
            ->assertJson([
                'exists' => true,
                'email_exists' => true,
                'phone_exists' => true,
                'login_email' => $customer->email,
            ])
            ->assertSessionHas('url.intended', route('checkout.create'));

        $this->postJson(route('login.store'), [
            'email' => $customer->email,
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('redirect', route('checkout.create'));

        $this->assertAuthenticatedAs($customer);
    }

    public function test_guest_checkout_with_existing_contact_prompts_login_instead_of_creating_order(): void
    {
        User::create([
            'name' => 'Існуючий клієнт',
            'email' => 'owned-contact@example.com',
            'phone' => '+380991234501',
            'password' => 'password123',
        ]);
        $category = Category::create(['name' => 'Посуд', 'slug' => 'existing-contact-cookware']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Горнятко туристичне',
            'slug' => 'existing-contact-mug',
            'price' => 500,
            'stock' => 3,
        ]);

        $this->post(route('cart.store', $product));

        $this->post(route('checkout.store'), [
            'last_name' => 'Петренко',
            'first_name' => 'Іван',
            'patronymic' => 'Петрович',
            'phone' => '+380 99 123 45 01',
            'email' => 'new-email@example.com',
            ...$this->novaPoshtaDelivery(),
            'payment_method' => 'cash_on_delivery',
        ])
            ->assertRedirect()
            ->assertSessionHas('checkout_existing_account', true)
            ->assertSessionHas('url.intended', route('checkout.create'))
            ->assertSessionHasInput('nova_poshta_city_ref', self::CITY_REF);

        $this->assertDatabaseCount('orders', 0);
        $this->get(route('checkout.create'))
            ->assertOk()
            ->assertSee('Схоже, у вас уже є акаунт');
    }

    public function test_checkout_displays_detailed_order_block_under_contact_details(): void
    {
        $category = Category::create(['name' => 'Котушки', 'slug' => 'reels']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Котушка акційна Shimano',
            'slug' => 'sale-shimano-reel',
            'sku' => 'SH-2000',
            'price' => 2000,
            'discount_percent' => 25,
            'image_url' => '/images/reel.jpg',
            'stock' => 4,
        ]);

        $this->post(route('cart.store', $product), ['quantity' => 2]);

        $this->get(route('checkout.create'))
            ->assertOk()
            ->assertSee('checkout-progress', false)
            ->assertSee('data-checkout-step-target="contacts"', false)
            ->assertDontSee('data-checkout-step-target="summary"', false)
            ->assertSeeInOrder(['Контакти клієнта', 'Ваше замовлення', 'Доставка', 'Оплата'])
            ->assertSee('Ваше замовлення')
            ->assertSee('Редагувати')
            ->assertSee('data-open-cart', false)
            ->assertSee('Котушка акційна Shimano')
            ->assertSee('Кількість: 2')
            ->assertSee('Код товару: SH-2000')
            ->assertSee('2 000 ₴')
            ->assertSee('1 500 ₴')
            ->assertSee('−25%', false)
            ->assertSee('3 000 ₴ за 2 шт.')
            ->assertSee('Ваша вигода')
            ->assertSee('1 000 ₴')
            ->assertDontSee('−1 000 ₴')
            ->assertSee('За тарифом перевізника')
            ->assertSee('Потрібно заповнити')
            ->assertSee('Доставка Новою Поштою')
            ->assertSee('nova-poshta-brand', false)
            ->assertSee('nova-poshta-type-icon', false)
            ->assertSee('Як бажаєте отримати замовлення?')
            ->assertSee('Самостійне отримання без черги')
            ->assertSee('data-nova-poshta-city-input', false)
            ->assertSee('data-nova-poshta-point-input', false)
            ->assertSee('Шукайте за номером відділення, вулицею або адресою')
            ->assertSee('data-checkout-nav-status', false)
            ->assertSee('Замовлення підтверджую')
            ->assertDontSee('Вага замовлення');
    }

    public function test_checkout_order_block_is_returned_when_cart_changes_via_ajax(): void
    {
        $category = Category::create(['name' => 'Намети', 'slug' => 'tents']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Намет для чекауту',
            'slug' => 'checkout-tent',
            'price' => 4200,
            'stock' => 2,
        ]);

        $this->post(route('cart.store', $product));

        $response = $this->deleteJson(route('cart.destroy', $product))
            ->assertOk()
            ->assertJsonStructure(['drawer', 'cart', 'checkoutOrder', 'checkoutSummary'])
            ->assertJsonPath('count', 0);

        $this->assertStringContainsString('У кошику немає товарів', $response->json('checkoutOrder'));
        $this->assertStringContainsString('disabled', $response->json('checkoutSummary'));
    }

    public function test_cart_can_be_updated_without_reloading_page(): void
    {
        $category = Category::create(['name' => 'Рюкзаки', 'slug' => 'backpacks']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Рюкзак туристичний',
            'slug' => 'tourist-backpack',
            'price' => 2500,
            'stock' => 3,
        ]);

        $this->postJson(route('cart.store', $product))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonFragment(['message' => 'Товар додано до кошика.'])
            ->assertJsonStructure(['drawer']);
    }

    public function test_quick_order_requires_valid_phone_and_reserves_product(): void
    {
        $category = Category::create(['name' => 'Вудилища', 'slug' => 'rods']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Фідерне вудилище',
            'slug' => 'feeder-rod',
            'price' => 1800,
            'stock' => 3,
        ]);

        $this->post(route('checkout.quick'), [
            'product_id' => $product->id,
            'phone' => 'not-a-phone',
        ])->assertSessionHasErrors('phone');

        $response = $this->postJson(route('checkout.quick'), [
            'product_id' => $product->id,
            'phone' => '+38 (099) 123-45-67',
        ])->assertOk();

        $order = Order::query()->firstOrFail();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $order->number);
        $response->assertJsonPath('redirect', route('checkout.success', $order));
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'order_type' => 'quick',
            'phone' => '+380991234567',
            'total' => 1800,
        ]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 2]);
    }

    public function test_cart_quantity_cannot_exceed_current_stock(): void
    {
        $category = Category::create(['name' => 'Ліхтарі', 'slug' => 'lights']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Ліхтар туристичний',
            'slug' => 'tourist-light',
            'price' => 900,
            'stock' => 2,
        ]);

        $this->post(route('cart.store', $product), ['quantity' => 3])
            ->assertSessionHasErrors('quantity');
    }

    public function test_customer_can_place_order_to_nova_poshta_postomat(): void
    {
        $category = Category::create(['name' => 'Ліхтарі', 'slug' => 'postomat-lights']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Ліхтар для поштомату',
            'slug' => 'postomat-light',
            'price' => 900,
            'stock' => 2,
        ]);

        $this->post(route('cart.store', $product));

        $this->post(route('checkout.store'), [
            'last_name' => 'Петренко',
            'first_name' => 'Іван',
            'patronymic' => 'Петрович',
            'phone' => '+380991234567',
            'email' => 'postomat@example.com',
            ...$this->novaPoshtaDelivery('nova_poshta_postomat', self::POSTOMAT_REF),
            'payment_method' => 'cash_on_delivery',
        ])->assertRedirect();

        $this->assertDatabaseHas('orders', [
            'delivery_type' => 'nova_poshta_postomat',
            'nova_poshta_city_ref' => self::CITY_REF,
            'nova_poshta_warehouse_ref' => self::POSTOMAT_REF,
            'nova_poshta_warehouse_name' => 'Поштомат №1001',
            'nova_poshta_warehouse_number' => '1001',
            'nova_poshta_warehouse_address' => 'вул. Хрещатик, 10',
        ]);
    }

    public function test_customer_can_place_order_with_nova_poshta_courier_delivery(): void
    {
        $category = Category::create(['name' => 'Спінінги', 'slug' => 'courier-rods']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Спінінг для кур\'єра',
            'slug' => 'courier-rod',
            'price' => 1200,
            'stock' => 2,
        ]);

        $this->post(route('cart.store', $product));

        $this->post(route('checkout.store'), [
            'last_name' => 'Петренко',
            'first_name' => 'Іван',
            'patronymic' => 'Петрович',
            'phone' => '+380991234567',
            'email' => 'courier@example.com',
            ...$this->novaPoshtaCourierDelivery(),
            'payment_method' => 'cash_on_delivery',
        ])->assertRedirect();

        $this->assertDatabaseHas('orders', [
            'delivery_type' => 'nova_poshta_courier',
            'nova_poshta_city_ref' => self::CITY_REF,
            'nova_poshta_city_name' => 'Київ',
            'nova_poshta_street_ref' => self::STREET_REF,
            'nova_poshta_street_name' => 'вул. Хрещатик',
            'nova_poshta_building' => '10',
            'nova_poshta_flat' => '12',
            'delivery_address' => 'вул. Хрещатик, буд. 10, кв. 12',
            'nova_poshta_warehouse_ref' => null,
        ]);
    }

    public function test_courier_checkout_accepts_street_label_without_description_field(): void
    {
        $category = Category::create(['name' => 'Воблери', 'slug' => 'courier-crankbaits']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Воблер для кур\'єра',
            'slug' => 'courier-crankbait',
            'price' => 350,
            'stock' => 2,
        ]);

        $this->post(route('cart.store', $product));

        $payload = $this->novaPoshtaCourierDelivery();
        unset($payload['nova_poshta_street_description']);

        $this->post(route('checkout.store'), [
            'last_name' => 'Петренко',
            'first_name' => 'Іван',
            'patronymic' => 'Петрович',
            'phone' => '+380991234567',
            'email' => 'courier-label@example.com',
            ...$payload,
            'payment_method' => 'cash_on_delivery',
        ])->assertRedirect();

        $this->assertDatabaseHas('orders', [
            'delivery_type' => 'nova_poshta_courier',
            'nova_poshta_street_ref' => self::STREET_REF,
            'nova_poshta_street_name' => 'вул. Хрещатик',
        ]);
    }

    public function test_large_product_disables_postomat_and_requires_cargo_warehouse(): void
    {
        $category = Category::create(['name' => 'Меблі', 'slug' => 'large-delivery']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Велике крісло',
            'slug' => 'large-chair',
            'price' => 5000,
            'stock' => 2,
            'specifications' => [
                'Вага в упаковці, кг' => '25',
                'Довжина в упаковці, м' => '1,40',
                'Ширина в упаковці, м' => '0,80',
                'Висота в упаковці, м' => '0,60',
            ],
        ]);

        $this->post(route('cart.store', $product));

        $this->get(route('checkout.create'))
            ->assertOk()
            ->assertSee('Потрібне вантажне відділення')
            ->assertSee('class="is-disabled"', false)
            ->assertSee('value="nova_poshta_postomat"', false)
            ->assertSee('data-cargo-only="1"', false);

        $this->post(route('checkout.store'), [
            'last_name' => 'Петренко',
            'first_name' => 'Іван',
            'patronymic' => 'Петрович',
            'phone' => '+380991234567',
            'email' => 'large@example.com',
            ...$this->novaPoshtaDelivery('nova_poshta_postomat', self::POSTOMAT_REF),
            'payment_method' => 'cash_on_delivery',
        ])->assertSessionHasErrors('delivery_type');
    }

    public function test_light_oversized_for_postomat_product_keeps_regular_warehouses(): void
    {
        $category = Category::create(['name' => 'Намети', 'slug' => 'tent-delivery-profile']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Намет великий',
            'slug' => 'large-tent-delivery',
            'price' => 4200,
            'stock' => 2,
            'specifications' => [
                'Вага в упаковці, кг' => '5,000',
                'Довжина в упаковці, м' => '0,800',
                'Ширина в упаковці, м' => '0,800',
                'Висота в упаковці, м' => '0,250',
            ],
        ]);

        $this->post(route('cart.store', $product));

        $this->get(route('checkout.create'))
            ->assertOk()
            ->assertSee('value="nova_poshta_postomat"', false)
            ->assertSee('class="is-disabled"', false)
            ->assertDontSee('Потрібне вантажне відділення')
            ->assertSee('data-cargo-only="0"', false);
    }

    public function test_disabled_payment_method_is_hidden_and_rejected(): void
    {
        PaymentOption::query()->where('code', 'iban')->firstOrFail()->update(['is_enabled' => false]);
        $category = Category::create(['name' => 'Оплата', 'slug' => 'payment-options']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Товар для оплати',
            'slug' => 'payment-option-product',
            'price' => 1000,
            'stock' => 2,
        ]);

        $this->post(route('cart.store', $product));

        $this->get(route('checkout.create'))
            ->assertOk()
            ->assertDontSee('value="iban"', false)
            ->assertSee('value="liqpay_hold"', false)
            ->assertSee('value="cash_on_delivery"', false);

        $this->post(route('checkout.store'), [
            'last_name' => 'Петренко',
            'first_name' => 'Іван',
            'patronymic' => 'Петрович',
            'phone' => '+380991234567',
            'email' => 'disabled-payment@example.com',
            ...$this->novaPoshtaDelivery(),
            'payment_method' => 'iban',
        ])->assertSessionHasErrors('payment_method');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_rejects_postomat_for_warehouse_delivery_type(): void
    {
        $category = Category::create(['name' => 'Сумки', 'slug' => 'delivery-validation-bags']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Сумка',
            'slug' => 'delivery-validation-bag',
            'price' => 700,
            'stock' => 1,
        ]);

        $this->post(route('cart.store', $product));

        $this->post(route('checkout.store'), [
            'last_name' => 'Петренко',
            'first_name' => 'Іван',
            'patronymic' => 'Петрович',
            'phone' => '+380991234567',
            'email' => 'wrong-point@example.com',
            ...$this->novaPoshtaDelivery('nova_poshta_warehouse', self::POSTOMAT_REF),
            'payment_method' => 'cash_on_delivery',
        ])->assertSessionHasErrors('nova_poshta_warehouse_ref');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_handles_unavailable_nova_poshta_api(): void
    {
        $novaPoshta = $this->mock(NovaPoshtaService::class);
        $novaPoshta->shouldReceive('getCityByRef')
            ->once()
            ->andThrow(new NovaPoshtaException('Service unavailable.'));
        $category = Category::create(['name' => 'Термоси', 'slug' => 'unavailable-api-thermoses']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Термос',
            'slug' => 'unavailable-api-thermos',
            'price' => 800,
            'stock' => 1,
        ]);

        $this->post(route('cart.store', $product));

        $this->post(route('checkout.store'), [
            'last_name' => 'Петренко',
            'first_name' => 'Іван',
            'patronymic' => 'Петрович',
            'phone' => '+380991234567',
            'email' => 'api-error@example.com',
            ...$this->novaPoshtaDelivery(),
            'payment_method' => 'cash_on_delivery',
        ])->assertSessionHasErrors('nova_poshta_city_ref');

        $this->assertDatabaseCount('orders', 0);
    }

    private function novaPoshtaDelivery(
        string $type = 'nova_poshta_warehouse',
        string $warehouseRef = self::WAREHOUSE_REF,
    ): array {
        return [
            'delivery_type' => $type,
            'nova_poshta_city_ref' => self::CITY_REF,
            'nova_poshta_city_name' => 'Київ',
            'nova_poshta_warehouse_ref' => $warehouseRef,
        ];
    }

    private function novaPoshtaCourierDelivery(): array
    {
        return [
            'delivery_type' => 'nova_poshta_courier',
            'nova_poshta_city_ref' => self::CITY_REF,
            'nova_poshta_city_name' => 'Київ',
            'nova_poshta_street_ref' => self::STREET_REF,
            'nova_poshta_street_name' => 'вул. Хрещатик',
            'nova_poshta_street_description' => 'Хрещатик',
            'nova_poshta_building' => '10',
            'nova_poshta_flat' => '12',
        ];
    }

    public function test_zero_price_product_is_not_purchasable(): void
    {
        $category = Category::create(['name' => 'Ножі', 'slug' => 'knives']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Ніж очікується',
            'slug' => 'pending-knife',
            'price' => 0,
            'stock' => 5,
        ]);

        $this->get($product->url())
            ->assertOk()
            ->assertSee('Очікується надходження')
            ->assertSee('Ціну ще не розраховано')
            ->assertDontSee(route('cart.store', $product), false)
            ->assertDontSee('Швидке замовлення в один клік');

        $this->post(route('cart.store', $product))
            ->assertSessionHas('error', 'Ціну ще не розраховано.');

        $this->post(route('checkout.quick'), [
            'product_id' => $product->id,
            'phone' => '+380991234567',
        ])->assertRedirect($product->url());
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_discounted_cart_can_be_recalculated_and_cleared_without_reloading_page(): void
    {
        $category = Category::create(['name' => 'Котушки', 'slug' => 'reels']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Котушка акційна',
            'slug' => 'sale-reel',
            'price' => 2000,
            'discount_percent' => 25,
            'stock' => 5,
        ]);

        $response = $this->postJson(route('cart.store', $product))
            ->assertOk()
            ->assertJsonPath('productIds', [$product->id]);
        $this->assertStringContainsString('1 500 ₴', $response->json('cart'));
        $this->assertStringContainsString('2 000 ₴', $response->json('cart'));
        $this->assertStringContainsString('−25%', $response->json('cart'));
        $this->assertStringContainsString('Ваша вигода', $response->json('cart'));
        $this->assertStringContainsString('500 ₴', $response->json('cart'));
        $this->assertStringNotContainsString('−500 ₴', $response->json('cart'));
        $this->assertStringContainsString('Ваша вигода', $response->json('drawer'));
        $this->assertStringContainsString('2 000 ₴', $response->json('drawer'));

        $response = $this->patchJson(route('cart.update', $product), ['quantity' => 3])
            ->assertOk()
            ->assertJsonPath('count', 3);
        $this->assertStringContainsString('4 500 ₴', $response->json('cart'));
        $this->assertStringContainsString('1 500 ₴', $response->json('cart'));
        $this->assertStringNotContainsString('−1 500 ₴', $response->json('cart'));

        $this->deleteJson(route('cart.clear'))
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('productIds', []);
    }

    public function test_home_page_contains_merchandising_sliders(): void
    {
        $category = Category::create(['name' => 'Намети', 'slug' => 'tents']);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Намет зі знижкою',
            'slug' => 'sale-tent',
            'price' => 3000,
            'discount_percent' => 10,
            'stock' => 4,
            'is_featured' => true,
        ]);
        $brandLeader = Product::create([
            'category_id' => $category->id,
            'name' => 'Топовий рюкзак Naturehike',
            'slug' => 'top-naturehike-backpack',
            'brand' => 'Naturehike',
            'price' => 5000,
            'stock' => 4,
        ]);
        $secondBrandLeader = Product::create([
            'category_id' => $category->id,
            'name' => 'Топова котушка Shimano',
            'slug' => 'top-shimano-reel',
            'brand' => 'Shimano',
            'price' => 3500,
            'stock' => 4,
        ]);

        DB::table('product_views_daily')->insert([
            ['product_id' => $brandLeader->id, 'viewed_on' => today()->toDateString(), 'views' => 18],
            ['product_id' => $secondBrandLeader->id, 'viewed_on' => today()->toDateString(), 'views' => 12],
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Акційні товари')
            ->assertSee('Кращі товари різних брендів')
            ->assertSee('Топовий рюкзак Naturehike')
            ->assertSee('Топова котушка Shimano')
            ->assertSee('Популярні товари')
            ->assertSee('−10%')
            ->assertSee('data-hero-slider', false)
            ->assertDontSee('Товари, на яких залишили відгуки');
    }

    public function test_catalog_filters_and_load_more_work_for_html_and_ajax(): void
    {
        $parent = Category::create([
            'name' => 'Туризм',
            'slug' => 'tourism',
            'visible_filters' => ['brand', 'model', 'price', 'season', 'material', 'weight'],
        ]);
        $category = Category::create(['name' => 'Намети', 'slug' => 'tents', 'parent_id' => $parent->id]);

        foreach (range(1, 22) as $index) {
            Product::create([
                'category_id' => $category->id,
                'name' => "Намет {$index}",
                'slug' => "tent-{$index}",
                'sku' => "TEST-{$index}",
                'brand' => $index === 22 ? 'Other' : 'Naturehike',
                'model' => $index === 22 ? 'Special' : 'Standard',
                'season' => $index === 22 ? 'Літо' : 'Всесезонний',
                'usage_type' => 'Туризм',
                'material' => $index === 22 ? 'Нейлон' : 'Поліестер',
                'weight_grams' => $index === 22 ? 900 : 2200,
                'price' => 1000 + $index,
                'stock' => 2,
            ]);
        }

        $this->get($parent->catalogUrl())
            ->assertOk()
            ->assertSee('Показати ще 20 товарів')
            ->assertSee('Сторінка 1 з 2')
            ->assertSee('aria-current="page"', false)
            ->assertSee('rel="next"', false)
            ->assertSee('page=2', false)
            ->assertSee('Naturehike')
            ->assertSee('(21)')
            ->assertSee('(1)')
            ->assertSee('У категорії «Туризм» доступно 22 товарів.')
            ->assertSeeInOrder([
                'data-load-more',
                'data-catalog-pagination',
                'data-catalog-seo',
            ], false);

        $this->get($parent->catalogUrl(['page' => 2]))
            ->assertOk()
            ->assertSee('Сторінка 2 з 2')
            ->assertSee('rel="prev"', false)
            ->assertSee('rel="canonical"', false)
            ->assertSee('page=2', false)
            ->assertSee('href="/tourism/products/tent-22"', false)
            ->assertDontSee('href="/tourism/products/tent-1"', false);

        $this->get($parent->catalogUrl(['filter' => 'other']))
            ->assertOk()
            ->assertSee('Намет 22')
            ->assertDontSee('Намет 1</a>', false);

        $this->get($parent->catalogUrl(['filter' => 'naturehike', 'page' => 2]))
            ->assertOk()
            ->assertSee('Сторінка 2 з 2')
            ->assertSee('rel="canonical"', false)
            ->assertSee('page=2', false);

        $this->getJson($parent->catalogUrl(['filter' => 'naturehike']))
            ->assertOk()
            ->assertJsonStructure(['html', 'url'])
            ->assertJsonPath('url', $parent->catalogUrl(['filter' => 'naturehike'], absolute: false));

        $this->getJson($parent->catalogUrl(['filter' => 'naturehike', 'filters_only' => 1]))
            ->assertOk()
            ->assertJsonMissingPath('html')
            ->assertJsonPath('url', $parent->catalogUrl(['filter' => 'naturehike'], absolute: false))
            ->assertJson(fn ($json) => $json
                ->whereType('filters', 'string')
                ->etc());

        $this->get('/tourism/search/other/')
            ->assertOk()
            ->assertSee('Намет 22');

        $this->get('/tourism/search/not-real-filter/')->assertNotFound();

        $multiFilterResponse = $this->getJson($parent->catalogUrl(['filter' => 'special;lito;neylon']))
            ->assertOk()
            ->json('url');

        $this->assertSame('/tourism/search/lito/neylon/special/', $multiFilterResponse);
        $this->assertStringNotContainsString('%3B', $multiFilterResponse);

        $this->get($parent->catalogUrl(['filter' => 'special;lito;neylon', 'max_weight' => 1000, 'per_page' => 40]))
            ->assertOk()
            ->assertSee('Намет 22')
            ->assertSee('Публічна оферта')
            ->assertDontSee('Намет 1</a>', false);
    }

    public function test_product_specifications_link_to_category_filters(): void
    {
        $category = Category::create(['name' => 'Гамаки', 'slug' => 'hammocks']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Гамак Naturehike Summer',
            'slug' => 'naturehike-summer',
            'sku' => 'HAM-1',
            'brand' => 'Naturehike',
            'model' => 'Summer',
            'season' => 'Літо',
            'usage_type' => 'Кемпінг',
            'material' => 'Нейлон',
            'weight_grams' => 850,
            'specifications' => ['Бренд' => 'Naturehike', 'Матеріал' => 'Нейлон', 'Вага' => '850 г'],
            'price' => 1200,
            'stock' => 3,
        ]);

        $this->get($product->url())
            ->assertOk()
            ->assertSee($category->catalogUrl(['filter' => 'naturehike']), false)
            ->assertSee($category->catalogUrl(['filter' => 'neylon']), false)
            ->assertSee($category->catalogUrl(['max_weight' => 850]), false);
    }

    public function test_category_catalog_uses_search_path_for_up_to_three_filters_and_query_for_more(): void
    {
        $category = Category::create(['name' => 'Котушки', 'slug' => 'reels']);

        foreach (['Shimano', 'Daiwa', 'Select', 'Favorite', 'Flagman', 'Ryobi'] as $brand) {
            Product::create([
                'category_id' => $category->id,
                'name' => "Котушка {$brand}",
                'slug' => 'reel-'.strtolower($brand),
                'brand' => $brand,
                'model' => 'Exage Line',
                'price' => 1000,
                'stock' => 4,
            ]);
        }

        $this->getJson($category->catalogUrl(['filter' => 'shimano']))
            ->assertOk()
            ->assertJsonPath('url', $category->catalogUrl(['filter' => 'shimano'], absolute: false));

        $this->getJson($category->catalogUrl(['filter' => 'shimano;daiwa']))
            ->assertOk()
            ->assertJsonPath('url', $category->catalogUrl(['filter' => 'shimano;daiwa'], absolute: false));

        $multiFilterUrl = $this->getJson($category->catalogUrl(['filter' => 'select;daiwa;shimano']))
            ->assertOk()
            ->json('url');

        $this->assertSame($category->catalogUrl(['filter' => 'daiwa;select;shimano'], absolute: false), $multiFilterUrl);
        $this->assertStringNotContainsString('%3B', $multiFilterUrl);

        $fourFilterUrl = $this->getJson($category->catalogUrl(['filter' => 'select;daiwa;shimano;favorite']))
            ->assertOk()
            ->json('url');

        $this->assertStringContainsString('?filter=daiwa;favorite;select;shimano', $fourFilterUrl);

        $sixFilterUrl = $this->getJson($category->catalogUrl(['filter' => 'select;daiwa;shimano;favorite;flagman;ryobi']))
            ->assertOk()
            ->json('url');

        $this->assertStringContainsString('?filter=daiwa;favorite;flagman;ryobi;select;shimano', $sixFilterUrl);

        $this->get('/reels/search/daiwa/shimano/')
            ->assertOk()
            ->assertSee('Котушка Shimano')
            ->assertSee('Котушка Daiwa');

        $this->get('/catalog')->assertNotFound();
        $this->get('/catalog/search/not-real-filter')->assertNotFound();
        $this->get('/reels/search/not-real-filter/')->assertNotFound();
    }

    public function test_legacy_filter_query_redirects_to_search_path_when_filter_count_is_indexable(): void
    {
        $category = Category::create(['name' => 'Одяг', 'slug' => 'clothes']);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Боксери Camotec Tan',
            'slug' => 'boxers-camotec-tan',
            'brand' => 'Camotec',
            'model' => 'Boksery',
            'usage_type' => 'Anatomichni',
            'material' => 'Tan',
            'price' => 1000,
            'stock' => 4,
            'is_active' => true,
            'is_processed' => true,
        ]);

        app(CatalogCache::class)->invalidate();

        $this->get('/clothes/?filter=anatomichni;boksery;camotec')
            ->assertRedirect('/clothes/search/anatomichni/boksery/camotec/');

        $this->get('/clothes/search/anatomichni/boksery/camotec/')
            ->assertOk()
            ->assertSee('Боксери Camotec Tan');
    }

    public function test_catalog_uses_product_specifications_as_filters_and_seo_heading(): void
    {
        $category = Category::create(['name' => 'Спінінги', 'slug' => 'spinning-rods']);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Спінінг Favorite X1',
            'slug' => 'favorite-x1',
            'brand' => 'Favorite',
            'price' => 2100,
            'stock' => 3,
            'specifications' => ['Довжина' => '2.4 м', 'Тест' => '5-20 г', 'Стрій' => 'Fast', 'Клас захисту' => 'IPX4'],
        ]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Спінінг Shimano Catana',
            'slug' => 'shimano-catana',
            'brand' => 'Shimano',
            'price' => 2600,
            'stock' => 3,
            'specifications' => ['Довжина' => '2.7 м', 'Тест' => '10-30 г', 'Стрій' => 'Moderate'],
        ]);

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertSee('Довжина')
            ->assertSee('2.4 м')
            ->assertSee('Тест')
            ->assertSee('Стрій')
            ->assertSee('Клас захисту')
            ->assertSee('IPX4');

        $this->get($category->catalogUrl(['filter' => '24-m;favorite;ipx4']))
            ->assertOk()
            ->assertSee('Спінінги бренд Favorite довжина 2.4 м клас захисту IPX4')
            ->assertSee('Обрані фільтри')
            ->assertSee('Скинути все')
            ->assertSee('клас захисту IPX4')
            ->assertSee('Спінінг Favorite X1')
            ->assertDontSee('Спінінг Shimano Catana');

        $response = $this->getJson($category->catalogUrl(['filter' => 'favorite']))
            ->assertOk()
            ->assertJsonStructure(['html', 'filters', 'url']);

        $this->assertStringContainsString('2.4 м', $response->json('filters'));
        $this->assertStringNotContainsString('2.7 м', $response->json('filters'));
    }

    public function test_filter_options_render_crawlable_links_that_combine_with_active_filters(): void
    {
        $category = Category::create(['name' => 'Спінінги', 'slug' => 'crawlable-spinning-rods']);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Спінінг Favorite X1',
            'slug' => 'crawlable-favorite-x1',
            'brand' => 'Favorite',
            'price' => 2100,
            'stock' => 3,
            'specifications' => ['Довжина' => '2.4 м'],
        ]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Спінінг Shimano Catana',
            'slug' => 'crawlable-shimano-catana',
            'brand' => 'Shimano',
            'price' => 2600,
            'stock' => 3,
            'specifications' => ['Довжина' => '2.7 м'],
        ]);

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertSee('href="/crawlable-spinning-rods/search/favorite/"', false)
            ->assertSee('href="/crawlable-spinning-rods/search/shimano/"', false)
            ->assertSee('data-filter-option-link', false);

        $this->get($category->catalogUrl(['filter' => 'favorite']))
            ->assertOk()
            ->assertSee('href="/crawlable-spinning-rods/search/24-m/favorite/"', false);
    }

    public function test_catalog_does_not_fail_with_older_filter_cache_without_specifications(): void
    {
        Cache::forever('catalog:version', 'legacy-test-version');
        $category = Category::create(['name' => 'Котушки', 'slug' => 'legacy-reels']);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Котушка Legacy',
            'slug' => 'legacy-reel',
            'brand' => 'Shimano',
            'price' => 2500,
            'stock' => 2,
        ]);

        Cache::put('catalog:legacy-test-version:filters:category-'.$category->id, [
            'brands' => ['Shimano' => 1],
            'models' => [],
            'seasons' => [],
            'usageTypes' => [],
            'materials' => [],
            'minPrice' => 2500,
            'maxPrice' => 2500,
            'hasSaleProducts' => false,
        ]);

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertSee('Котушка Legacy');
    }

    public function test_default_catalog_filters_hide_empty_optional_filters(): void
    {
        $category = Category::create([
            'name' => 'Спінінги',
            'slug' => 'spinning-rods',
        ]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Спінінг Favorite',
            'slug' => 'spinning-favorite',
            'brand' => 'Favorite',
            'model' => 'Pro',
            'price' => 1500,
            'stock' => 2,
        ]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Спінінг Shimano',
            'slug' => 'spinning-shimano',
            'brand' => 'Shimano',
            'model' => 'Catana',
            'price' => 2200,
            'stock' => 1,
        ]);

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertSee('<legend>Бренд</legend>', false)
            ->assertSee('<legend>Модель</legend>', false)
            ->assertSee('<legend>Ціна', false)
            ->assertDontSee('<legend>Сезон</legend>', false)
            ->assertDontSee('<legend>Матеріал</legend>', false)
            ->assertDontSee('<legend>Максимальна вага</legend>', false)
            ->assertDontSee('Акційні товари');
    }

    public function test_category_filter_visibility_can_be_configured_from_admin_fields(): void
    {
        $category = Category::create([
            'name' => 'Адмін фільтри',
            'slug' => 'admin-filters',
            'visible_filters' => ['brand'],
            'visible_spec_filters' => ['Довжина'],
        ]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Вудилище Favorite 240',
            'slug' => 'favorite-240',
            'brand' => 'Favorite',
            'material' => 'Carbon',
            'price' => 2100,
            'stock' => 3,
            'specifications' => ['Довжина' => '2.4 м', 'Тест' => '5-20 г'],
        ]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Вудилище Shimano 270',
            'slug' => 'shimano-270',
            'brand' => 'Shimano',
            'material' => 'Graphite',
            'price' => 2600,
            'stock' => 3,
            'specifications' => ['Довжина' => '2.7 м', 'Тест' => '10-30 г'],
        ]);

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertSee('<legend>Бренд</legend>', false)
            ->assertSee('<legend>Довжина</legend>', false)
            ->assertDontSee('<legend>Матеріал</legend>', false)
            ->assertDontSee('<legend>Тест</legend>', false);

        $this->get($category->catalogUrl(['filter' => 'carbon']))
            ->assertOk()
            ->assertSee('Вудилище Favorite 240')
            ->assertSee('Вудилище Shimano 270');
    }

    public function test_technical_product_attributes_are_not_available_as_catalog_filters(): void
    {
        $category = Category::create([
            'name' => 'Технічні фільтри',
            'slug' => 'technical-filters',
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Тестовий товар',
            'slug' => 'technical-filter-product',
            'price' => 1000,
            'stock' => 2,
            'specifications' => [
                'Виробник' => 'Favorite',
                'Відео review' => 'https://example.test/review',
                'Відео review shorts' => 'https://example.test/short-review',
                'Відео shorts' => 'https://example.test/shorts',
                'Колір' => 'Зелений',
            ],
        ]);

        $options = app(CatalogSpecificationFacets::class)->availableFilterOptions($category);

        $this->assertArrayHasKey('Колір', $options);
        $this->assertArrayNotHasKey('Виробник', $options);
        $this->assertArrayNotHasKey('Відео review', $options);
        $this->assertArrayNotHasKey('Відео review shorts', $options);
        $this->assertArrayNotHasKey('Відео shorts', $options);
    }

    public function test_empty_category_spec_filter_selection_hides_auto_discovered_filters(): void
    {
        $category = Category::create([
            'name' => 'Без характеристик',
            'slug' => 'without-spec-filters',
            'visible_spec_filters' => [],
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Товар із кольором',
            'slug' => 'product-with-color',
            'price' => 1000,
            'stock' => 2,
            'specifications' => ['Колір' => 'Зелений'],
        ]);

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertDontSee('Зелений');
    }

    public function test_parent_category_includes_products_from_fourth_level_descendants(): void
    {
        $tourism = Category::create(['name' => 'Туризм', 'slug' => 'tourism']);
        $furniture = Category::create(['name' => 'Кемпінгові меблі', 'slug' => 'camping-furniture', 'parent_id' => $tourism->id]);
        $chairs = Category::create(['name' => 'Крісла та стільці', 'slug' => 'camping-chairs', 'parent_id' => $furniture->id]);
        $foldingChairs = Category::create(['name' => 'Складні крісла', 'slug' => 'folding-chairs', 'parent_id' => $chairs->id]);

        $product = Product::create([
            'category_id' => $foldingChairs->id,
            'name' => 'Крісло складне туристичне',
            'slug' => 'folding-tourist-chair',
            'price' => 1700,
            'stock' => 4,
        ]);

        $this->get($tourism->catalogUrl())
            ->assertOk()
            ->assertSee('Крісло складне туристичне')
            ->assertDontSee('Складні крісла');

        $this->get($foldingChairs->catalogUrl())
            ->assertOk()
            ->assertSeeInOrder(['Каталог', 'Туризм', 'Кемпінгові меблі', 'Крісла та стільці', 'Складні крісла']);

        $this->get($product->url())
            ->assertOk()
            ->assertSeeInOrder(['Каталог', 'Туризм', 'Кемпінгові меблі', 'Крісла та стільці', 'Складні крісла', 'Крісло складне туристичне']);
    }

    public function test_feed_hash_suffixes_are_hidden_from_public_category_urls(): void
    {
        $fishing = Category::create(['name' => 'Рибальство', 'slug' => 'ribalstvo-2530dd583e']);
        $lures = Category::create(['name' => 'Приманки', 'slug' => 'ribalstvo-primanki-0033fe1013', 'parent_id' => $fishing->id]);

        Product::create([
            'category_id' => $lures->id,
            'name' => 'Воблер Jackall',
            'slug' => 'jackall-lure',
            'brand' => 'Jackall',
            'price' => 500,
            'stock' => 4,
        ]);

        $this->assertSame('/ribalstvo/primanki', $lures->catalogPath());

        $this->get('/ribalstvo/primanki')
            ->assertOk()
            ->assertSee('Воблер Jackall')
            ->assertDontSee('/ribalstvo-2530dd583e/ribalstvo-primanki-0033fe1013', false);

        $this->get('/ribalstvo/primanki/povid3ci')->assertNotFound();
    }

    public function test_long_filter_groups_show_five_options_before_expanding(): void
    {
        $category = Category::create(['name' => 'Рюкзаки', 'slug' => 'backpacks']);

        foreach (range(1, 6) as $index) {
            Product::create([
                'category_id' => $category->id,
                'name' => "Рюкзак {$index}",
                'slug' => "backpack-{$index}",
                'brand' => "Brand {$index}",
                'price' => 1000 + $index,
                'stock' => 2,
            ]);
        }

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertSee('Показати ще')
            ->assertSee('data-filter-extra hidden', false);
    }

    public function test_products_can_be_toggled_in_favorites_and_comparison_without_reloading(): void
    {
        $category = Category::create(['name' => 'Котушки', 'slug' => 'reels']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Котушка Shimano Nexave',
            'slug' => 'shimano-nexave',
            'brand' => 'Shimano',
            'model' => 'Nexave',
            'price' => 2350,
            'stock' => 4,
        ]);

        $this->postJson(route('favorites.toggle', $product))
            ->assertOk()
            ->assertJsonPath('added', true)
            ->assertJsonPath('favoriteCount', 1)
            ->assertJsonPath('favoriteIds', [$product->id]);

        $this->get(route('favorites.index'))
            ->assertOk()
            ->assertSee('Обрані товари')
            ->assertSee('Котушка Shimano Nexave');

        $this->postJson(route('comparison.toggle', $product))
            ->assertOk()
            ->assertJsonPath('added', true)
            ->assertJsonPath('comparisonCount', 1)
            ->assertJsonPath('comparisonIds', [$product->id]);

        $this->get(route('comparison.index'))
            ->assertOk()
            ->assertSee('Порівняння товарів')
            ->assertSee('Shimano')
            ->assertSee('Nexave');

        $this->postJson(route('favorites.toggle', $product))
            ->assertOk()
            ->assertJsonPath('added', false)
            ->assertJsonPath('favoriteCount', 0)
            ->assertJsonPath('favoriteIds', []);
    }

    public function test_out_of_stock_products_are_shown_last_without_buy_button(): void
    {
        $category = Category::create(['name' => 'Ліхтарі', 'slug' => 'lanterns']);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Ліхтар відсутній',
            'slug' => 'out-of-stock-lantern',
            'price' => 800,
            'stock' => 0,
        ]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Ліхтар у наявності',
            'slug' => 'available-lantern',
            'price' => 900,
            'stock' => 3,
        ]);

        $this->get($category->catalogUrl(['sort' => 'price_asc']))
            ->assertOk()
            ->assertSeeInOrder(['Ліхтар у наявності', 'Ліхтар відсутній'])
            ->assertSee('Немає в наявності')
            ->assertSee('Очікується надходження')
            ->assertSee(route('cart.store', Product::query()->where('stock', 3)->firstOrFail(), false), false)
            ->assertDontSee(route('cart.store', Product::query()->where('stock', 0)->firstOrFail(), false), false);
    }

    public function test_sale_filter_is_visible_only_for_categories_with_discounted_products(): void
    {
        $saleCategory = Category::create(['name' => 'Спінінги', 'slug' => 'spinning-rods']);
        $regularCategory = Category::create(['name' => 'Гачки', 'slug' => 'hooks']);
        Product::create([
            'category_id' => $saleCategory->id,
            'name' => 'Спінінг акційний',
            'slug' => 'sale-spinning-rod',
            'brand' => 'Favorite',
            'price' => 1800,
            'discount_percent' => 15,
            'stock' => 2,
        ]);
        Product::create([
            'category_id' => $saleCategory->id,
            'name' => 'Спінінг звичайний',
            'slug' => 'regular-spinning-rod',
            'brand' => 'Shimano',
            'price' => 1600,
            'stock' => 2,
        ]);
        Product::create([
            'category_id' => $regularCategory->id,
            'name' => 'Гачок звичайний',
            'slug' => 'regular-hook',
            'price' => 120,
            'stock' => 8,
        ]);

        Product::create([
            'category_id' => $saleCategory->id,
            'name' => 'Спінінг зі знижкою',
            'slug' => 'sale-price-spinning-rod',
            'brand' => 'Daiwa',
            'price' => 2000,
            'sale_price' => 1700,
            'discount_percent' => 0,
            'stock' => 1,
        ]);

        $this->get($saleCategory->catalogUrl())
            ->assertOk()
            ->assertSee('Акційні товари')
            ->assertSee('name="on_sale"', false)
            ->assertSeeInOrder(['Акційні товари', 'Бренд']);

        $this->get($saleCategory->catalogUrl(['filter' => 'sale']))
            ->assertOk()
            ->assertSee('Спінінг акційний')
            ->assertSee('Спінінг зі знижкою')
            ->assertDontSee('Спінінг звичайний');

        $saleWithSort = $this->getJson($saleCategory->catalogUrl(['on_sale' => 1, 'sort' => 'price_asc', 'fast_filters' => 1]))
            ->assertOk()
            ->assertJsonPath('filters', null)
            ->assertJsonPath('url', $saleCategory->catalogUrl(['filter' => 'sale', 'sort' => 'price_asc'], absolute: false));

        $this->assertStringContainsString('Спінінг акційний', (string) $saleWithSort->json('html'));
        $this->assertStringNotContainsString('Спінінг звичайний', (string) $saleWithSort->json('html'));

        $this->get($regularCategory->catalogUrl())
            ->assertOk()
            ->assertDontSee('name="on_sale"', false);
    }

    public function test_product_stock_price_range_and_cart_state_are_presented_clearly(): void
    {
        $category = Category::create(['name' => 'Термоси', 'slug' => 'thermoses']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Термос останній',
            'slug' => 'last-thermos',
            'price' => 1200,
            'discount_percent' => 20,
            'stock' => 1,
        ]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Термос великий',
            'slug' => 'large-thermos',
            'price' => 2400,
            'stock' => 3,
        ]);

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertSee('placeholder="від 1 200"', false)
            ->assertSee('placeholder="до 2 400"', false)
            ->assertSee('Скоро закінчується')
            ->assertSee('has-discount', false);

        $this->post(route('cart.store', $product));

        $this->get($product->url())
            ->assertOk()
            ->assertSee('Скоро закінчується')
            ->assertSee('Є в наявності')
            ->assertDontSee('Є в наявності: 1 шт.')
            ->assertSee('data-cart-product="'.$product->id.'"', false)
            ->assertSee('У кошику');
    }

    public function test_ibis_feed_importer_populates_real_catalog_fields(): void
    {
        $result = app(IbisFeedImporter::class)->import(base_path('tests/Fixtures/ibis-feed.xml'), true);

        $this->assertSame(2, $result['products']);
        $this->assertSame(4, $result['categories']);
        $this->assertDatabaseHas('categories', ['name' => 'Намети']);
        $this->assertDatabaseHas('products', [
            'external_id' => '12271354',
            'source' => 'ibis-gear',
            'brand' => 'Naturehike',
            'model' => 'Cloud Up',
            'season' => 'Літо',
            'material' => 'Нейлон',
            'weight_grams' => 1800,
            'price' => 5000,
            'sale_price' => 4250,
            'discount_percent' => 15,
            'stock' => 10,
            'is_processed' => false,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('products', [
            'external_id' => '12271355',
            'stock' => 0,
        ]);

        $product = Product::query()->where('external_id', '12271354')->firstOrFail();

        $this->assertSame(4250.0, $product->salePrice());
        $this->assertSame(['https://example.com/tent-gallery.jpg'], $product->gallery_images);
        $this->assertSame('Легкий туристичний намет.', $product->description);
    }

    public function test_catalog_popularity_uses_product_views_from_last_thirty_days(): void
    {
        Carbon::setTestNow('2026-06-02 12:00:00');

        $category = Category::create(['name' => 'Котушки', 'slug' => 'popular-reels']);
        $olderLeader = Product::create([
            'category_id' => $category->id,
            'name' => 'Стара популярна котушка',
            'slug' => 'old-popular-reel',
            'price' => 1500,
            'stock' => 3,
        ]);
        $monthlyLeader = Product::create([
            'category_id' => $category->id,
            'name' => 'Популярна котушка місяця',
            'slug' => 'monthly-popular-reel',
            'price' => 1800,
            'stock' => 3,
        ]);

        DB::table('product_views_daily')->insert([
            ['product_id' => $olderLeader->id, 'viewed_on' => '2026-04-01', 'views' => 500],
            ['product_id' => $olderLeader->id, 'viewed_on' => '2026-06-01', 'views' => 2],
            ['product_id' => $monthlyLeader->id, 'viewed_on' => '2026-05-20', 'views' => 12],
        ]);

        $this->artisan('catalog:refresh-popularity')->assertSuccessful();

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertSeeInOrder(['Популярна котушка місяця', 'Стара популярна котушка']);

        $this->get($olderLeader->url())->assertOk();
        $this->get($olderLeader->url())->assertOk();

        $this->assertDatabaseHas('product_views_daily', [
            'product_id' => $olderLeader->id,
            'viewed_on' => '2026-06-02',
            'views' => 1,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $olderLeader->id,
            'monthly_views' => 3,
        ]);

        Carbon::setTestNow();
    }

    public function test_product_gallery_renders_slider_controls_for_multiple_images(): void
    {
        $category = Category::create(['name' => 'Намети', 'slug' => 'slider-tents']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Намет з галереєю',
            'slug' => 'tent-with-gallery',
            'price' => 4200,
            'stock' => 3,
            'image_url' => 'https://example.com/tent-main.jpg',
            'gallery_images' => [
                'https://example.com/tent-side.jpg',
                'https://example.com/tent-inside.jpg',
            ],
        ]);

        $this->get($product->url())
            ->assertOk()
            ->assertSee('data-product-gallery', false)
            ->assertSee('data-gallery-prev', false)
            ->assertSee('data-gallery-next', false)
            ->assertSee('data-gallery-current', false)
            ->assertSee('data-gallery-index="2"', false)
            ->assertSee('1</b> / 3', false);

        $this->assertStringContainsString('visibleThumbs', file_get_contents(resource_path('js/app.js')));
    }

    public function test_products_without_image_use_local_placeholder(): void
    {
        $category = Category::create(['name' => 'Ліхтарі', 'slug' => 'placeholder-lanterns']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Ліхтар без фото',
            'slug' => 'lantern-without-photo',
            'price' => 900,
            'stock' => 2,
        ]);

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertSee('/images/product-placeholder.svg', false);

        $this->get($product->url())
            ->assertOk()
            ->assertSee('/images/product-placeholder.svg', false);
    }

    public function test_smart_search_suggests_products_and_categories_for_wrong_keyboard_layout(): void
    {
        $category = Category::create(['name' => 'Вудилища', 'slug' => 'fishing-rods']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Вудка зимова тестова',
            'slug' => 'winter-fishing-rod-search',
            'brand' => 'Salmo',
            'price' => 850,
            'stock' => 4,
        ]);

        $this->getJson(route('search.suggestions', ['q' => 'delrf']))
            ->assertOk()
            ->assertJsonPath('corrected', 'вудка')
            ->assertJsonFragment(['id' => $category->id, 'name' => 'Вудилища'])
            ->assertJsonFragment(['id' => $product->id, 'name' => 'Вудка зимова тестова']);

        $this->get(route('search.index', ['q' => 'delrf']))
            ->assertOk()
            ->assertSee('Вудка зимова тестова')
            ->assertSee('Вудилища');
    }

    public function test_smart_search_learns_from_result_clicks(): void
    {
        $category = Category::create(['name' => 'Котушки', 'slug' => 'search-reels']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Котушка Daiwa Search',
            'slug' => 'daiwa-search-reel',
            'brand' => 'Daiwa',
            'price' => 3200,
            'stock' => 3,
        ]);

        $this->postJson(route('search.click'), [
            'query' => 'daiwa котушка',
            'type' => 'product',
            'id' => $product->id,
        ])->assertOk()->assertJson(['ok' => true]);

        $this->postJson(route('search.click'), [
            'query' => 'daiwa котушка',
            'type' => 'product',
            'id' => $product->id,
        ])->assertOk();

        $this->assertDatabaseHas('search_interactions', [
            'query_hash' => sha1('daiwa котушка'),
            'result_type' => 'product',
            'result_id' => $product->id,
            'clicks' => 2,
        ]);
    }

    public function test_missing_pages_render_custom_404(): void
    {
        $this->get('/takoyi-storinky-nemae')
            ->assertNotFound()
            ->assertSee('Сторінку не знайдено')
            ->assertSee('Маршрут загубився')
            ->assertSee('noindex, follow', false);
    }
}
