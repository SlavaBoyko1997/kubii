<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariantGroup;
use App\Models\Review;
use App\Support\FreeDelivery;
use App\Support\Locale;
use App\Support\PlainText;
use App\Support\StoreInfo;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SeoSchema
{
    public function home(): array
    {
        return $this->graph([
            $this->organization(),
            $this->website(),
        ]);
    }

    public function product(Product $product, iterable $reviews = []): array
    {
        $url = $product->canonicalUrl();
        $reviewSchemas = collect($reviews)
            ->filter(fn ($review): bool => $review instanceof Review && $review->is_visible)
            ->map(fn (Review $review): array => $this->review($review, $url))
            ->values()
            ->all();
        $schema = [
            '@type' => 'Product',
            '@id' => $url.'#product',
            'name' => $product->name,
            'image' => collect($product->gallery())->map(fn (string $image): string => $this->absoluteUrl($image))->all(),
            'description' => $this->description($product->description, $product->name),
            'sku' => $product->sku,
            'brand' => $product->brand ? [
                '@type' => 'Brand',
                'name' => $product->brand,
            ] : null,
            ...$this->gtin($product),
            ...$this->productStructuredAttributes($product),
            'category' => $product->category?->breadcrumbTrail()->pluck('name')->implode(' > '),
            'url' => $url,
            'offers' => $this->offer($product, includeSeller: true),
            'isVariantOf' => $this->productGroupReference($product),
            'aggregateRating' => $this->aggregateRating($product),
            'review' => $reviewSchemas,
        ];

        return $this->graph([
            $this->organization(),
            $this->productGroup($product),
            $schema,
            $this->breadcrumbs([
                ['name' => __('Головна'), 'url' => localized_route('home')],
                ...$product->category->breadcrumbTrail()
                    ->map(fn (Category $category): array => [
                        'name' => $category->name,
                        'url' => $category->catalogUrl(),
                    ])
                    ->all(),
                ['name' => $product->name, 'url' => $url],
            ], $url),
        ]);
    }

    public function category(Category $category, Paginator $products, string $url, ?string $heading = null): array
    {
        $name = $heading ?: $category->pageH1();
        $items = collect($products->items());
        $hasItems = $items->isNotEmpty();
        $faqSchema = $this->faqPage((array) $category->translated('seo_faq'), $url);

        return $this->graph([
            [
                '@type' => 'CollectionPage',
                '@id' => $url.'#webpage',
                'url' => $url,
                'name' => $name,
                'description' => $this->description($category->description, $category->metaDescription()),
                'image' => $this->absoluteUrl($category->imageUrl()),
                'inLanguage' => Locale::current(),
                'isPartOf' => ['@id' => $this->websiteId()],
                'mainEntity' => $hasItems ? ['@id' => $url.'#item-list'] : null,
            ],
            $hasItems ? $this->itemList($items, $url, $products->firstItem() ?: 1) : [],
            $faqSchema,
            $this->breadcrumbs([
                ['name' => __('Головна'), 'url' => localized_route('home')],
                ...$category->breadcrumbTrail()
                    ->map(fn (Category $crumb): array => [
                        'name' => $crumb->name,
                        'url' => $crumb->catalogUrl(),
                    ])
                    ->all(),
            ], $url),
        ]);
    }

    /**
     * @param  list<array{question?: string, answer?: string}>  $faq
     */
    private function faqPage(array $faq, string $url): array
    {
        $items = collect($faq)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'question' => trim(strip_tags((string) ($item['question'] ?? ''))),
                'answer' => trim(strip_tags((string) ($item['answer'] ?? ''))),
            ])
            ->filter(fn (array $item): bool => $item['question'] !== '' && $item['answer'] !== '')
            ->take(6)
            ->values();

        if ($items->count() < 3) {
            return [];
        }

        return [
            '@type' => 'FAQPage',
            '@id' => $url.'#faq',
            'mainEntity' => $items
                ->map(fn (array $item): array => [
                    '@type' => 'Question',
                    'name' => $item['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $item['answer'],
                    ],
                ])
                ->all(),
        ];
    }

    public function search(string $query, Collection $products, string $url): array
    {
        $title = $query !== ''
            ? __('Результати для «:query»', ['query' => $query])
            : __('Пошук товарів');

        return $this->graph([
            [
                '@type' => 'SearchResultsPage',
                '@id' => $url.'#webpage',
                'url' => $url,
                'name' => $title,
                'description' => $this->description(
                    $query !== '' ? __('Результати пошуку товарів за запитом «:query».', ['query' => $query]) : null,
                    $title,
                ),
                'inLanguage' => Locale::current(),
                'isPartOf' => ['@id' => $this->websiteId()],
                'mainEntity' => $products->isNotEmpty() ? ['@id' => $url.'#item-list'] : null,
            ],
            $products->isNotEmpty() ? $this->itemList($products, $url) : [],
            $this->breadcrumbs([
                ['name' => __('Головна'), 'url' => localized_route('home')],
                ['name' => $title, 'url' => $url],
            ], $url),
        ]);
    }

    public function page(string $title, string $content, string $url, string $type = 'WebPage'): array
    {
        return $this->graph([
            [
                '@type' => $type,
                '@id' => $url.'#webpage',
                'url' => $url,
                'name' => $title,
                'description' => $this->description($content, $title),
                'inLanguage' => Locale::current(),
                'isPartOf' => ['@id' => $this->websiteId()],
                'publisher' => ['@id' => $this->organizationId()],
            ],
            $this->breadcrumbs([
                ['name' => __('Головна'), 'url' => localized_route('home')],
                ['name' => $title, 'url' => $url],
            ], $url),
        ]);
    }

    public function article(
        string $title,
        string $content,
        string $url,
        ?string $image = null,
        ?string $publishedAt = null,
        ?string $modifiedAt = null,
    ): array {
        return $this->graph([
            [
                '@type' => 'Article',
                '@id' => $url.'#article',
                'headline' => $title,
                'description' => $this->description($content, $title),
                'image' => $image ? $this->absoluteUrl($image) : null,
                'url' => $url,
                'datePublished' => $publishedAt,
                'dateModified' => $modifiedAt,
                'inLanguage' => Locale::current(),
                'author' => ['@id' => $this->organizationId()],
                'publisher' => ['@id' => $this->organizationId()],
                'mainEntityOfPage' => ['@id' => $url.'#webpage'],
            ],
            $this->page($title, $content, $url)['@graph'][0],
            $this->breadcrumbs([
                ['name' => __('Головна'), 'url' => localized_route('home')],
                ['name' => $title, 'url' => $url],
            ], $url),
        ]);
    }

    public function blogPost(
        string $title,
        string $description,
        string $url,
        ?string $image = null,
        ?string $publishedAt = null,
        ?string $modifiedAt = null,
    ): array {
        return $this->graph([
            [
                '@type' => 'Article',
                '@id' => $url.'#article',
                'headline' => $title,
                'description' => $this->description($description, $title),
                'image' => $image ? $this->absoluteUrl($image) : null,
                'url' => $url,
                'datePublished' => $publishedAt,
                'dateModified' => $modifiedAt,
                'inLanguage' => Locale::current(),
                'author' => ['@id' => $this->organizationId()],
                'publisher' => ['@id' => $this->organizationId()],
                'mainEntityOfPage' => ['@id' => $url.'#webpage'],
            ],
            [
                '@type' => 'WebPage',
                '@id' => $url.'#webpage',
                'url' => $url,
                'name' => $title,
                'description' => $this->description($description, $title),
                'inLanguage' => Locale::current(),
                'isPartOf' => ['@id' => $this->websiteId()],
                'publisher' => ['@id' => $this->organizationId()],
            ],
            $this->breadcrumbs([
                ['name' => __('Головна'), 'url' => localized_route('home')],
                ['name' => __('Блог'), 'url' => localized_route('blog.index')],
                ['name' => $title, 'url' => $url],
            ], $url),
        ]);
    }

    public function graph(array $schemas): array
    {
        $seen = [];
        $graph = collect($schemas)
            ->filter(fn (mixed $schema): bool => is_array($schema))
            ->map(fn (array $schema): array => $this->clean($schema))
            ->filter()
            ->filter(function (array $schema) use (&$seen): bool {
                $key = $schema['@id'] ?? (($schema['@type'] ?? '').'|'.($schema['url'] ?? ''));

                if ($key === '|' || isset($seen[$key])) {
                    return false;
                }

                $seen[$key] = true;

                return true;
            })
            ->values()
            ->all();

        return [
            '@context' => 'https://schema.org',
            '@graph' => $graph,
        ];
    }

    private function organization(): array
    {
        return [
            '@type' => 'Organization',
            '@id' => $this->organizationId(),
            'name' => StoreInfo::name(),
            'legalName' => StoreInfo::legalName('uk'),
            'url' => url('/'),
            'logo' => [
                '@type' => 'ImageObject',
                'url' => asset('favicon.svg'),
            ],
            'email' => StoreInfo::email(),
            'telephone' => StoreInfo::phone(),
            'address' => [
                '@type' => 'PostalAddress',
                'addressCountry' => StoreInfo::addressCountry(),
                'addressLocality' => StoreInfo::locality('uk'),
                'streetAddress' => StoreInfo::streetAddress('uk'),
            ],
            'hasShippingService' => $this->shippingService(),
            'contactPoint' => [
                '@type' => 'ContactPoint',
                'contactType' => 'customer service',
                'email' => StoreInfo::email(),
                'telephone' => StoreInfo::phone(),
                'areaServed' => 'UA',
                'availableLanguage' => ['uk', 'ru'],
                'hoursAvailable' => $this->openingHoursSpecification(),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function openingHoursSpecification(): array
    {
        return collect(StoreInfo::openingHours())
            ->map(fn (array $hours): array => [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => $hours['days'] ?? [],
                'opens' => $hours['opens'] ?? null,
                'closes' => $hours['closes'] ?? null,
            ])
            ->all();
    }

    private function website(): array
    {
        $searchUrl = localized_route('search.index');

        return [
            '@type' => 'WebSite',
            '@id' => $this->websiteId(),
            'url' => localized_route('home'),
            'name' => StoreInfo::name(),
            'inLanguage' => Locale::current(),
            'publisher' => ['@id' => $this->organizationId()],
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $searchUrl.'?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    private function itemList(Collection $products, string $url, int $startPosition = 1): array
    {
        return [
            '@type' => 'ItemList',
            '@id' => $url.'#item-list',
            'numberOfItems' => $products->count(),
            'itemListElement' => $products
                ->values()
                ->map(fn (Product $product, int $index): array => [
                    '@type' => 'ListItem',
                    'position' => $startPosition + $index,
                    'url' => $product->url(),
                    'item' => [
                        '@type' => 'Product',
                        '@id' => $product->url().'#product',
                        'name' => $product->name,
                        'url' => $product->url(),
                        'image' => $this->absoluteUrl($product->imageUrl()),
                        'sku' => $product->sku,
                        'brand' => $product->brand ? [
                            '@type' => 'Brand',
                            'name' => $product->brand,
                        ] : null,
                        ...$this->gtin($product),
                        ...$this->productStructuredAttributes($product),
                        'offers' => $this->offer($product),
                        'aggregateRating' => $this->aggregateRating($product),
                    ],
                ])
                ->all(),
        ];
    }

    private function breadcrumbs(array $items, string $url): array
    {
        return [
            '@type' => 'BreadcrumbList',
            '@id' => $url.'#breadcrumb',
            'itemListElement' => collect($items)
                ->filter(fn (array $item): bool => filled($item['name'] ?? null) && filled($item['url'] ?? null))
                ->values()
                ->map(fn (array $item, int $index): array => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $this->plainText($item['name']),
                    'item' => $this->absoluteUrl($item['url']),
                ])
                ->all(),
        ];
    }

    private function review(Review $review, string $productUrl): array
    {
        return [
            '@type' => 'Review',
            '@id' => $productUrl.'#review-'.$review->id,
            'name' => $review->title,
            'reviewBody' => $this->description($review->body, $review->title),
            'datePublished' => $review->created_at?->toAtomString(),
            'author' => $review->user?->fullName() ? [
                '@type' => 'Person',
                'name' => $review->user->fullName(),
            ] : null,
            'reviewRating' => [
                '@type' => 'Rating',
                'ratingValue' => (int) $review->rating,
                'bestRating' => 5,
                'worstRating' => 1,
            ],
        ];
    }

    private function availability(Product $product): string
    {
        if ($product->is_active && $product->isPricePending()) {
            return 'https://schema.org/PreOrder';
        }

        return $product->isPurchasable()
            ? 'https://schema.org/InStock'
            : 'https://schema.org/OutOfStock';
    }

    private function offer(Product $product, bool $includeSeller = false): ?array
    {
        if ($product->isPricePending()) {
            return null;
        }

        return [
            '@type' => 'Offer',
            'url' => $product->url(),
            'price' => $product->salePrice(),
            'priceCurrency' => 'UAH',
            'priceSpecification' => $this->strikethroughPriceSpecification($product),
            'availability' => $this->availability($product),
            'itemCondition' => 'https://schema.org/NewCondition',
            'seller' => $includeSeller ? ['@id' => $this->organizationId()] : null,
            'shippingDetails' => $this->shippingDetails(),
            'hasMerchantReturnPolicy' => $this->merchantReturnPolicy(),
        ];
    }

    private function strikethroughPriceSpecification(Product $product): ?array
    {
        $regularPrice = round((float) $product->price, 2);
        $activePrice = round($product->salePrice(), 2);

        if ($regularPrice <= 0 || $regularPrice <= $activePrice) {
            return null;
        }

        return [
            '@type' => 'UnitPriceSpecification',
            'priceType' => 'https://schema.org/StrikethroughPrice',
            'price' => $regularPrice,
            'priceCurrency' => 'UAH',
        ];
    }

    private function shippingDetails(): array
    {
        return [
            '@type' => 'OfferShippingDetails',
            'shippingDestination' => [
                '@type' => 'DefinedRegion',
                'addressCountry' => 'UA',
            ],
            'hasShippingService' => ['@id' => $this->shippingPolicyId()],
            'deliveryTime' => [
                '@type' => 'ShippingDeliveryTime',
                'handlingTime' => [
                    '@type' => 'QuantitativeValue',
                    'minValue' => 0,
                    'maxValue' => 1,
                    'unitCode' => 'DAY',
                ],
                'transitTime' => [
                    '@type' => 'QuantitativeValue',
                    'minValue' => 1,
                    'maxValue' => 4,
                    'unitCode' => 'DAY',
                ],
            ],
        ];
    }

    private function shippingService(): ?array
    {
        $threshold = FreeDelivery::threshold();

        if ($threshold <= 0) {
            return null;
        }

        return [
            '@type' => 'ShippingService',
            '@id' => $this->shippingPolicyId(),
            'name' => FreeDelivery::promoLabel(),
            'fulfillmentType' => 'https://schema.org/FulfillmentTypeDelivery',
            'shippingConditions' => [
                '@type' => 'ShippingConditions',
                'shippingDestination' => [
                    '@type' => 'DefinedRegion',
                    'addressCountry' => 'UA',
                ],
                'orderValue' => [
                    '@type' => 'MonetaryAmount',
                    'minValue' => $threshold,
                    'currency' => 'UAH',
                ],
                'shippingRate' => [
                    '@type' => 'MonetaryAmount',
                    'value' => 0,
                    'currency' => 'UAH',
                ],
            ],
        ];
    }

    private function merchantReturnPolicy(): array
    {
        return [
            '@type' => 'MerchantReturnPolicy',
            'applicableCountry' => 'UA',
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays' => 14,
            'returnMethod' => 'https://schema.org/ReturnByMail',
            'returnFees' => 'https://schema.org/ReturnFeesCustomerResponsibility',
        ];
    }

    private function aggregateRating(Product $product): ?array
    {
        if (($product->visible_reviews_count ?? 0) > 0 && $product->visible_reviews_avg_rating) {
            return [
                '@type' => 'AggregateRating',
                'ratingValue' => round((float) $product->visible_reviews_avg_rating, 2),
                'reviewCount' => (int) $product->visible_reviews_count,
                'bestRating' => 5,
                'worstRating' => 1,
            ];
        }

        return null;
    }

    private function description(?string $value, ?string $fallback): string
    {
        return $this->plainText(filled($value) ? $value : ($fallback ?? ''));
    }

    private function productGroup(Product $product): ?array
    {
        $group = $this->visibleVariantGroup($product);

        if ($group === null) {
            return null;
        }

        $variesBy = $this->variantSchemaProperties($group);

        return [
            '@type' => 'ProductGroup',
            '@id' => $this->productGroupId($group),
            'productGroupID' => $group->group_key,
            'name' => $group->title,
            'brand' => $group->brand ? [
                '@type' => 'Brand',
                'name' => $group->brand,
            ] : null,
            'variesBy' => $variesBy,
            'hasVariant' => $group->products
                ->map(fn (Product $variant): array => ['@id' => $variant->url().'#product'])
                ->all(),
        ];
    }

    private function productGroupReference(Product $product): ?array
    {
        $group = $this->visibleVariantGroup($product);

        return $group ? ['@id' => $this->productGroupId($group)] : null;
    }

    private function visibleVariantGroup(Product $product): ?ProductVariantGroup
    {
        $group = $product->variantGroup;

        if (! $group?->isVisibleOnFrontend()) {
            return null;
        }

        if (! $group->relationLoaded('products')) {
            $group->load(['products' => fn ($query) => $query
                ->where('is_active', true)
                ->with('category.parentRecursive')
                ->orderByDesc('is_primary_variant')
                ->orderBy('price')
                ->orderBy('id')]);
        }

        return $group->products->count() > 1 ? $group : null;
    }

    private function productGroupId(ProductVariantGroup $group): string
    {
        return url('/').'#product-group-'.$group->group_key;
    }

    /**
     * @return list<string>
     */
    private function variantSchemaProperties(ProductVariantGroup $group): array
    {
        $mapping = $this->normalizedAttributeMap();
        $schemaProperties = config('seo.structured_data.variant_properties', []);

        return collect($group->variant_option_keys ?? [])
            ->map(fn (string $key): ?string => $mapping[$this->normalizeSchemaKey($key)] ?? null)
            ->filter()
            ->unique()
            ->map(fn (string $attribute): ?string => $schemaProperties[$attribute] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function productStructuredAttributes(Product $product): array
    {
        $attributes = [];

        foreach ($this->schemaAttributeKeys() as $schemaProperty => $sourceKeys) {
            $value = $this->firstProductDataValue($product, $sourceKeys);

            if ($value !== null) {
                $attributes[$schemaProperty] = $value;
            }
        }

        if (! isset($attributes['material']) && filled($product->material)) {
            $attributes['material'] = $this->plainText($product->material);
        }

        return $attributes;
    }

    /**
     * @return array<string, list<string>>
     */
    private function schemaAttributeKeys(): array
    {
        return collect(config('seo.structured_data.product_attributes', []))
            ->map(fn (array $keys): array => array_values(array_filter(array_map('strval', $keys))))
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function normalizedAttributeMap(): array
    {
        return collect($this->schemaAttributeKeys())
            ->flatMap(fn (array $keys, string $attribute): array => collect($keys)
                ->mapWithKeys(fn (string $key): array => [$this->normalizeSchemaKey($key) => $attribute])
                ->all())
            ->all();
    }

    /**
     * @param  list<string>  $sourceKeys
     */
    private function firstProductDataValue(Product $product, array $sourceKeys): ?string
    {
        $sources = [
            $product->localizedVariantOptions(),
            $product->localizedVariantSecondarySpecs(),
            $product->localizedSpecifications(),
        ];

        foreach ($sourceKeys as $sourceKey) {
            $normalizedSourceKey = $this->normalizeSchemaKey($sourceKey);

            foreach ($sources as $source) {
                foreach ($source as $key => $value) {
                    if ($this->normalizeSchemaKey((string) $key) !== $normalizedSourceKey) {
                        continue;
                    }

                    $formatted = $product->formatSpecificationValue($value);

                    if ($formatted !== '') {
                        return $formatted;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function gtin(Product $product): array
    {
        $value = $this->firstProductDataValue($product, $this->trustedGtinKeys());

        if ($value === null) {
            return [];
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (! in_array(strlen($digits), [8, 12, 13, 14], true) || ! $this->hasValidGtinCheckDigit($digits)) {
            return [];
        }

        return ['gtin'.strlen($digits) => $digits];
    }

    /**
     * @return list<string>
     */
    private function trustedGtinKeys(): array
    {
        return array_values(array_filter(array_map('strval', config('seo.structured_data.gtin_keys', []))));
    }

    private function hasValidGtinCheckDigit(string $digits): bool
    {
        $sum = 0;
        $reversed = strrev(substr($digits, 0, -1));

        foreach (str_split($reversed) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 3 : 1);
        }

        $checkDigit = (10 - ($sum % 10)) % 10;

        return $checkDigit === (int) substr($digits, -1);
    }

    private function normalizeSchemaKey(string $key): string
    {
        return Str::lower(trim($key));
    }

    private function plainText(mixed $value): string
    {
        return PlainText::fromHtml((string) $value) ?? '';
    }

    private function absoluteUrl(string $value): string
    {
        if (preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }

        return url('/'.ltrim($value, '/'));
    }

    private function clean(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = $this->clean($value);
            }

            if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                unset($data[$key]);
            } else {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    private function organizationId(): string
    {
        return url('/').'#organization';
    }

    private function shippingPolicyId(): string
    {
        return url('/').'#shipping-policy';
    }

    private function websiteId(): string
    {
        return localized_route('home').'#website';
    }
}
