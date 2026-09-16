<?php

namespace App\Models;

use App\Support\CatalogCache;
use App\Support\Locale;
use App\Support\PlainText;
use App\Support\ProductColorVariants;
use App\Support\StoredAsset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Product extends Model
{
    protected $fillable = [
        'category_id',
        'source_feed_category_id',
        'variant_group_id',
        'source',
        'counterparty_id',
        'external_id',
        'variant_id',
        'external_url',
        'name',
        'name_ru',
        'slug',
        'slug_ru',
        'sku',
        'description',
        'description_ru',
        'content',
        'content_ru',
        'seo_title',
        'seo_title_ru',
        'meta_description',
        'meta_description_ru',
        'h1',
        'h1_ru',
        'is_indexable',
        'canonical_type',
        'canonical_product_id',
        'brand',
        'model',
        'season',
        'season_ru',
        'usage_type',
        'usage_type_ru',
        'material',
        'material_ru',
        'weight_grams',
        'specifications',
        'specifications_ru',
        'variant_options',
        'variant_options_ru',
        'variant_secondary_specs',
        'variant_secondary_specs_ru',
        'is_primary_variant',
        'price',
        'sale_price',
        'discount_percent',
        'image_url',
        'image_path',
        'mirrored_image_url',
        'mirrored_gallery_urls',
        'gallery_images',
        'gallery_paths',
        'content_images',
        'content_image_paths',
        'stock',
        'is_featured',
        'is_active',
        'is_visible_in_catalog',
        'is_processed',
        'reviews_count',
        'reviews_avg_rating',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'gallery_images' => 'array',
            'gallery_paths' => 'array',
            'mirrored_gallery_urls' => 'array',
            'content_images' => 'array',
            'content_image_paths' => 'array',
            'specifications' => 'array',
            'specifications_ru' => 'array',
            'variant_options' => 'array',
            'variant_options_ru' => 'array',
            'variant_secondary_specs' => 'array',
            'variant_secondary_specs_ru' => 'array',
            'is_featured' => 'boolean',
            'is_primary_variant' => 'boolean',
            'is_indexable' => 'boolean',
            'is_active' => 'boolean',
            'is_visible_in_catalog' => 'boolean',
            'is_processed' => 'boolean',
            'reviews_avg_rating' => 'decimal:2',
            'monthly_views' => 'integer',
        ];
    }

    public function getVisibleReviewsAvgRatingAttribute(): ?float
    {
        if (array_key_exists('visible_reviews_avg_rating', $this->attributes)) {
            return $this->attributes['visible_reviews_avg_rating'] !== null
                ? (float) $this->attributes['visible_reviews_avg_rating']
                : null;
        }

        return isset($this->attributes['reviews_avg_rating'])
            ? (float) $this->attributes['reviews_avg_rating']
            : null;
    }

    public function getVisibleReviewsCountAttribute(): int
    {
        if (array_key_exists('visible_reviews_count', $this->attributes)) {
            return (int) $this->attributes['visible_reviews_count'];
        }

        return (int) ($this->attributes['reviews_count'] ?? 0);
    }

    protected static function booted(): void
    {
        static::saving(function (self $product): void {
            if ($product->isDirty('slug')) {
                $product->slug_ru = $product->attributes['slug'] ?? null;
            }

            if ($product->isDirty(['variant_group_id', 'is_primary_variant'])) {
                $product->is_visible_in_catalog = $product->variant_group_id === null
                    || (bool) $product->is_primary_variant;
            }

            if ($product->is_active && ! $product->is_processed) {
                $product->is_processed = true;
            }
        });
        static::created(function (self $product): void {
            if (blank($product->sku)) {
                $product->updateQuietly(['sku' => self::siteSkuForId($product->id)]);
            }
        });
        static::saved(fn (): mixed => app(CatalogCache::class)->invalidate());
        static::deleted(fn (): mixed => app(CatalogCache::class)->invalidate());
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function sourceFeedCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'source_feed_category_id');
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    public function variantGroup(): BelongsTo
    {
        return $this->belongsTo(ProductVariantGroup::class, 'variant_group_id');
    }

    public function canonicalProduct(): BelongsTo
    {
        return $this->belongsTo(self::class, 'canonical_product_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function visibleReviews(): HasMany
    {
        return $this->reviews()->whereNull('parent_id')->where('is_visible', true);
    }

    public function scopeWithMonthlyViews(Builder $query): Builder
    {
        $monthlyViews = DB::table('product_views_daily')
            ->selectRaw('product_id, SUM(views) as monthly_views')
            ->where('viewed_on', '>=', today()->subDays(29)->toDateString())
            ->groupBy('product_id');

        if ($query->getQuery()->columns === null) {
            $query->select('products.*');
        }

        return $query
            ->leftJoinSub($monthlyViews, 'monthly_product_views', 'monthly_product_views.product_id', '=', 'products.id')
            ->selectRaw('COALESCE(monthly_product_views.monthly_views, 0) as monthly_views');
    }

    public function scopeOrderByMonthlyPopularity(Builder $query): Builder
    {
        return $query
            ->orderByDesc('products.monthly_views')
            ->orderByDesc('is_featured');
    }

    public function scopeVisibleInCatalog(Builder $query): Builder
    {
        return $query->where('is_visible_in_catalog', true);
    }

    public function scopePurchasable(Builder $query): Builder
    {
        return $query
            ->where('stock', '>', 0)
            ->whereRaw('COALESCE(sale_price, ROUND(price * (100 - discount_percent) / 100, 2)) > 0');
    }

    public function scopeOnSale(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('discount_percent', '>', 0)
                ->orWhere(function (Builder $query): void {
                    $query->whereNotNull('sale_price')
                        ->where('sale_price', '>', 0)
                        ->whereColumn('sale_price', '<', 'price');
                });
        });
    }

    public function scopeCatalogPlacementIssues(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->whereNull('category_id')
                ->orWhere('is_active', false)
                ->orWhereHas('category', fn (Builder $categoryQuery): Builder => $categoryQuery->where('is_active', false));
        });
    }

    public function filterUrl(string $attribute, mixed $value): string
    {
        $attribute = trim($attribute);
        $values = collect(is_array($value) ? $value : [$value])
            ->map(fn ($item): string => trim(strip_tags((string) $item)))
            ->filter()
            ->values()
            ->all();
        $firstValue = $values[0] ?? null;

        if ($attribute === 'Вага') {
            return $this->category->catalogUrl(['max_weight' => $this->weight_grams]);
        }

        $tokens = collect(match ($attribute) {
            'Виробник', 'Бренд' => [filled($firstValue) ? $firstValue : $this->brand],
            'Модель' => [filled($firstValue) ? $firstValue : $this->model],
            default => $values,
        })
            ->map(fn ($value): string => Str::slug((string) $value, '-', 'uk') ?: Str::slug((string) $value))
            ->filter()
            ->values()
            ->all();

        return $this->category->catalogUrl($tokens === [] ? [] : ['filter' => implode(';', $tokens)]);
    }

    public function rootCategory(): ?Category
    {
        return $this->category?->loadMissing('parentRecursive')->breadcrumbTrail()->first();
    }

    public function rootCategorySlug(): ?string
    {
        return $this->rootCategory()?->publicSlug();
    }

    public function url(bool $absolute = true, ?string $locale = null): string
    {
        $locale ??= Locale::current();
        $rootSlug = $this->rootCategory()?->publicSlug($locale) ?: 'products';
        $slug = $this->getRawOriginal('slug') ?: $this->attributes['slug'];
        $path = Locale::prefixPath('/'.$rootSlug.'/products/'.$slug, $locale);

        return $absolute ? url($path) : $path;
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $column = $field ?: $this->getRouteKeyName();
        $product = $this->where($column, $value)->first();

        if ($product || $column !== 'slug') {
            return $product;
        }

        $productId = DB::table('product_slug_redirects')->where('old_slug', $value)->value('product_id');

        return $productId ? $this->find($productId) : null;
    }

    public static function siteSkuForId(int $id): string
    {
        return (string) (2000000000 + $id);
    }

    public function salePrice(): float
    {
        return $this->sale_price !== null
            ? (float) $this->sale_price
            : round((float) $this->price * (100 - $this->discount_percent) / 100, 2);
    }

    public function isPricePending(): bool
    {
        return $this->salePrice() <= 0;
    }

    public function isPurchasable(): bool
    {
        return $this->is_active && $this->stock > 0 && ! $this->isPricePending();
    }

    public function gallery(): array
    {
        $main = $this->storedImageUrl($this->image_path) ?: $this->storedImageUrl($this->image_url);

        return collect([
            $main,
            ...$this->storedImageUrls($this->gallery_paths ?? []),
            ...$this->storedImageUrls($this->gallery_images ?? []),
        ])
            ->filter()
            ->unique()
            ->values()
            ->whenEmpty(fn ($images) => $images->push($this->imageUrl()))
            ->all();
    }

    public function contentImageUrls(): array
    {
        return collect([
            ...$this->storedImageUrls($this->content_image_paths ?? []),
            ...$this->storedImageUrls($this->content_images ?? []),
        ])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function imageUrl(): string
    {
        return $this->storedImageUrl($this->image_path)
            ?: ($this->storedImageUrl($this->image_url) ?: asset('images/product-placeholder.svg'));
    }

    public function storedImageUrl(?string $path): ?string
    {
        return StoredAsset::url($path);
    }

    /**
     * @param  array<int, string|null>|null  $paths
     * @return array<int, string>
     */
    public function storedImageUrls(?array $paths): array
    {
        return StoredAsset::urls($paths);
    }

    public function seoTitle(?string $locale = null): string
    {
        $locale ??= Locale::current();
        $suffix = $locale === 'ru' ? ' купить в Kubii' : ' купити в Kubii';

        return $this->translated('seo_title', $locale) ?: $this->translated('name', $locale).$suffix;
    }

    public function metaDescription(?string $locale = null): string
    {
        $locale ??= Locale::current();
        $fallback = $locale === 'ru'
            ? $this->translated('name', $locale).' - туристическое и рыболовное снаряжение Kubii.'
            : $this->translated('name', $locale).' - туристичне та рибальське спорядження Kubii.';

        return str($this->translated('meta_description', $locale) ?: $this->translated('description', $locale) ?: $fallback)
            ->limit(155)
            ->toString();
    }

    public function pageH1(?string $locale = null): string
    {
        return $this->translated('h1', $locale) ?: $this->translated('name', $locale);
    }

    public function canonicalUrl(?string $locale = null): string
    {
        if ($this->canonical_type === 'primary' && $this->variantGroup?->primaryProduct) {
            return $this->variantGroup->primaryProduct->url(locale: $locale);
        }

        if ($this->canonical_type === 'custom' && $this->canonicalProduct) {
            return $this->canonicalProduct->url(locale: $locale);
        }

        return $this->url(locale: $locale);
    }

    public function translated(string $field, ?string $locale = null): mixed
    {
        $locale ??= Locale::current();
        $primary = $this->attributes[$field] ?? null;
        $value = $locale === 'ru' ? ($this->attributes[$field.'_ru'] ?? null) : $primary;

        if ($value === null || $value === '') {
            $value = $primary;
        }

        if (in_array($field, ['specifications', 'variant_options', 'variant_secondary_specs'], true) && is_string($value)) {
            return json_decode($value, true) ?: [];
        }

        return $value;
    }

    public function localizedSpecifications(?string $locale = null): array
    {
        return (array) $this->translated('specifications', $locale);
    }

    /**
     * @return array<int, string>
     */
    public static function hiddenSpecificationKeys(): array
    {
        return [
            'Виробник у фіді',
            'Производитель в фиде',
            'Артикул постачальника',
            'Артикул поставщика',
            'Код товару',
            'Код товара',
            'Відео',
            'Видео',
        ];
    }

    public function displaySpecifications(?string $locale = null): array
    {
        return collect($this->localizedSpecifications($locale))
            ->except(static::hiddenSpecificationKeys())
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function orderedDisplaySpecifications(?string $locale = null): array
    {
        $locale ??= Locale::current();
        $specifications = collect($this->displaySpecifications($locale));
        $manufacturerKey = $locale === 'ru' ? 'Производитель' : 'Виробник';
        $modelKey = 'Модель';
        $prioritySpecifications = collect([
            $manufacturerKey => $specifications->get($manufacturerKey) ?: $this->brand,
            $modelKey => $specifications->get($modelKey) ?: $this->model,
        ])->filter(fn (mixed $value): bool => filled($value));

        return $prioritySpecifications
            ->merge($specifications->except($prioritySpecifications->keys()->all()))
            ->map(fn (mixed $value): string => $this->formatSpecificationValue($value))
            ->filter(fn (string $value): bool => $value !== '')
            ->all();
    }

    public function copyableDetailsText(?string $locale = null): string
    {
        $locale ??= Locale::current();
        $lines = [$this->pageH1($locale), ''];

        foreach ($this->orderedDisplaySpecifications($locale) as $name => $value) {
            $lines[] = $name.': '.$value;
        }

        return rtrim(implode("\n", $lines));
    }

    public function formatSpecificationValue(mixed $value): string
    {
        if (is_array($value)) {
            return collect($value)
                ->map(fn (mixed $item): string => $this->formatSpecificationValue($item))
                ->filter(fn (string $item): bool => $item !== '')
                ->implode(', ');
        }

        return trim(strip_tags((string) $value));
    }

    public function plainDescription(?string $locale = null): ?string
    {
        $locale ??= Locale::current();
        $value = $locale === 'ru'
            ? ($this->attributes['description_ru'] ?? $this->attributes['description'] ?? null)
            : ($this->attributes['description'] ?? null);

        return PlainText::fromHtml($value);
    }

    /**
     * @return array<int, string>
     */
    public function descriptionParagraphs(?string $locale = null): array
    {
        $locale ??= Locale::current();
        $value = $locale === 'ru'
            ? ($this->attributes['description_ru'] ?? $this->attributes['description'] ?? null)
            : ($this->attributes['description'] ?? null);

        return PlainText::paragraphs($value);
    }

    public function localizedVariantOptions(?string $locale = null): array
    {
        return (array) $this->translated('variant_options', $locale);
    }

    public function localizedVariantSecondarySpecs(?string $locale = null): array
    {
        return (array) $this->translated('variant_secondary_specs', $locale);
    }

    public function getNameAttribute(?string $value): ?string
    {
        return Locale::isRussian() ? ($this->attributes['name_ru'] ?? $value) : $value;
    }

    public function getSlugAttribute(?string $value): ?string
    {
        return $value;
    }

    public function getDescriptionAttribute(?string $value): ?string
    {
        return Locale::isRussian() ? ($this->attributes['description_ru'] ?? $value) : $value;
    }

    public function getContentAttribute(?string $value): ?string
    {
        return Locale::isRussian() ? ($this->attributes['content_ru'] ?? $value) : $value;
    }

    public function getSeasonAttribute(?string $value): ?string
    {
        return Locale::isRussian() ? ($this->attributes['season_ru'] ?? $value) : $value;
    }

    public function getUsageTypeAttribute(?string $value): ?string
    {
        return Locale::isRussian() ? ($this->attributes['usage_type_ru'] ?? $value) : $value;
    }

    public function getMaterialAttribute(?string $value): ?string
    {
        return Locale::isRussian() ? ($this->attributes['material_ru'] ?? $value) : $value;
    }

    public function getSpecificationsAttribute(mixed $value): array
    {
        return $this->localizedJsonAttribute('specifications', $value);
    }

    public function getVariantOptionsAttribute(mixed $value): array
    {
        return $this->localizedJsonAttribute('variant_options', $value);
    }

    public function getVariantSecondarySpecsAttribute(mixed $value): array
    {
        return $this->localizedJsonAttribute('variant_secondary_specs', $value);
    }

    private function localizedJsonAttribute(string $field, mixed $value): array
    {
        $localized = Locale::isRussian() ? ($this->attributes[$field.'_ru'] ?? null) : $value;
        $localized = $localized ?: $value;

        return is_array($localized) ? $localized : (json_decode((string) $localized, true) ?: []);
    }

    public function variantLabel(array $optionKeys): string
    {
        return collect($optionKeys)
            ->map(fn (string $key): ?string => data_get($this->variant_options, $key))
            ->filter()
            ->implode(' / ') ?: $this->name;
    }

    public function colorVariantSwatches(int $limit = 8): Collection
    {
        return app(ProductColorVariants::class)->swatches($this, $limit);
    }

    public function hasColorVariantSwatches(): bool
    {
        return $this->colorVariantSwatches()->isNotEmpty();
    }

    public function sizeVariantOptions(int $limit = 8): Collection
    {
        return app(ProductColorVariants::class)->chipOptions($this, $limit);
    }

    public function hasSizeVariantOptions(): bool
    {
        return $this->sizeVariantOptions()->isNotEmpty();
    }

    public function chipVariantOptions(int $limit = 8): Collection
    {
        return $this->sizeVariantOptions($limit);
    }

    public function hasChipVariantOptions(): bool
    {
        return $this->hasSizeVariantOptions();
    }

    public function hasCardVariantOptions(): bool
    {
        return $this->hasColorVariantSwatches() || $this->hasChipVariantOptions();
    }

    public function cardVariantOptionKey(): ?string
    {
        $service = app(ProductColorVariants::class);

        return $service->colorOptionKey($this) ?? $service->chipOptionKey($this);
    }

    public function cardVariantOptionLabel(): ?string
    {
        return app(ProductColorVariants::class)->translateOptionKey($this->cardVariantOptionKey());
    }

    public static function variantCountLabel(int $count): string
    {
        if (Locale::isRussian()) {
            $lastTwo = $count % 100;
            $last = $count % 10;
            $word = match (true) {
                $lastTwo >= 11 && $lastTwo <= 14 => 'вариантов',
                $last === 1 => 'вариант',
                $last >= 2 && $last <= 4 => 'варианта',
                default => 'вариантов',
            };

            return "{$count} {$word}";
        }

        $lastTwo = $count % 100;
        $last = $count % 10;

        $word = match (true) {
            $lastTwo >= 11 && $lastTwo <= 14 => 'варіантів',
            $last === 1 => 'варіант',
            $last >= 2 && $last <= 4 => 'варіанти',
            default => 'варіантів',
        };

        return $count.' '.$word;
    }
}
