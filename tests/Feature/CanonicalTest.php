<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_html_page_gets_one_absolute_canonical_without_tracking_query(): void
    {
        foreach (['/', '/pages/about', '/cart', '/login', '/search?q=tent&utm_source=test'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, 'rel="canonical"'), $url);
            $this->assertStringStartsWith('http://localhost', $this->link($html, 'canonical'));
            $this->assertStringNotContainsString('utm_source', $this->link($html, 'canonical'));
        }
    }

    public function test_catalog_sorting_and_page_size_do_not_change_canonical_but_page_does(): void
    {
        $category = Category::create(['name' => 'Намети', 'slug' => 'tents']);

        foreach (range(1, 22) as $index) {
            Product::create([
                'category_id' => $category->id,
                'name' => "Намет {$index}",
                'slug' => "tent-{$index}",
                'price' => 1000,
                'stock' => 1,
            ]);
        }

        $sorted = $this->get($category->catalogUrl([
            'sort' => 'price_asc',
            'per_page' => 40,
        ]))->assertOk()->getContent();
        $page = $this->get($category->catalogUrl(['page' => 2]))->assertOk()->getContent();

        $this->assertSame($category->catalogUrl(), $this->link($sorted, 'canonical'));
        $this->assertSame($category->catalogUrl(['page' => 2]), $this->link($page, 'canonical'));
    }

    public function test_variant_canonical_hreflang_open_graph_schema_and_sitemap_use_primary_product(): void
    {
        $category = Category::create(['name' => 'Котушки', 'slug' => 'reels']);
        $primary = Product::create([
            'category_id' => $category->id,
            'name' => 'Основна котушка',
            'slug' => 'primary-reel',
            'price' => 2000,
            'stock' => 2,
        ]);
        $variant = Product::create([
            'category_id' => $category->id,
            'name' => 'Варіант котушки',
            'slug' => 'variant-reel',
            'canonical_type' => 'custom',
            'canonical_product_id' => $primary->id,
            'price' => 2100,
            'stock' => 2,
        ]);

        $html = $this->get($variant->url())->assertOk()->getContent();

        $this->assertSame($primary->url(), $this->link($html, 'canonical'));
        $this->assertSame($primary->url(locale: 'uk'), $this->alternate($html, 'uk'));
        $this->assertSame($primary->url(locale: 'ru'), $this->alternate($html, 'ru'));
        $this->assertStringContainsString('property="og:url" content="'.$primary->url().'"', $html);
        $this->assertStringContainsString('"url":"'.$primary->url().'"', $html);

        $sitemap = $this->get('/sitemap-products-1.xml')->assertOk()->getContent();
        $this->assertStringContainsString($primary->url(), $sitemap);
        $this->assertStringNotContainsString($variant->url(), $sitemap);
    }

    private function link(string $html, string $rel): string
    {
        preg_match('/<link rel="'.preg_quote($rel, '/').'" href="([^"]+)"/', $html, $matches);

        return html_entity_decode($matches[1] ?? '');
    }

    private function alternate(string $html, string $locale): string
    {
        preg_match('/<link rel="alternate" hreflang="'.preg_quote($locale, '/').'" href="([^"]+)"/', $html, $matches);

        return html_entity_decode($matches[1] ?? '');
    }
}
