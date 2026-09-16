<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariantGroup;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_outputs_organization_website_and_search_action_once(): void
    {
        $response = $this->get('/')->assertOk();
        $schema = $this->schema($response->getContent());

        $this->assertSame(1, substr_count($response->getContent(), 'type="application/ld+json"'));
        $this->assertNotNull($this->node($schema, 'Organization'));
        $website = $this->node($schema, 'WebSite');
        $this->assertSame('SearchAction', $website['potentialAction']['@type']);
        $this->assertStringContainsString('{search_term_string}', $website['potentialAction']['target']['urlTemplate']);
    }

    public function test_product_schema_uses_real_review_data_and_clean_absolute_values(): void
    {
        [$category, $product] = $this->product([
            'description' => '<p>Надійна <strong>котушка</strong>.</p>',
            'image_url' => '/images/reel.jpg',
        ]);
        $user = User::factory()->create();
        Review::create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'rating' => 5,
            'title' => 'Чудова покупка',
            'body' => '<p>Працює відмінно.</p>',
            'is_visible' => true,
        ]);

        $response = $this->get($product->url())->assertOk();
        $schema = $this->schema($response->getContent());
        $node = $this->node($schema, 'Product');
        $organization = $this->node($schema, 'Organization');
        $shippingPolicyId = url('/').'#shipping-policy';

        $this->assertSame(1, substr_count($response->getContent(), 'type="application/ld+json"'));
        $this->assertSame($product->sku, $node['sku']);
        $this->assertSame('Надійна котушка.', $node['description']);
        $this->assertSame('https://schema.org/InStock', $node['offers']['availability']);
        $this->assertSame('OfferShippingDetails', $node['offers']['shippingDetails']['@type']);
        $this->assertArrayNotHasKey('shippingRate', $node['offers']['shippingDetails']);
        $this->assertSame('UA', $node['offers']['shippingDetails']['shippingDestination']['addressCountry']);
        $this->assertSame(['@id' => $shippingPolicyId], $node['offers']['shippingDetails']['hasShippingService']);
        $this->assertSame('ShippingService', $organization['hasShippingService']['@type']);
        $this->assertSame($shippingPolicyId, $organization['hasShippingService']['@id']);
        $this->assertSame(3000, $organization['hasShippingService']['shippingConditions']['orderValue']['minValue']);
        $this->assertSame(0, $organization['hasShippingService']['shippingConditions']['shippingRate']['value']);
        $this->assertSame('MerchantReturnPolicy', $node['offers']['hasMerchantReturnPolicy']['@type']);
        $this->assertSame(14, $node['offers']['hasMerchantReturnPolicy']['merchantReturnDays']);
        $this->assertMatchesRegularExpression('#^https?://#', $node['image'][0]);
        $this->assertStringEndsWith('/images/reel.jpg', $node['image'][0]);
        $this->assertSame(5, $node['aggregateRating']['ratingValue']);
        $this->assertSame(1, $node['aggregateRating']['reviewCount']);
        $this->assertSame('Працює відмінно.', $node['review'][0]['reviewBody']);

        $breadcrumb = $this->node($schema, 'BreadcrumbList');
        $this->assertSame([1, 2, 3], array_column($breadcrumb['itemListElement'], 'position'));
        $this->assertSame($category->catalogUrl(), $breadcrumb['itemListElement'][1]['item']);
    }

    public function test_product_schema_omits_aggregate_rating_when_no_reviews_exist(): void
    {
        [, $product] = $this->product();
        $node = $this->node($this->schema($this->get($product->url())->assertOk()->getContent()), 'Product');

        $this->assertArrayNotHasKey('aggregateRating', $node);
        $this->assertArrayNotHasKey('review', $node);
    }

    public function test_product_schema_does_not_publish_a_fake_offer_when_price_is_pending(): void
    {
        [, $product] = $this->product(['price' => 0]);
        $node = $this->node($this->schema($this->get($product->url())->assertOk()->getContent()), 'Product');

        $this->assertArrayNotHasKey('offers', $node);
    }

    public function test_product_schema_outputs_strikethrough_price_for_sale_products(): void
    {
        [, $product] = $this->product([
            'price' => 320,
            'sale_price' => 96,
            'discount_percent' => 70,
        ]);

        $node = $this->node($this->schema($this->get($product->url())->assertOk()->getContent()), 'Product');

        $this->assertEqualsWithDelta(96.0, $node['offers']['price'], 0.01);
        $this->assertSame('UnitPriceSpecification', $node['offers']['priceSpecification']['@type']);
        $this->assertSame('https://schema.org/StrikethroughPrice', $node['offers']['priceSpecification']['priceType']);
        $this->assertEqualsWithDelta(320.0, $node['offers']['priceSpecification']['price'], 0.01);
        $this->assertSame('UAH', $node['offers']['priceSpecification']['priceCurrency']);
    }

    public function test_product_schema_outputs_structured_product_attributes_and_valid_gtin_only(): void
    {
        [$category, $product] = $this->product([
            'variant_options' => ['Розмір' => 'S', 'Колір товару' => 'Birch'],
            'specifications' => ['Матеріал' => '100% Бавовна', 'EAN' => '4006381333931'],
        ]);

        $node = $this->node($this->schema($this->get($product->url())->assertOk()->getContent()), 'Product');

        $this->assertSame('S', $node['size']);
        $this->assertSame('Birch', $node['color']);
        $this->assertSame('100% Бавовна', $node['material']);
        $this->assertSame('4006381333931', $node['gtin13']);

        $invalidGtinProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'Котушка з невалідним EAN',
            'slug' => 'kotushka-invalid-gtin',
            'sku' => '2000000124',
            'brand' => 'Shimano',
            'description' => 'Тестовий опис',
            'specifications' => ['EAN' => '4006381333932'],
            'price' => 2000,
            'stock' => 3,
        ]);

        $invalidNode = $this->node($this->schema($this->get($invalidGtinProduct->url())->assertOk()->getContent()), 'Product');

        $this->assertArrayNotHasKey('gtin13', $invalidNode);
    }

    public function test_product_schema_outputs_product_group_for_real_variants(): void
    {
        [$category, $product] = $this->product([
            'name' => 'Футболка Bavovna Birch, S',
            'brand' => 'Bavovna',
            'variant_options' => ['Розмір' => 'S'],
        ]);
        $sibling = Product::create([
            'category_id' => $category->id,
            'name' => 'Футболка Bavovna Birch, M',
            'slug' => 'futbolka-bavovna-birch-m',
            'sku' => '2000000125',
            'brand' => 'Bavovna',
            'description' => 'Тестовий опис',
            'variant_options' => ['Розмір' => 'M'],
            'price' => 2100,
            'stock' => 0,
            'is_active' => true,
        ]);
        $group = ProductVariantGroup::create([
            'category_id' => $category->id,
            'primary_product_id' => $product->id,
            'brand' => 'Bavovna',
            'title' => 'Футболка Bavovna Birch',
            'group_key' => 'bavovna-birch',
            'variant_option_keys' => ['Розмір'],
            'status' => 'approved',
        ]);
        $product->update(['variant_group_id' => $group->id, 'is_primary_variant' => true]);
        $sibling->update(['variant_group_id' => $group->id]);

        $schema = $this->schema($this->get($product->fresh()->url())->assertOk()->getContent());
        $node = $this->node($schema, 'Product');
        $productGroup = $this->node($schema, 'ProductGroup');

        $this->assertSame(url('/').'#product-group-bavovna-birch', $node['isVariantOf']['@id']);
        $this->assertSame('bavovna-birch', $productGroup['productGroupID']);
        $this->assertSame('Футболка Bavovna Birch', $productGroup['name']);
        $this->assertSame(['https://schema.org/size'], $productGroup['variesBy']);
        $this->assertCount(2, $productGroup['hasVariant']);
    }

    public function test_category_and_search_output_item_lists_with_absolute_product_urls(): void
    {
        [$category, $product] = $this->product();

        $categorySchema = $this->schema($this->get($category->catalogUrl())->assertOk()->getContent());
        $collection = $this->node($categorySchema, 'CollectionPage');
        $categoryItems = $this->node($categorySchema, 'ItemList');

        $this->assertSame(parse_url($category->catalogUrl(), PHP_URL_PATH), parse_url($collection['url'], PHP_URL_PATH));
        $this->assertSame(1, $categoryItems['itemListElement'][0]['position']);
        $this->assertSame($product->url(), $categoryItems['itemListElement'][0]['url']);
        $this->assertSame('UAH', $categoryItems['itemListElement'][0]['item']['offers']['priceCurrency']);

        $searchSchema = $this->schema($this->get(route('search.index', ['q' => $product->sku]))->assertOk()->getContent());
        $this->assertNotNull($this->node($searchSchema, 'SearchResultsPage'));
        $searchItems = $this->node($searchSchema, 'ItemList');
        $this->assertSame($product->url(), $searchItems['itemListElement'][0]['item']['url']);
        $this->assertNotNull($this->node($searchSchema, 'BreadcrumbList'));
    }

    public function test_content_page_outputs_webpage_and_breadcrumb_schema(): void
    {
        $schema = $this->schema($this->get(route('pages.show', 'about'))->assertOk()->getContent());

        $this->assertNotNull($this->node($schema, 'AboutPage'));
        $breadcrumb = $this->node($schema, 'BreadcrumbList');
        $this->assertSame([1, 2], array_column($breadcrumb['itemListElement'], 'position'));
    }

    private function product(array $overrides = []): array
    {
        $category = Category::create([
            'name' => 'Котушки',
            'slug' => 'kotushky',
            'description' => '<p>Категорія котушок.</p>',
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Котушка тестова',
            'slug' => 'kotushka-testova',
            'sku' => '2000000123',
            'brand' => 'Shimano',
            'description' => 'Тестовий опис',
            'price' => 2000,
            'stock' => 3,
            ...$overrides,
        ]);

        return [$category, $product];
    }

    private function schema(string $html): array
    {
        preg_match('/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/s', $html, $matches);

        $this->assertNotEmpty($matches[1] ?? null, 'JSON-LD script was not found.');

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }

    private function node(array $schema, string $type): ?array
    {
        return collect($schema['@graph'] ?? [])->first(fn (array $node): bool => ($node['@type'] ?? null) === $type);
    }
}
