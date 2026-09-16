<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Support\CatalogCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryPageSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_page_outputs_single_h1_and_seo_tags(): void
    {
        $parent = Category::create([
            'name' => 'Рибальство',
            'slug' => 'rybalstvo',
            'is_active' => true,
        ]);

        $category = Category::create([
            'name' => 'Вудилища',
            'slug' => 'vudylyshcha',
            'parent_id' => $parent->id,
            'h1' => 'Вудилища для риболовлі',
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Спінінг тестовий',
            'slug' => 'spinning-test',
            'price' => 1200,
            'stock' => 2,
            'is_active' => true,
            'is_processed' => true,
        ]);

        app(CatalogCache::class)->invalidate();

        $html = $this->get($category->catalogUrl())->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('<h1 data-catalog-heading>Вудилища для риболовлі</h1>', $html);
        $this->assertStringContainsString('<title>Рибальство Вудилища купити в Україні — Trip&amp;Fish</title>', $html);
        $this->assertStringContainsString(
            'name="description" content="Купуйте вудилища в інтернет-магазині Trip&amp;Fish.',
            $html,
        );
        $this->assertStringContainsString(
            'property="og:title" content="Рибальство Вудилища купити в Україні — Trip&amp;Fish"',
            $html,
        );
    }

    public function test_category_custom_seo_fields_override_fallbacks_on_page(): void
    {
        $category = Category::create([
            'name' => 'Намети',
            'slug' => 'namety',
            'seo_title' => 'Намети купити онлайн — Trip&Fish',
            'meta_description' => 'Кастомний опис категорії наметів.',
            'h1' => 'Намети для туризму та кемпінгу',
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Намет 2-місний',
            'slug' => 'tent-2p',
            'price' => 3200,
            'stock' => 1,
            'is_active' => true,
            'is_processed' => true,
        ]);

        app(CatalogCache::class)->invalidate();

        $html = $this->get($category->catalogUrl())->assertOk()->getContent();

        $this->assertStringContainsString('<title>Намети купити онлайн — Trip&amp;Fish</title>', $html);
        $this->assertStringContainsString(
            'name="description" content="Кастомний опис категорії наметів."',
            $html,
        );
        $this->assertStringContainsString('<h1 data-catalog-heading>Намети для туризму та кемпінгу</h1>', $html);
    }

    public function test_filtered_category_page_gets_dynamic_h1_and_title(): void
    {
        $category = Category::create([
            'name' => 'Спальні мішки',
            'slug' => 'spalni-mishky',
            'is_active' => true,
            'visible_filters' => ['brand', 'model', 'season', 'usage_type', 'material', 'price'],
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Спальний мішок Tramp зимовий',
            'slug' => 'sleeping-bag-tramp-winter',
            'brand' => 'Tramp',
            'season' => 'Зимові',
            'price' => 1500,
            'stock' => 3,
            'is_active' => true,
            'is_processed' => true,
        ]);

        app(CatalogCache::class)->invalidate();

        $html = $this->followingRedirects()
            ->get($category->catalogUrl(['brand' => ['Tramp'], 'season' => ['Зимові']]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<h1 data-catalog-heading>Спальні мішки бренд Tramp сезон Зимові</h1>', $html);
        $this->assertStringContainsString('<title>Спальні мішки бренд Tramp сезон Зимові купити в Україні — Trip&amp;Fish</title>', $html);
    }

    public function test_category_page_without_filters_keeps_static_h1_and_title(): void
    {
        $category = Category::create([
            'name' => 'Спальні мішки',
            'slug' => 'spalni-mishky-static',
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Спальний мішок звичайний',
            'slug' => 'sleeping-bag-plain',
            'price' => 900,
            'stock' => 5,
            'is_active' => true,
            'is_processed' => true,
        ]);

        app(CatalogCache::class)->invalidate();

        $html = $this->get($category->catalogUrl())->assertOk()->getContent();

        $this->assertStringContainsString('<h1 data-catalog-heading>Спальні мішки</h1>', $html);
        $this->assertStringContainsString('<title>Спальні мішки купити в Україні — Trip&amp;Fish</title>', $html);
    }

    public function test_clean_category_page_is_indexable_with_self_canonical(): void
    {
        $category = Category::create([
            'name' => 'Ліхтарі',
            'slug' => 'lihtari-ta-elektrozyvlennia',
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Ліхтар кемпінговий',
            'slug' => 'camping-lamp',
            'price' => 900,
            'stock' => 5,
            'is_active' => true,
            'is_processed' => true,
        ]);

        app(CatalogCache::class)->invalidate();

        $html = $this->get($category->catalogUrl())->assertOk()->getContent();

        $this->assertStringContainsString('name="robots" content="index, follow"', $html);
        $this->assertStringNotContainsString('name="robots" content="noindex, follow"', $html);
        $this->assertSame(rtrim($category->catalogUrl(), '/'), rtrim($this->link($html, 'canonical'), '/'));
    }

    public function test_filter_query_parameter_pages_are_noindex_with_clean_canonical(): void
    {
        $category = Category::create([
            'name' => 'Ліхтарі',
            'slug' => 'lihtari-filter-seo',
            'is_active' => true,
            'visible_filters' => ['brand'],
            'visible_spec_filters' => ['Діаметр', 'Термін служби', 'Дальність', 'Світловий потік', 'Колір'],
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Ліхтар 160 мм',
            'slug' => 'lamp-160mm',
            'brand' => 'Fenix',
            'specifications' => [
                'Діаметр' => '160 мм',
                'Термін служби' => '5 років',
                'Дальність' => '640 м',
                'Світловий потік' => '500 лм',
                'Колір' => 'white',
            ],
            'price' => 1200,
            'stock' => 3,
            'is_active' => true,
            'is_processed' => true,
        ]);

        app(CatalogCache::class)->invalidate();

        $html = $this->followingRedirects()
            ->get($category->catalogUrl(['filter' => '160-mm;5-rokiv;640-m;500-lm;fenix;white']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="robots" content="noindex, follow"', $html);
        $this->assertSame(rtrim($category->catalogUrl(), '/'), rtrim($this->link($html, 'canonical'), '/'));
        $this->assertStringNotContainsString('filter=', $this->link($html, 'canonical'));
    }

    public function test_path_based_filters_remain_indexable_without_filter_query_canonical(): void
    {
        $category = Category::create([
            'name' => 'Спальні мішки',
            'slug' => 'spalni-mishky-path-filter',
            'is_active' => true,
            'visible_filters' => ['brand', 'season'],
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Спальний мішок Tramp зимовий',
            'slug' => 'sleeping-bag-tramp-path',
            'brand' => 'Tramp',
            'season' => 'Зимові',
            'price' => 1500,
            'stock' => 3,
            'is_active' => true,
            'is_processed' => true,
        ]);

        app(CatalogCache::class)->invalidate();

        $html = $this->followingRedirects()
            ->get($category->catalogUrl(['brand' => ['Tramp'], 'season' => ['Зимові']]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="robots" content="index, follow"', $html);
        $this->assertStringNotContainsString('?filter=', $this->link($html, 'canonical'));
    }

    public function test_paginated_category_page_adds_page_number_to_seo_tags(): void
    {
        $category = Category::create([
            'name' => 'Мультитули',
            'slug' => 'multytuly-3',
            'seo_title' => 'Мультитули купити в Україні — ціни',
            'meta_description' => 'Мультитули для туризму та риболовлі.',
            'is_active' => true,
        ]);

        for ($i = 1; $i <= 21; $i++) {
            Product::create([
                'category_id' => $category->id,
                'name' => "Мультитул тестовий {$i}",
                'slug' => "multitool-test-{$i}",
                'price' => 1000 + $i,
                'stock' => 2,
                'is_active' => true,
                'is_processed' => true,
            ]);
        }

        app(CatalogCache::class)->invalidate();

        $html = $this->get($category->catalogUrl(['page' => 2]))->assertOk()->getContent();

        $this->assertStringContainsString('<title>Мультитули купити в Україні — ціни — Сторінка 2</title>', $html);
        $this->assertStringContainsString(
            'name="description" content="Мультитули для туризму та риболовлі. — Сторінка 2"',
            $html,
        );
        $this->assertStringContainsString(
            'property="og:title" content="Мультитули купити в Україні — ціни — Сторінка 2"',
            $html,
        );
        $this->assertStringContainsString('?page=2', $this->link($html, 'canonical'));
    }

    private function link(string $html, string $rel): string
    {
        preg_match('/<link rel="'.preg_quote($rel, '/').'" href="([^"]+)"/', $html, $matches);

        return html_entity_decode($matches[1] ?? '');
    }

    public function test_three_selected_brands_are_omitted_from_h1_to_avoid_spam(): void
    {
        $category = Category::create([
            'name' => 'Спальні мішки',
            'slug' => 'spalni-mishky-brands',
            'is_active' => true,
        ]);

        foreach (['Tramp', 'Ranger', 'Norfin'] as $index => $brand) {
            Product::create([
                'category_id' => $category->id,
                'name' => "Спальний мішок {$brand}",
                'slug' => 'sleeping-bag-'.$index,
                'brand' => $brand,
                'price' => 1000 + $index,
                'stock' => 2,
                'is_active' => true,
                'is_processed' => true,
            ]);
        }

        app(CatalogCache::class)->invalidate();

        $html = $this->followingRedirects()
            ->get($category->catalogUrl(['brand' => ['Tramp', 'Ranger', 'Norfin']]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<h1 data-catalog-heading>Спальні мішки</h1>', $html);
    }

    public function test_h1_includes_model_and_stock_flag_but_not_price_range(): void
    {
        $category = Category::create([
            'name' => 'Спальні мішки',
            'slug' => 'spalni-mishky-model',
            'is_active' => true,
            'visible_filters' => ['brand', 'model', 'price', 'stock'],
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Спальний мішок Tramp T-500',
            'slug' => 'sleeping-bag-tramp-t500',
            'brand' => 'Tramp',
            'model' => 'T-500',
            'price' => 1500,
            'stock' => 3,
            'is_active' => true,
            'is_processed' => true,
        ]);

        app(CatalogCache::class)->invalidate();

        $html = $this->followingRedirects()
            ->get($category->catalogUrl(['brand' => ['Tramp'], 'model' => ['T-500'], 'in_stock' => 1, 'min_price' => 1000]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<h1 data-catalog-heading>Спальні мішки бренд Tramp модель T-500 в наявності</h1>', $html);
    }
}
