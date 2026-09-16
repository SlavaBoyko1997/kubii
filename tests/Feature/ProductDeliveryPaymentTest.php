<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PaymentOption;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDeliveryPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_page_shows_delivery_and_payment_block(): void
    {
        $category = Category::create([
            'name' => 'Намети',
            'slug' => 'tents',
            'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Намет тестовий',
            'slug' => 'tent-test',
            'price' => 3000,
            'stock' => 5,
            'is_active' => true,
        ]);

        $this->get($product->url())
            ->assertOk()
            ->assertSee('Доставка', false)
            ->assertSee('Нова Пошта по Україні', false)
            ->assertSee('Безкоштовна доставка від 3 000 ₴', false)
            ->assertSee('Детальніше про доставку та оплату', false)
            ->assertSee('Оплата карткою на сайті', false)
            ->assertSee('Післяплата у Новій Пошті', false);
    }

    public function test_product_page_respects_category_payment_restrictions(): void
    {
        $category = Category::create([
            'name' => 'Зброя',
            'slug' => 'weapon',
            'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Товар з обмеженнями',
            'slug' => 'restricted-product',
            'price' => 5000,
            'stock' => 1,
            'is_active' => true,
        ]);

        $iban = PaymentOption::query()->where('code', 'iban')->firstOrFail();
        $category->paymentOptions()->sync([$iban->id]);

        $response = $this->get($product->url());

        $response
            ->assertOk()
            ->assertSee('Оплата на IBAN', false)
            ->assertDontSee('Оплата карткою на сайті', false)
            ->assertDontSee('Післяплата у Новій Пошті', false);
    }
}
