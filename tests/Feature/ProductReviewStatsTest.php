<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\CatalogCache;
use App\Support\ProductReviewStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProductReviewStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_stats_are_denormalized_on_product(): void
    {
        $category = Category::create(['name' => 'Спінінги', 'slug' => 'spinning-stats']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Спінінг Pro',
            'slug' => 'spinning-pro',
            'price' => 1500,
            'stock' => 3,
        ]);

        ProductReviewStats::syncForProduct($product->id);
        $product->refresh();

        $this->assertSame(0, $product->reviews_count);
        $this->assertNull($product->reviews_avg_rating);

        $user = User::create([
            'name' => 'Reviewer',
            'email' => 'reviewer@example.com',
            'password' => 'password123',
        ]);

        $product->reviews()->create([
            'user_id' => $user->id,
            'rating' => 4,
            'body' => 'Гарний спінінг для початківців.',
            'is_visible' => true,
        ]);
        $product->reviews()->create([
            'user_id' => $user->id,
            'rating' => 5,
            'body' => 'Відмінна якість.',
            'is_visible' => true,
        ]);

        $product->refresh();

        $this->assertSame(2, $product->reviews_count);
        $this->assertSame(4.5, (float) $product->reviews_avg_rating);
        $this->assertSame(2, $product->visible_reviews_count);
        $this->assertSame(4.5, $product->visible_reviews_avg_rating);
    }

    public function test_category_showcase_is_cached_with_image_src(): void
    {
        $parent = Category::create([
            'name' => 'Showcase батько',
            'slug' => 'showcase-parent',
            'is_active' => true,
        ]);
        $child = Category::create([
            'name' => 'Showcase дитина',
            'slug' => 'showcase-child',
            'parent_id' => $parent->id,
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $child->id,
            'name' => 'Showcase товар',
            'slug' => 'showcase-product',
            'price' => 900,
            'stock' => 2,
            'is_active' => true,
            'image_url' => '/storage/showcase-product.jpg',
        ]);

        Cache::forever('catalog:version', 'showcase-version');

        $cache = app(CatalogCache::class);
        $showcase = $cache->categoryShowcase($parent->id);

        $this->assertNotEmpty($showcase);
        $childCard = collect($showcase)->firstWhere('id', $child->id);
        $this->assertNotNull($childCard);
        $this->assertNotEmpty($childCard['image_src'] ?? null);

        Category::query()->whereKey($child->id)->update(['name' => 'Змінена дитина']);

        $this->assertSame($showcase, $cache->categoryShowcase($parent->id));
        $this->assertSame('Showcase дитина', collect($cache->categoryShowcase($parent->id))->firstWhere('id', $child->id)['name'] ?? null);
    }
}
