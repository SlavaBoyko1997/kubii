<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_customer_can_leave_multiple_reviews_and_reply(): void
    {
        $customer = User::create([
            'name' => 'Олена Коваль',
            'email' => 'olena@example.com',
            'password' => 'password123',
        ]);
        $category = Category::create(['name' => 'Намети', 'slug' => 'tents']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Намет Naturehike',
            'slug' => 'naturehike-tent',
            'price' => 4200,
            'stock' => 4,
        ]);
        $order = Order::create([
            'user_id' => $customer->id,
            'number' => 'FT-TEST-REVIEW',
            'customer_name' => $customer->name,
            'phone' => '+380991234567',
            'city' => 'Київ',
            'delivery_address' => 'Відділення 1',
            'payment_method' => 'cash_on_delivery',
            'total' => 4200,
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'price' => 4200,
            'quantity' => 1,
            'subtotal' => 4200,
        ]);

        $this->post(route('reviews.store', $product), [
            'rating' => 5,
            'body' => 'Чудовий намет для літніх походів.',
        ])->assertRedirect(route('login'));

        $this->actingAs($customer)->post(route('reviews.store', $product), [
            'rating' => 5,
            'title' => 'Рекомендую',
            'body' => 'Чудовий намет для літніх походів.',
            'pros' => 'Легко встановити.',
            'cons' => 'Хотілося б більше кольорів.',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('reviews', [
            'product_id' => $product->id,
            'user_id' => $customer->id,
            'rating' => 5,
            'is_verified_purchase' => true,
        ]);

        $this->actingAs($customer)->post(route('reviews.store', $product), [
            'rating' => 4,
            'body' => 'Ще один відгук після декількох виїздів.',
        ])->assertSessionHas('success');

        $this->assertDatabaseCount('reviews', 2);

        $review = Review::query()->where('rating', 5)->firstOrFail();

        $this->actingAs($customer)->post(route('reviews.replies.store', $review), [
            'body' => 'Додаю відповідь до першого відгуку.',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('reviews', [
            'parent_id' => $review->id,
            'product_id' => $product->id,
            'user_id' => $customer->id,
            'rating' => null,
        ]);
        $product->refresh();
        $this->assertSame(2, $product->reviews_count);
        $this->assertSame(2, $product->visible_reviews_count);

        $this->actingAs($customer)->post(route('reviews.vote', $review), ['is_helpful' => 1])->assertSessionHas('success');
        $this->actingAs($customer)->post(route('reviews.vote', $review), ['is_helpful' => 0])->assertSessionHas('success');
        $this->assertDatabaseHas('review_votes', [
            'review_id' => $review->id,
            'user_id' => $customer->id,
            'is_helpful' => false,
        ]);
        $this->assertDatabaseCount('review_votes', 1);

        $this->get($product->url())
            ->assertOk()
            ->assertSee('Ще один відгук')
            ->assertSee('Додаю відповідь')
            ->assertSee('Олена Коваль')
            ->assertSee('Товар куплений')
            ->assertSee('Легко встановити.')
            ->assertSee('Хотілося б більше кольорів.');
    }

    public function test_product_reviews_are_paginated_by_five(): void
    {
        $customer = User::create([
            'name' => 'Олена Коваль',
            'email' => 'olena@example.com',
            'password' => 'password123',
        ]);
        $category = Category::create(['name' => 'Намети', 'slug' => 'tents']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Намет Naturehike',
            'slug' => 'naturehike-tent',
            'price' => 4200,
            'stock' => 4,
        ]);

        foreach (range(1, 6) as $index) {
            $product->reviews()->create([
                'user_id' => $customer->id,
                'rating' => 5,
                'body' => "Відгук про намет номер {$index}.",
            ]);
        }

        $product->refresh();
        $this->assertSame(6, $product->reviews_count);

        $this->get($product->url())
            ->assertOk()
            ->assertSee('reviews_page=2')
            ->assertSee('Відгук про намет номер 6.')
            ->assertDontSee('Відгук про намет номер 1.');

        $this->get($product->url().'?reviews_page=2')
            ->assertOk()
            ->assertSee('Відгук про намет номер 1.');
    }

    public function test_related_products_show_only_purchasable_items(): void
    {
        $category = Category::create(['name' => 'Воблери', 'slug' => 'crankbaits']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Воблер основний',
            'slug' => 'main-crankbait',
            'price' => 450,
            'stock' => 4,
        ]);
        $availableRelated = Product::create([
            'category_id' => $category->id,
            'name' => 'Воблер доступний',
            'slug' => 'available-crankbait',
            'price' => 390,
            'stock' => 2,
        ]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Воблер без наявності',
            'slug' => 'out-of-stock-crankbait',
            'price' => 390,
            'stock' => 0,
        ]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Воблер без ціни',
            'slug' => 'pending-crankbait',
            'price' => 0,
            'stock' => 3,
        ]);

        $this->get($product->url())
            ->assertOk()
            ->assertSee(__('Схожі товари'))
            ->assertSee('Воблер доступний')
            ->assertDontSee('Воблер без наявності')
            ->assertDontSee('Воблер без ціни');
    }
}
