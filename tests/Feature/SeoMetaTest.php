<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Services\SeoMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SeoMetaTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_outputs_one_complete_open_graph_and_twitter_card(): void
    {
        $response = $this->get('/')->assertOk();
        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, 'property="og:title"'));
        $this->assertSame('Trip&Fish', $this->property($html, 'og:title'));
        $this->assertSame('website', $this->property($html, 'og:type'));
        $this->assertSame('1200', $this->property($html, 'og:image:width'));
        $this->assertSame('630', $this->property($html, 'og:image:height'));
        $this->assertSame('uk_UA', $this->property($html, 'og:locale'));
        $this->assertSame('ru_RU', $this->property($html, 'og:locale:alternate'));
        $this->assertSame('summary_large_image', $this->metaName($html, 'twitter:card'));
        $this->assertSame('max-image-preview:large', $this->metaName($html, 'robots'));
        $this->assertSame($this->property($html, 'og:image'), $this->metaName($html, 'twitter:image'));
        $this->assertStringEndsWith('/images/social-default.jpg', $this->property($html, 'og:image'));
    }

    public function test_product_uses_real_content_canonical_url_price_and_social_image(): void
    {
        [, $product] = $this->product([
            'description' => '<p>Надійна <strong>котушка</strong> для риболовлі.</p>',
            'image_url' => '/images/product-placeholder.svg',
            'price' => 2450.5,
            'stock' => 4,
        ]);

        $response = $this->get($product->url())->assertOk();
        $html = $response->getContent();

        $this->assertSame($product->name, $this->property($html, 'og:title'));
        $this->assertSame('product', $this->property($html, 'og:type'));
        $this->assertSame($product->canonicalUrl(), $this->property($html, 'og:url'));
        $this->assertSame('2450.50', $this->property($html, 'product:price:amount'));
        $this->assertSame('UAH', $this->property($html, 'product:price:currency'));
        $this->assertSame('in stock', $this->property($html, 'product:availability'));
        $this->assertSame('Надійна котушка для риболовлі.', $this->property($html, 'og:description'));
        $this->assertStringContainsString(
            "/social-images/products/{$product->id}/",
            $this->property($html, 'og:image'),
        );
        $this->assertStringStartsWith('https://', $this->property($html, 'og:image:secure_url'));
    }

    public function test_product_sitemap_exposes_main_image_to_google(): void
    {
        [, $product] = $this->product([
            'image_path' => 'products/test/main.jpg',
            'image_url' => 'https://supplier.test/main.jpg',
        ]);

        $xml = $this->get(route('sitemap.products', 1))->assertOk()->streamedContent();

        $this->assertStringContainsString('xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"', $xml);
        $this->assertStringContainsString('<image:image><image:loc>', $xml);
        $this->assertStringContainsString('/storage/products/test/main.jpg</image:loc></image:image>', $xml);
        $this->assertStringContainsString($product->url(locale: 'uk'), $xml);
    }

    public function test_product_without_price_omits_price_tags_and_reports_out_of_stock(): void
    {
        [, $product] = $this->product(['price' => 0, 'stock' => 0]);
        $html = $this->get($product->url())->assertOk()->getContent();

        $this->assertNull($this->property($html, 'product:price:amount'));
        $this->assertNull($this->property($html, 'product:price:currency'));
        $this->assertSame('out of stock', $this->property($html, 'product:availability'));
    }

    public function test_category_and_russian_pages_use_correct_social_data_and_locales(): void
    {
        [$category] = $this->product();

        $categoryHtml = $this->get($category->catalogUrl())->assertOk()->getContent();
        $this->assertSame($category->seoTitle(), $this->property($categoryHtml, 'og:title'));
        $this->assertSame($category->catalogUrl(), $this->property($categoryHtml, 'og:url'));
        $this->assertStringContainsString(
            "/social-images/categories/{$category->id}/",
            $this->property($categoryHtml, 'og:image'),
        );

        $russianHtml = $this->get('/ru')->assertOk()->getContent();
        $this->assertSame('ru_RU', $this->property($russianHtml, 'og:locale'));
        $this->assertSame('uk_UA', $this->property($russianHtml, 'og:locale:alternate'));
    }

    public function test_generic_pages_receive_social_tags_and_article_builder_has_real_fields(): void
    {
        $loginHtml = $this->get(route('login'))->assertOk()->getContent();
        $this->assertNotNull($this->property($loginHtml, 'og:title'));
        $this->assertNotNull($this->metaName($loginHtml, 'twitter:description'));

        $article = app(SeoMeta::class)->article(
            title: 'Як обрати намет',
            description: '<p>Короткий анонс статті.</p>',
            url: 'https://example.com/articles/tent',
            publishedAt: '2026-06-01T10:00:00+03:00',
            modifiedAt: '2026-06-02T10:00:00+03:00',
            author: 'Trip&Fish',
        );

        $this->assertSame('article', $article['type']);
        $this->assertSame('Короткий анонс статті.', $article['description']);
        $this->assertSame('2026-06-01T10:00:00+03:00', $article['extra']['article:published_time']);
        $this->assertSame('Trip&Fish', $article['extra']['article:author']);
    }

    public function test_social_image_endpoint_returns_direct_cached_jpeg_and_security_headers_are_present(): void
    {
        [, $product] = $this->product(['image_url' => '/images/product-placeholder.svg']);
        $version = $product->updated_at->timestamp;
        $response = $this->get(route('social-images.product', [$product, 'version' => $version]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('max-age=31536000', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('Set-Cookie'));

        $image = getimagesize($response->baseResponse->getFile()->getPathname());
        $this->assertSame([1200, 630], [$image[0], $image[1]]);

        $this->get('https://localhost/')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin-allow-popups')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_social_image_endpoint_accepts_supplier_images_slightly_under_legacy_width_limit(): void
    {
        $sourcePath = public_path('images/social-test-583x500.jpg');
        File::ensureDirectoryExists(dirname($sourcePath));

        $source = imagecreatetruecolor(583, 500);
        $color = imagecolorallocate($source, 43, 91, 73);
        imagefill($source, 0, 0, $color);
        imagejpeg($source, $sourcePath, 90);
        imagedestroy($source);

        try {
            [, $product] = $this->product(['image_url' => '/images/social-test-583x500.jpg']);
            $version = $product->updated_at->timestamp;

            $response = $this->get(route('social-images.product', [$product, 'version' => $version]))
                ->assertOk()
                ->assertHeader('Content-Type', 'image/jpeg');

            $path = $response->baseResponse->getFile()->getPathname();

            $this->assertStringContainsString('framework/cache/social-images', $path);
            $this->assertFalse(str_ends_with($path, '/images/social-default.jpg'));

            $image = getimagesize($path);
            $this->assertSame([1200, 630], [$image[0], $image[1]]);
        } finally {
            File::delete($sourcePath);
        }
    }

    public function test_catalog_menu_is_not_embedded_in_every_page_and_loads_from_cached_endpoint(): void
    {
        $category = Category::create([
            'name' => 'Риболовля',
            'slug' => 'rybolovlia',
            'is_active' => true,
        ]);

        $homeHtml = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('<div class="menu-group">', $homeHtml);

        $response = $this->get(route('catalog.menu'))
            ->assertOk()
            ->assertSee($category->name);
        $this->assertStringContainsString('max-age=300', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('stale-while-revalidate=86400', $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    private function product(array $overrides = []): array
    {
        $category = Category::create([
            'name' => 'Котушки',
            'name_ru' => 'Катушки',
            'slug' => 'kotushky',
            'description' => '<p>Категорія котушок.</p>',
            'is_active' => true,
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Котушка тестова',
            'name_ru' => 'Катушка тестовая',
            'slug' => 'kotushka-testova',
            'sku' => '2000000123',
            'description' => 'Тестовий опис',
            'price' => 2000,
            'stock' => 3,
            'is_active' => true,
            ...$overrides,
        ]);

        return [$category, $product];
    }

    private function property(string $html, string $property): ?string
    {
        preg_match(
            '/<meta property="'.preg_quote($property, '/').'" content="([^"]*)">/',
            $html,
            $matches,
        );

        return isset($matches[1])
            ? html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5)
            : null;
    }

    private function metaName(string $html, string $name): ?string
    {
        preg_match(
            '/<meta name="'.preg_quote($name, '/').'" content="([^"]*)">/',
            $html,
            $matches,
        );

        return isset($matches[1])
            ? html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5)
            : null;
    }
}
