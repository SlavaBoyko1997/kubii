<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariantGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductColorVariantsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Cache::flush();
    }

    public function test_catalog_card_shows_numeric_option_chips(): void
    {
        $category = Category::create(['name' => 'Спінінги', 'slug' => 'spinning-length-options']);
        $group = ProductVariantGroup::create([
            'category_id' => $category->id,
            'title' => 'Favorite Spin Length',
            'group_key' => 'favorite-spin-length-options',
            'grouping_level' => 'exact_model',
            'confidence' => 90,
            'variant_option_keys' => ['Довжина'],
            'status' => 'approved',
        ]);

        foreach ([
            ['2.40', 'favorite-spin-240', 2],
            ['2.10', 'favorite-spin-210', 1],
            ['3.00', 'favorite-spin-300', 0],
        ] as [$length, $slug, $stock]) {
            Product::create([
                'category_id' => $category->id,
                'variant_group_id' => $group->id,
                'name' => 'Спінінг Favorite '.$length,
                'slug' => $slug,
                'price' => 2100,
                'stock' => $stock,
                'variant_options' => ['Довжина' => $length],
                'is_primary_variant' => $length === '2.40',
            ]);
        }

        $group->update(['primary_product_id' => Product::query()->where('slug', 'favorite-spin-240')->value('id')]);

        $product = Product::query()->with('variantGroup.products')->where('slug', 'favorite-spin-240')->firstOrFail();
        $options = app(\App\Support\ProductColorVariants::class)->chipOptions($product);

        $this->assertSame(['2.10', '2.40', '3.00'], $options->pluck('label')->all());

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertSee('product-size-options is-compact is-responsive', false)
            ->assertSee('class="product-card-variant-label">Довжина</span>', false)
            ->assertSee('>2.40</span>', false)
            ->assertDontSee('class="card-variant-note"', false);
    }

    public function test_size_options_are_sorted_ascending(): void
    {
        $category = Category::create(['name' => 'Взуття', 'slug' => 'shoes-size-order']);
        $group = ProductVariantGroup::create([
            'category_id' => $category->id,
            'title' => 'Boot Size',
            'group_key' => 'boot-size-order',
            'grouping_level' => 'exact_model',
            'confidence' => 90,
            'variant_option_keys' => ['Розмір взуття'],
            'status' => 'approved',
        ]);

        foreach ([
            ['42', 'boot-size-42', 2],
            ['38', 'boot-size-38', 1],
            ['40', 'boot-size-40', 0],
        ] as [$size, $slug, $stock]) {
            Product::create([
                'category_id' => $category->id,
                'variant_group_id' => $group->id,
                'name' => 'Черевики '.$size,
                'slug' => $slug,
                'price' => 2400,
                'stock' => $stock,
                'variant_options' => ['Розмір взуття' => $size],
                'is_primary_variant' => $size === '42',
            ]);
        }

        $group->update(['primary_product_id' => Product::query()->where('slug', 'boot-size-42')->value('id')]);

        $product = Product::query()->with('variantGroup.products')->where('slug', 'boot-size-42')->firstOrFail();
        $options = app(\App\Support\ProductColorVariants::class)->sizeOptions($product);

        $this->assertSame(['38', '42', '40'], $options->pluck('label')->all());
        $this->assertTrue($options->firstWhere('label', '38')['is_available']);
        $this->assertTrue($options->firstWhere('label', '42')['is_available']);
        $this->assertFalse($options->firstWhere('label', '40')['is_available']);

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertSee('is-unavailable', false);
    }

    public function test_catalog_card_shows_size_options_for_size_grouped_variants(): void
    {
        $category = Category::create(['name' => 'Одяг', 'slug' => 'clothes-size-swatches']);
        $group = ProductVariantGroup::create([
            'category_id' => $category->id,
            'title' => 'Jacket Size',
            'group_key' => 'jacket-size-swatches',
            'grouping_level' => 'exact_model',
            'confidence' => 90,
            'variant_option_keys' => ['Розмір'],
            'status' => 'approved',
        ]);

        foreach (['S', 'M', 'L'] as $index => $size) {
            Product::create([
                'category_id' => $category->id,
                'variant_group_id' => $group->id,
                'name' => 'Куртка '.$size,
                'slug' => 'jacket-size-'.mb_strtolower($size),
                'price' => 3200,
                'stock' => 2,
                'variant_options' => ['Розмір' => $size],
                'is_primary_variant' => $index === 1,
            ]);
        }

        $group->update(['primary_product_id' => Product::query()->where('slug', 'jacket-size-m')->value('id')]);

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertSee('product-size-options is-compact is-responsive', false)
            ->assertSee('class="product-card-variant-label">Розмір</span>', false)
            ->assertSee('>S</span>', false)
            ->assertSee('>M</span>', false)
            ->assertSee('>L</span>', false)
            ->assertDontSee('class="card-variant-note"', false);
    }

    public function test_catalog_card_shows_international_size_options_like_regular_size(): void
    {
        $category = Category::create(['name' => 'Футболки', 'slug' => 'intl-size-swatches', 'is_active' => true]);
        $group = ProductVariantGroup::create([
            'category_id' => $category->id,
            'title' => 'Pobedov Shirt',
            'group_key' => 'intl-size-swatches',
            'grouping_level' => 'feed_group_id',
            'confidence' => 92,
            'variant_option_keys' => [],
            'status' => 'approved',
        ]);

        foreach ([
            ['L', 'shirt-intl-l', 2],
            ['XL', 'shirt-intl-xl', 3],
            ['XXXL', 'shirt-intl-xxxl', 1],
        ] as [$size, $slug, $stock]) {
            Product::create([
                'category_id' => $category->id,
                'variant_group_id' => $group->id,
                'name' => 'Футболка '.$size,
                'slug' => $slug,
                'price' => 650,
                'stock' => $stock,
                'is_active' => true,
                'is_primary_variant' => $size === 'XL',
                'is_visible_in_catalog' => $size === 'XL',
                'specifications' => ['Международный размер' => $size],
            ]);
        }

        $group->update(['primary_product_id' => Product::query()->where('slug', 'shirt-intl-xl')->value('id')]);

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertSee('product-size-options is-compact is-responsive', false)
            ->assertSee('class="product-card-variant-label">Міжнародний розмір</span>', false)
            ->assertSee('>L</span>', false)
            ->assertSee('>XL</span>', false)
            ->assertSee('>XXXL</span>', false);
    }

    public function test_catalog_card_shows_translated_variant_label_in_russian(): void
    {
        $category = Category::create([
            'name' => 'Спінінги',
            'name_ru' => 'Спиннинги',
            'slug' => 'spinning-color-ru-label',
        ]);
        $group = ProductVariantGroup::create([
            'category_id' => $category->id,
            'title' => 'Favorite Spin Color',
            'group_key' => 'favorite-spin-color-ru-label',
            'grouping_level' => 'exact_model',
            'confidence' => 90,
            'variant_option_keys' => ['Колір'],
            'status' => 'approved',
        ]);

        $red = Product::create([
            'category_id' => $category->id,
            'variant_group_id' => $group->id,
            'name' => 'Спінінг Favorite червоний',
            'name_ru' => 'Спиннинг Favorite красный',
            'slug' => 'favorite-spin-red-ru-label',
            'price' => 1800,
            'stock' => 2,
            'image_url' => 'https://cdn.example.test/red-ru.jpg',
            'variant_options' => ['Колір' => 'Червоний'],
            'is_primary_variant' => true,
        ]);
        Product::create([
            'category_id' => $category->id,
            'variant_group_id' => $group->id,
            'name' => 'Спінінг Favorite синій',
            'name_ru' => 'Спиннинг Favorite синий',
            'slug' => 'favorite-spin-blue-ru-label',
            'price' => 1800,
            'stock' => 2,
            'image_url' => 'https://cdn.example.test/blue-ru.jpg',
            'variant_options' => ['Колір' => 'Синій'],
        ]);

        $group->update(['primary_product_id' => $red->id]);

        $this->get($category->catalogUrl(locale: 'ru'))
            ->assertOk()
            ->assertSee('class="product-card-variant-label">Цвет</span>', false);
    }

    public function test_in_stock_color_swatches_are_sorted_first(): void
    {
        $category = Category::create(['name' => 'Спінінги', 'slug' => 'spinning-color-stock-order']);
        $group = ProductVariantGroup::create([
            'category_id' => $category->id,
            'title' => 'Favorite Spin Color Stock',
            'group_key' => 'favorite-spin-color-stock-order',
            'grouping_level' => 'exact_model',
            'confidence' => 90,
            'variant_option_keys' => ['Колір'],
            'status' => 'approved',
        ]);

        Product::create([
            'category_id' => $category->id,
            'variant_group_id' => $group->id,
            'name' => 'Спінінг Favorite червоний',
            'slug' => 'favorite-spin-red-stock-order',
            'price' => 1800,
            'stock' => 0,
            'image_url' => 'https://cdn.example.test/red-oos.jpg',
            'variant_options' => ['Колір' => 'Червоний'],
            'is_primary_variant' => true,
        ]);
        Product::create([
            'category_id' => $category->id,
            'variant_group_id' => $group->id,
            'name' => 'Спінінг Favorite синій',
            'slug' => 'favorite-spin-blue-stock-order',
            'price' => 1800,
            'stock' => 4,
            'image_url' => 'https://cdn.example.test/blue-in-stock.jpg',
            'variant_options' => ['Колір' => 'Синій'],
        ]);
        Product::create([
            'category_id' => $category->id,
            'variant_group_id' => $group->id,
            'name' => 'Спінінг Favorite зелений',
            'slug' => 'favorite-spin-green-stock-order',
            'price' => 1800,
            'stock' => 2,
            'image_url' => 'https://cdn.example.test/green-in-stock.jpg',
            'variant_options' => ['Колір' => 'Зелений'],
        ]);

        $group->update(['primary_product_id' => Product::query()->where('slug', 'favorite-spin-red-stock-order')->value('id')]);

        $product = Product::query()->with('variantGroup.products')->where('slug', 'favorite-spin-red-stock-order')->firstOrFail();
        $swatches = app(\App\Support\ProductColorVariants::class)->swatches($product);

        $this->assertSame('https://cdn.example.test/blue-in-stock.jpg', $swatches[0]['image']);
        $this->assertSame('https://cdn.example.test/green-in-stock.jpg', $swatches[1]['image']);
        $this->assertSame('https://cdn.example.test/red-oos.jpg', $swatches[2]['image']);
    }

    public function test_color_swatches_helper_groups_variants_by_color(): void
    {
        $category = Category::create(['name' => 'Спінінги', 'slug' => 'spinning-color-helper']);
        $group = ProductVariantGroup::create([
            'category_id' => $category->id,
            'title' => 'Favorite Spin Color',
            'group_key' => 'favorite-spin-color-helper',
            'grouping_level' => 'color',
            'confidence' => 90,
            'variant_option_keys' => ['Колір'],
            'status' => 'approved',
        ]);

        $red = Product::create([
            'category_id' => $category->id,
            'variant_group_id' => $group->id,
            'name' => 'Спінінг Favorite червоний',
            'slug' => 'favorite-spin-red-helper',
            'price' => 1800,
            'stock' => 2,
            'image_url' => 'https://cdn.example.test/red.jpg',
            'variant_options' => ['Колір' => 'Червоний'],
            'is_primary_variant' => true,
        ]);
        Product::create([
            'category_id' => $category->id,
            'variant_group_id' => $group->id,
            'name' => 'Спінінг Favorite синій',
            'slug' => 'favorite-spin-blue-helper',
            'price' => 1800,
            'stock' => 2,
            'image_url' => 'https://cdn.example.test/blue.jpg',
            'variant_options' => ['Колір' => 'Синій'],
        ]);

        $group->update(['primary_product_id' => $red->id]);

        $product = Product::query()
            ->with([
                'category.parentRecursive',
                'variantGroup.products' => fn ($query) => $query->where('is_active', true),
            ])
            ->findOrFail($red->id);

        $swatches = app(\App\Support\ProductColorVariants::class)->swatches($product);

        $this->assertCount(2, $swatches);
        $this->assertSame('https://cdn.example.test/red.jpg', $swatches->firstWhere('is_current', true)['image']);
        $this->assertSame('https://cdn.example.test/blue.jpg', $swatches->firstWhere('is_current', false)['image']);
    }

    public function test_color_swatches_read_color_from_specifications_when_variant_options_missing(): void
    {
        $category = Category::create(['name' => 'Спінінги', 'slug' => 'spinning-color-specs']);
        $group = ProductVariantGroup::create([
            'category_id' => $category->id,
            'title' => 'Favorite Spin Color Specs',
            'group_key' => 'favorite-spin-color-specs',
            'grouping_level' => 'exact_model',
            'confidence' => 90,
            'variant_option_keys' => ['Колір товару'],
            'status' => 'approved',
        ]);

        $red = Product::create([
            'category_id' => $category->id,
            'variant_group_id' => $group->id,
            'name' => 'Спінінг Favorite червоний',
            'slug' => 'favorite-spin-red-specs',
            'price' => 1800,
            'stock' => 2,
            'image_url' => 'https://cdn.example.test/red-spec.jpg',
            'specifications' => ['Колір товару' => 'Червоний'],
            'is_primary_variant' => true,
        ]);
        Product::create([
            'category_id' => $category->id,
            'variant_group_id' => $group->id,
            'name' => 'Спінінг Favorite синій',
            'slug' => 'favorite-spin-blue-specs',
            'price' => 1800,
            'stock' => 2,
            'image_url' => 'https://cdn.example.test/blue-spec.jpg',
            'specifications' => ['Колір товару' => 'Синій'],
        ]);

        $group->update(['primary_product_id' => $red->id]);

        $product = Product::query()->with('variantGroup.products')->findOrFail($red->id);

        $this->assertTrue(app(\App\Support\ProductColorVariants::class)->hasSwatches($product));
    }

    public function test_catalog_card_shows_color_swatches_for_color_grouped_variants(): void
    {
        $category = Category::create(['name' => 'Спінінги', 'slug' => 'spinning-color-swatches']);
        $group = ProductVariantGroup::create([
            'category_id' => $category->id,
            'title' => 'Favorite Spin Color',
            'group_key' => 'favorite-spin-color',
            'grouping_level' => 'color',
            'confidence' => 90,
            'variant_option_keys' => ['Колір'],
            'status' => 'approved',
        ]);

        $red = Product::create([
            'category_id' => $category->id,
            'variant_group_id' => $group->id,
            'name' => 'Спінінг Favorite червоний',
            'slug' => 'favorite-spin-red',
            'price' => 1800,
            'stock' => 2,
            'image_url' => 'https://cdn.example.test/red.jpg',
            'variant_options' => ['Колір' => 'Червоний'],
            'is_primary_variant' => true,
        ]);
        Product::create([
            'category_id' => $category->id,
            'variant_group_id' => $group->id,
            'name' => 'Спінінг Favorite синій',
            'slug' => 'favorite-spin-blue',
            'price' => 1800,
            'stock' => 2,
            'image_url' => 'https://cdn.example.test/blue.jpg',
            'variant_options' => ['Колір' => 'Синій'],
        ]);

        $group->update(['primary_product_id' => $red->id]);

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertSee('class="product-color-swatches', false)
            ->assertSee('class="product-card-variant-label">Колір</span>', false)
            ->assertSee('https://cdn.example.test/red.jpg', false)
            ->assertSee('https://cdn.example.test/blue.jpg', false)
            ->assertDontSee('class="card-variant-note"', false)
            ->assertDontSee('class="variant-count-label"', false);
    }

    public function test_catalog_card_limits_responsive_color_swatches(): void
    {
        $category = Category::create(['name' => 'Спінінги', 'slug' => 'spinning-color-responsive']);
        $group = ProductVariantGroup::create([
            'category_id' => $category->id,
            'title' => 'Favorite Spin Color Responsive',
            'group_key' => 'favorite-spin-color-responsive',
            'grouping_level' => 'exact_model',
            'confidence' => 90,
            'variant_option_keys' => ['Колір'],
            'status' => 'approved',
        ]);

        $colors = ['Червоний', 'Синій', 'Зелений', 'Жовтий', 'Чорний', 'Білий'];
        $primary = null;

        foreach ($colors as $index => $color) {
            $product = Product::create([
                'category_id' => $category->id,
                'variant_group_id' => $group->id,
                'name' => 'Спінінг Favorite '.$color,
                'slug' => 'favorite-spin-'.($index + 1),
                'price' => 1800,
                'stock' => 2,
                'image_url' => 'https://cdn.example.test/color-'.($index + 1).'.jpg',
                'variant_options' => ['Колір' => $color],
                'is_primary_variant' => $index === 0,
            ]);

            $primary ??= $product;
        }

        $group->update(['primary_product_id' => $primary->id]);

        $this->get($category->catalogUrl())
            ->assertOk()
            ->assertSee('product-color-swatches is-compact is-responsive', false)
            ->assertSee('class="product-color-swatch-more is-desktop-only">+1</span>', false)
            ->assertSee('class="product-color-swatch-more is-mobile-only">+3</span>', false);
    }

    public function test_product_page_shows_color_swatches_instead_of_text_chips(): void
    {
        $category = Category::create(['name' => 'Котушки', 'slug' => 'reels-color-swatches']);
        $group = ProductVariantGroup::create([
            'category_id' => $category->id,
            'title' => 'Shimano Reel Color',
            'group_key' => 'shimano-reel-color',
            'grouping_level' => 'color',
            'confidence' => 90,
            'variant_option_keys' => ['Колір товару'],
            'status' => 'approved',
        ]);

        $black = Product::create([
            'category_id' => $category->id,
            'variant_group_id' => $group->id,
            'name' => 'Котушка Shimano чорна',
            'slug' => 'shimano-reel-black',
            'price' => 3200,
            'stock' => 1,
            'image_url' => 'https://cdn.example.test/black.jpg',
            'variant_options' => ['Колір товару' => 'Чорний'],
            'is_primary_variant' => true,
        ]);
        Product::create([
            'category_id' => $category->id,
            'variant_group_id' => $group->id,
            'name' => 'Котушка Shimano срібна',
            'slug' => 'shimano-reel-silver',
            'price' => 3200,
            'stock' => 1,
            'image_url' => 'https://cdn.example.test/silver.jpg',
            'variant_options' => ['Колір товару' => 'Срібний'],
        ]);

        $group->update(['primary_product_id' => $black->id]);

        $this->get($black->url())
            ->assertOk()
            ->assertSee('variant-option-values is-color-swatches', false)
            ->assertSee('https://cdn.example.test/black.jpg', false)
            ->assertSee('https://cdn.example.test/silver.jpg', false)
            ->assertDontSee('class="variant-chip', false);
    }
}
