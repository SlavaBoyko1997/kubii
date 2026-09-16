<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_popup_login_returns_validation_errors_as_json(): void
    {
        User::factory()->create([
            'email' => 'customer@example.com',
            'password' => 'correct-password',
        ]);

        $this->postJson(route('login.store'), [
            'login' => 'customer@example.com',
            'password' => 'wrong-password',
            'remember' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('login')
            ->assertJsonPath('message', 'Невірний email, номер телефону або пароль.');

        $this->assertGuest();
    }

    public function test_customer_can_login_with_email_or_phone_number(): void
    {
        $user = User::factory()->create([
            'email' => 'login@example.com',
            'phone' => '+380991234567',
            'password' => 'correct-password',
        ]);

        $this->post(route('login.store'), [
            'login' => 'LOGIN@EXAMPLE.COM',
            'password' => 'correct-password',
        ])->assertRedirect(route('account.index'));

        $this->assertAuthenticatedAs($user);
        auth()->logout();
        $this->flushSession();

        $this->post(route('login.store'), [
            'login' => '+38 (099) 123-45-67',
            'password' => 'correct-password',
        ])->assertRedirect(route('account.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_customer_can_login_with_phone_without_plus_or_country_code(): void
    {
        $user = User::factory()->create([
            'email' => 'phone-login@example.com',
            'phone' => '+380991234567',
            'password' => 'correct-password',
        ]);

        foreach (['380991234567', '0991234567', '991234567'] as $phone) {
            $this->post(route('login.store'), [
                'login' => $phone,
                'password' => 'correct-password',
            ])->assertRedirect(route('account.index'));

            $this->assertAuthenticatedAs($user);
            auth()->logout();
            $this->flushSession();
        }
    }

    public function test_login_form_accepts_email_or_phone_in_one_field(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Email або номер телефону')
            ->assertSee('name="login"', false)
            ->assertDontSee('type="email" name="login"', false);
    }

    public function test_popup_login_accepts_remember_without_treating_it_as_a_credential(): void
    {
        $user = User::factory()->create([
            'email' => 'remember@example.com',
            'password' => 'correct-password',
        ]);

        $this->postJson(route('login.store'), [
            'email' => 'remember@example.com',
            'password' => 'correct-password',
            'remember' => true,
        ])
            ->assertOk()
            ->assertJsonPath('redirect', route('account.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_authenticated_cart_is_loaded_from_database_on_another_session(): void
    {
        $user = User::factory()->create();
        $category = Category::create(['name' => 'Кошик у БД', 'slug' => 'database-cart']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Товар між пристроями',
            'slug' => 'cross-device-product',
            'price' => 900,
            'stock' => 10,
        ]);

        $this->actingAs($user)->post(route('cart.store', $product), ['quantity' => 3]);

        $this->assertDatabaseHas('cart_items', [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        auth()->logout();
        $this->flushSession();

        $this->actingAs($user)->get(route('cart.index'))
            ->assertOk()
            ->assertSee('Товар між пристроями')
            ->assertSee('2 700');
    }

    public function test_login_with_two_carts_asks_to_merge_and_can_combine_them(): void
    {
        $user = User::factory()->create([
            'email' => 'merge-cart@example.com',
            'password' => 'correct-password',
        ]);
        $category = Category::create(['name' => 'Об’єднання', 'slug' => 'cart-merge']);
        $accountProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'Товар акаунта',
            'slug' => 'account-cart-product',
            'price' => 1000,
            'stock' => 10,
        ]);
        $guestProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'Гостьовий товар',
            'slug' => 'guest-cart-product',
            'price' => 700,
            'stock' => 10,
        ]);
        CartItem::create([
            'user_id' => $user->id,
            'product_id' => $accountProduct->id,
            'quantity' => 2,
        ]);

        $this->post(route('cart.store', $guestProduct), ['quantity' => 3]);
        $this->withSession(['url.intended' => route('checkout.create')])
            ->postJson(route('login.store'), [
                'email' => $user->email,
                'password' => 'correct-password',
            ])
            ->assertOk()
            ->assertJsonPath('redirect', route('cart.merge.show'))
            ->assertSessionHas('cart_merge_pending', [$guestProduct->id => 3]);

        $this->get(route('cart.merge.show'))
            ->assertOk()
            ->assertSee('Товар акаунта')
            ->assertSee('Гостьовий товар');

        $this->post(route('cart.merge'))
            ->assertRedirect(route('checkout.create'));

        $this->assertDatabaseHas('cart_items', [
            'user_id' => $user->id,
            'product_id' => $accountProduct->id,
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('cart_items', [
            'user_id' => $user->id,
            'product_id' => $guestProduct->id,
            'quantity' => 3,
        ]);
    }

    public function test_customer_can_keep_account_cart_instead_of_guest_cart(): void
    {
        $user = User::factory()->create([
            'email' => 'keep-cart@example.com',
            'password' => 'correct-password',
        ]);
        $category = Category::create(['name' => 'Вибір кошика', 'slug' => 'keep-account-cart']);
        $accountProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'Збережений товар',
            'slug' => 'kept-account-product',
            'price' => 1200,
            'stock' => 5,
        ]);
        $guestProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'Незбережений товар',
            'slug' => 'discarded-guest-product',
            'price' => 600,
            'stock' => 5,
        ]);
        CartItem::create(['user_id' => $user->id, 'product_id' => $accountProduct->id, 'quantity' => 1]);

        $this->post(route('cart.store', $guestProduct));
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('cart.merge.show'));

        $this->post(route('cart.keep-account'))
            ->assertRedirect(route('account.index'));

        $this->assertDatabaseHas('cart_items', [
            'user_id' => $user->id,
            'product_id' => $accountProduct->id,
            'quantity' => 1,
        ]);
        $this->assertDatabaseMissing('cart_items', [
            'user_id' => $user->id,
            'product_id' => $guestProduct->id,
        ]);
    }

    public function test_customer_can_keep_device_cart_instead_of_account_cart(): void
    {
        $user = User::factory()->create([
            'email' => 'keep-device-cart@example.com',
            'password' => 'correct-password',
        ]);
        $category = Category::create(['name' => 'Кошик пристрою', 'slug' => 'keep-device-cart']);
        $accountProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'Товар старого кошика',
            'slug' => 'old-account-cart-product',
            'price' => 1000,
            'stock' => 5,
        ]);
        $guestProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'Товар цього пристрою',
            'slug' => 'current-device-cart-product',
            'price' => 800,
            'stock' => 5,
        ]);
        CartItem::create(['user_id' => $user->id, 'product_id' => $accountProduct->id, 'quantity' => 2]);

        $this->post(route('cart.store', $guestProduct), ['quantity' => 3]);
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('cart.merge.show'));

        $this->post(route('cart.keep-device'))
            ->assertRedirect(route('account.index'));

        $this->assertDatabaseMissing('cart_items', [
            'user_id' => $user->id,
            'product_id' => $accountProduct->id,
        ]);
        $this->assertDatabaseHas('cart_items', [
            'user_id' => $user->id,
            'product_id' => $guestProduct->id,
            'quantity' => 3,
        ]);
    }

    public function test_popup_registration_validates_required_and_unique_customer_data(): void
    {
        User::factory()->create([
            'email' => 'used@example.com',
            'phone' => '+380991234567',
        ]);

        $response = $this->postJson(route('register.store'), [
            'last_name' => 'Коваль',
            'first_name' => 'Марія',
            'email' => 'used@example.com',
            'phone' => '+38 (099) 123-45-67',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertUnprocessable();

        $errors = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['errors'];

        $this->assertArrayHasKey('patronymic', $errors);
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('phone', $errors);

        $this->assertGuest();
    }

    public function test_customer_can_register_through_popup_json_flow(): void
    {
        $this->postJson(route('register.store'), [
            'last_name' => 'Коваль',
            'first_name' => 'Марія',
            'patronymic' => 'Іванівна',
            'email' => 'popup@example.com',
            'phone' => '+38 (067) 765-43-21',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('redirect', route('account.index'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'popup@example.com',
            'phone' => '+380677654321',
        ]);
    }

    public function test_customer_can_register_and_open_account(): void
    {
        $response = $this->post(route('register.store'), [
            'last_name' => 'Коваль',
            'first_name' => 'Марія',
            'patronymic' => 'Іванівна',
            'email' => 'maria@example.com',
            'phone' => '+380 99 123 45 67',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('account.index'));
        $this->assertAuthenticated();
        $this->get(route('account.index'))->assertOk()->assertSee('Коваль')->assertSee('Марія')->assertSee('Іванівна');
        $this->assertDatabaseHas('users', [
            'email' => 'maria@example.com',
            'phone' => '+380991234567',
            'last_name' => 'Коваль',
            'first_name' => 'Марія',
            'patronymic' => 'Іванівна',
        ]);
    }

    public function test_registration_requires_valid_phone_number(): void
    {
        $this->post(route('register.store'), [
            'last_name' => 'Коваль',
            'first_name' => 'Марія',
            'email' => 'maria@example.com',
            'phone' => 'phone-me',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSessionHasErrors('phone');

        $this->assertGuest();
    }

    public function test_registration_requires_unique_email_and_phone(): void
    {
        User::factory()->create([
            'email' => 'maria@example.com',
            'phone' => '+380991234567',
        ]);

        $this->post(route('register.store'), [
            'last_name' => 'Коваль',
            'first_name' => 'Марія',
            'email' => 'maria@example.com',
            'phone' => '+38 (099) 123-45-67',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSessionHasErrors(['email', 'phone']);

        $this->assertGuest();
    }

    public function test_regular_customer_cannot_access_admin_panel(): void
    {
        $customer = User::create([
            'name' => 'Клієнт',
            'email' => 'customer@example.com',
            'password' => 'password123',
        ]);

        $this->actingAs($customer)->get('/admin')->assertForbidden();
    }

    public function test_customer_without_phone_can_add_unique_phone_in_account(): void
    {
        User::factory()->create(['phone' => '+380991234567']);
        $customer = User::factory()->create(['phone' => null]);

        $this->actingAs($customer)->get(route('account.index'))
            ->assertOk()
            ->assertSee('Додати телефон');

        $this->actingAs($customer)->post(route('account.phone.store'), [
            'phone' => '+38 (099) 123-45-67',
        ])->assertSessionHasErrors('phone');

        $this->actingAs($customer)->post(route('account.phone.store'), [
            'phone' => '+38 (067) 765-43-21',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'id' => $customer->id,
            'phone' => '+380677654321',
        ]);
    }

    public function test_customer_can_add_missing_name_parts_in_account(): void
    {
        $customer = User::create([
            'name' => 'Коваль',
            'last_name' => 'Коваль',
            'email' => 'missing-name@example.com',
            'phone' => '+380991234567',
            'password' => 'password123',
        ]);

        $this->actingAs($customer)->get(route('account.index'))
            ->assertOk()
            ->assertSee("Ім'я", false)
            ->assertSee('По батькові');

        $this->actingAs($customer)->post(route('account.profile.store'), [
            'first_name' => 'Марія',
            'patronymic' => 'Іванівна',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'id' => $customer->id,
            'last_name' => 'Коваль',
            'first_name' => 'Марія',
            'patronymic' => 'Іванівна',
            'name' => 'Коваль Марія Іванівна',
        ]);
    }

    public function test_account_displays_order_items_and_delivery_details(): void
    {
        $customer = User::factory()->create(['phone' => '+380991234567']);
        $category = Category::create(['name' => 'Намети', 'slug' => 'tents']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Намет Alpine Trek',
            'slug' => 'alpine-trek-tent',
            'price' => 4200,
            'stock' => 3,
        ]);
        $order = Order::create([
            'user_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'city' => 'Київ',
            'delivery_address' => 'вул. Пирогівський шлях, 135',
            'delivery_type' => 'nova_poshta_warehouse',
            'nova_poshta_city_ref' => 'kyiv-ref',
            'nova_poshta_city_name' => 'Київ',
            'nova_poshta_warehouse_ref' => 'warehouse-ref',
            'nova_poshta_warehouse_name' => 'Відділення №1',
            'nova_poshta_warehouse_number' => '1',
            'nova_poshta_warehouse_address' => 'вул. Пирогівський шлях, 135',
            'payment_method' => 'cash_on_delivery',
            'total' => 4200,
        ]);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $order->number);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'price' => 4200,
            'quantity' => 1,
            'subtotal' => 4200,
        ]);

        $this->actingAs($customer)->get(route('account.index'))
            ->assertOk()
            ->assertSee('Профіль покупця')
            ->assertSee($customer->email)
            ->assertSee($customer->phone)
            ->assertSee('Звичайне замовлення')
            ->assertSee($order->number)
            ->assertSee('Намет Alpine Trek')
            ->assertSee('Доставка Новою Поштою')
            ->assertSee('Тип отримання')
            ->assertSee('Обране відділення')
            ->assertSee('Відділення №1')
            ->assertSee('вул. Пирогівський шлях, 135')
            ->assertSee('Післяплата');
    }

    public function test_account_displays_five_orders_per_page_with_catalog_pagination(): void
    {
        $customer = User::factory()->create(['phone' => '+380991234567']);
        $orders = collect();

        foreach (range(1, 6) as $index) {
            $orders->push(Order::create([
                'user_id' => $customer->id,
                'customer_name' => $customer->name,
                'phone' => $customer->phone,
                'delivery_type' => 'nova_poshta_warehouse',
                'nova_poshta_city_name' => 'Київ',
                'nova_poshta_warehouse_name' => 'Відділення №'.$index,
                'payment_method' => 'cash_on_delivery',
                'total' => 1000 + $index,
            ]));
        }

        $firstPage = $this->actingAs($customer)->get(route('account.index'));

        $firstPage
            ->assertOk()
            ->assertSee('Замовлень: 6')
            ->assertSee('Показано 1-5 з 6 замовлень')
            ->assertSee('Сторінка 1 з 2')
            ->assertSee($orders->last()->number)
            ->assertDontSee($orders->first()->number);

        $this->actingAs($customer)
            ->get(route('account.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('Показано 6-6 з 6 замовлень')
            ->assertSee('Сторінка 2 з 2')
            ->assertSee($orders->first()->number)
            ->assertDontSee($orders->last()->number);
    }
}
