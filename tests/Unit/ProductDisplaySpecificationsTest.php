<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDisplaySpecificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_display_specifications_hide_internal_feed_keys(): void
    {
        $category = Category::create(['name' => 'Одяг', 'slug' => 'clothes']);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Футболка',
            'slug' => 'tshirt',
            'price' => 650,
            'stock' => 3,
            'specifications' => [
                'Виробник у фіді' => 'Pobedov',
                'Відео' => 'https://www.youtube.com/watch?v=example',
                'Сезон' => 'Літо',
            ],
            'specifications_ru' => [
                'Производитель в фиде' => 'Pobedov',
                'Видео' => 'https://www.youtube.com/watch?v=example',
                'Сезон' => 'Лето',
            ],
        ]);

        $this->assertSame(['Сезон' => 'Літо'], $product->displaySpecifications('uk'));
        $this->assertSame(['Сезон' => 'Лето'], $product->displaySpecifications('ru'));
    }
}
