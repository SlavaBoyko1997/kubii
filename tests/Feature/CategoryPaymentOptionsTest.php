<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PaymentOption;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryPaymentOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_shows_only_payment_methods_allowed_for_category(): void
    {
        $category = Category::create(['name' => 'Ножі', 'slug' => 'knives-payment']);
        $cashOnDelivery = PaymentOption::query()->where('code', 'cash_on_delivery')->firstOrFail();
        $cashOnDelivery->categories()->sync([$category->id]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Нож туристичний',
            'slug' => 'travel-knife-payment',
            'price' => 900,
            'stock' => 4,
        ]);

        $this->post(route('cart.store', $product));

        $this->get(route('checkout.create'))
            ->assertOk()
            ->assertSee('value="cash_on_delivery"', false)
            ->assertDontSee('value="liqpay_hold"', false)
            ->assertDontSee('value="iban"', false);
    }

    public function test_checkout_uses_intersection_when_cart_has_products_from_multiple_categories(): void
    {
        $cashOnlyCategory = Category::create(['name' => 'Спінінги', 'slug' => 'spinning-payment']);
        $cardCategory = Category::create(['name' => 'Котушки', 'slug' => 'reels-payment']);

        $cashOnDelivery = PaymentOption::query()->where('code', 'cash_on_delivery')->firstOrFail();
        $liqpayHold = PaymentOption::query()->where('code', 'liqpay_hold')->firstOrFail();

        $cashOnDelivery->categories()->sync([$cashOnlyCategory->id]);
        $liqpayHold->categories()->sync([$cardCategory->id]);
        $cashOnDelivery->categories()->syncWithoutDetaching([$cardCategory->id]);

        $cashProduct = Product::create([
            'category_id' => $cashOnlyCategory->id,
            'name' => 'Спінінг',
            'slug' => 'spinning-product-payment',
            'price' => 1200,
            'stock' => 3,
        ]);
        $cardProduct = Product::create([
            'category_id' => $cardCategory->id,
            'name' => 'Котушка',
            'slug' => 'reel-product-payment',
            'price' => 2400,
            'stock' => 2,
        ]);

        $this->post(route('cart.store', $cashProduct));
        $this->post(route('cart.store', $cardProduct));

        $this->get(route('checkout.create'))
            ->assertOk()
            ->assertSee('value="cash_on_delivery"', false)
            ->assertDontSee('value="liqpay_hold"', false)
            ->assertDontSee('value="iban"', false);
    }

    public function test_checkout_rejects_payment_method_not_allowed_for_category(): void
    {
        $category = Category::create(['name' => 'Приманки', 'slug' => 'lures-payment']);
        $cashOnDelivery = PaymentOption::query()->where('code', 'cash_on_delivery')->firstOrFail();
        $cashOnDelivery->categories()->sync([$category->id]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Воблер',
            'slug' => 'lure-payment',
            'price' => 350,
            'stock' => 10,
        ]);

        $this->post(route('cart.store', $product));

        $this->post(route('checkout.store'), [
            'last_name' => 'Петренко',
            'first_name' => 'Іван',
            'patronymic' => 'Петрович',
            'phone' => '+380991234567',
            'email' => 'lure-payment@example.com',
            'delivery_type' => 'nova_poshta_warehouse',
            'nova_poshta_city_ref' => '8d5a980d-391c-11dd-90d9-001a92567626',
            'nova_poshta_city_name' => 'Київ',
            'nova_poshta_warehouse_ref' => 'warehouse-ref-1',
            'payment_method' => 'liqpay_hold',
        ])->assertSessionHasErrors('payment_method');

        $this->assertDatabaseCount('orders', 0);
    }
}
