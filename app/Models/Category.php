<?php

namespace App\Models;

use App\Support\CatalogCache;
use App\Support\Locale;
use App\Support\StoredAsset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Category extends Model
{
    use SoftDeletes;

    public const MAX_INDEXABLE_FILTER_TOKENS = 3;

    protected ?string $resolvedProductFallbackImageUrl = null;

    protected bool $resolvedProductFallbackImageUrlLoaded = false;

    protected $fillable = [
        'counterparty_id',
        'target_category_id',
        'external_id',
        'name',
        'name_ru',
        'parent_id',
        'slug',
        'slug_ru',
        'image_url',
        'image_path',
        'seo_title',
        'seo_title_ru',
        'meta_description',
        'meta_description_ru',
        'h1',
        'h1_ru',
        'description',
        'description_ru',
        'seo_intro',
        'seo_intro_ru',
        'seo_faq',
        'seo_faq_ru',
        'ai_seo_generated_at',
        'sort_order',
        'visible_filters',
        'visible_spec_filters',
        'visible_spec_filters_ru',
        'filter_labels',
        'filter_labels_ru',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'visible_filters' => 'array',
            'visible_spec_filters' => 'array',
            'visible_spec_filters_ru' => 'array',
            'filter_labels' => 'array',
            'filter_labels_ru' => 'array',
            'seo_faq' => 'array',
            'seo_faq_ru' => 'array',
            'ai_seo_generated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $category): void {
            if ($category->isDirty('slug')) {
                $category->slug_ru = $category->attributes['slug'] ?? null;
            }
        });
        static::saved(function (self $category): void {
            if ($category->wasChanged('is_active') && $category->is_active) {
                $category->activateDescendants();
            }
        });
        static::saved(fn (): mixed => app(CatalogCache::class)->invalidate());
        static::deleted(fn (): mixed => app(CatalogCache::class)->invalidate());
    }

    public function resolvedVisibleFilters(): array
    {
        /** @var list<string>|null $filters */
        $filters = $this->visible_filters;

        if ($filters === null || $filters === []) {
            $this->loadMissing('parentRecursive');

            for ($parent = $this->parentRecursive; $parent; $parent = $parent->parentRecursive) {
                $parentFilters = $parent->visible_filters;

                if (is_array($parentFilters) && $parentFilters !== []) {
                    $filters = $parentFilters;

                    break;
                }
            }
        }

        if ($filters === null || $filters === []) {
            return ['brand', 'model', 'price'];
        }

        return array_values($filters);
    }

    public function resolvedVisibleSpecFilters(): ?array
    {
        $filters = Locale::isRussian() ? $this->visible_spec_filters_ru : $this->visible_spec_filters;

        if ($filters !== null) {
            return $filters;
        }

        $this->loadMissing('parentRecursive');

        for ($parent = $this->parentRecursive; $parent; $parent = $parent->parentRecursive) {
            $parentFilters = Locale::isRussian() ? $parent->visible_spec_filters_ru : $parent->visible_spec_filters;

            if ($parentFilters !== null) {
                return $parentFilters;
            }
        }

        return null;
    }

    public function resolvedFilterLabels(?bool $russian = null): array
    {
        $russian ??= Locale::isRussian();
        $labels = [];

        $this->loadMissing('parentRecursive');

        $parents = [];

        for ($parent = $this->parentRecursive; $parent; $parent = $parent->parentRecursive) {
            $parents[] = $parent;
        }

        foreach (array_reverse($parents) as $parent) {
            $labels = [...$labels, ...$this->localizedFilterLabels($parent, $russian)];
        }

        return [...$labels, ...$this->localizedFilterLabels($this, $russian)];
    }

    public function filterLabel(string $key, ?string $default = null, ?bool $russian = null): string
    {
        $key = trim($key);
        $label = $this->resolvedFilterLabels($russian)[$key] ?? null;

        return filled($label) ? $label : ($default ?? $key);
    }

    private function localizedFilterLabels(self $category, bool $russian): array
    {
        $labels = $russian ? $category->filter_labels_ru : $category->filter_labels;

        return collect((array) $labels)
            ->mapWithKeys(fn ($label, $key): array => [trim((string) $key) => trim((string) $label)])
            ->filter(fn (string $label, string $key): bool => $key !== '' && $label !== '')
            ->all();
    }

    public function activateDescendants(): int
    {
        $this->loadMissing('childrenRecursiveAll');

        $ids = $this->allDescendantIds();

        if ($ids === []) {
            return 0;
        }

        return static::query()
            ->whereIn('id', $ids)
            ->where('is_active', false)
            ->update([
                'is_active' => true,
                'updated_at' => now(),
            ]);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeVisibleInAdminTree(Builder $query): Builder
    {
        return $query->whereNull('target_category_id');
    }

    public function scopeMergeSource(Builder $query): Builder
    {
        return $query
            ->whereNotNull('target_category_id')
            ->whereNull('counterparty_id');
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    public function targetCategory(): BelongsTo
    {
        return $this->belongsTo(self::class, 'target_category_id');
    }

    public function sourceFeedProducts(): HasMany
    {
        return $this->hasMany(Product::class, 'source_feed_category_id');
    }

    public function isFeedCategory(): bool
    {
        return $this->counterparty_id !== null && filled($this->external_id);
    }

    public function paymentOptions(): BelongsToMany
    {
        return $this->belongsToMany(PaymentOption::class)->orderBy('payment_options.sort_order');
    }

    public function restrictedPaymentCodes(): array
    {
        return $this->paymentOptions->pluck('code')->all();
    }

    public function imageUrl(): string
    {
        if (filled($this->image_path)) {
            return StoredAsset::url($this->image_path) ?: asset('images/hero-outdoor.webp');
        }

        if (filled($this->image_url)) {
            return StoredAsset::url($this->image_url) ?: $this->resolvePublicAsset($this->image_url);
        }

        return $this->productFallbackImageUrl() ?: asset('images/hero-outdoor.webp');
    }

    private function productFallbackImageUrl(): ?string
    {
        if ($this->resolvedProductFallbackImageUrlLoaded) {
            return $this->resolvedProductFallbackImageUrl;
        }

        $this->resolvedProductFallbackImageUrlLoaded = true;

        $this->loadMissing('childrenRecursive');

        $product = Product::query()
            ->whereIn('category_id', [$this->id, ...$this->descendantIds()])
            ->where('is_active', true)
            ->visibleInCatalog()
            ->where(function ($query): void {
                $query->where(function ($query): void {
                    $query->whereNotNull('image_path')->where('image_path', '!=', '');
                })->orWhere(function ($query): void {
                    $query->whereNotNull('image_url')->where('image_url', '!=', '');
                });
            })
            ->orderByRaw('category_id = ? desc', [$this->id])
            ->orderByDesc('is_featured')
            ->orderByDesc('stock')
            ->orderBy('id')
            ->first(['image_path', 'image_url']);

        $this->resolvedProductFallbackImageUrl = $product
            ? ($product->storedImageUrl($product->image_path)
                ?: $product->storedImageUrl($product->image_url)
                ?: (filled($product->image_url) ? $this->resolvePublicAsset($product->image_url) : null))
            : null;

        return $this->resolvedProductFallbackImageUrl;
    }

    private function resolvePublicAsset(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || str_starts_with($url, '//')) {
            return $url;
        }

        return asset(ltrim($url, '/'));
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function parentRecursive(): BelongsTo
    {
        return $this->parent()->with('parentRecursive');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function activeChildren(): HasMany
    {
        return $this->children()->where('is_active', true);
    }

    public function childrenRecursive(): HasMany
    {
        return $this->activeChildren()->with('childrenRecursive');
    }

    public function childrenRecursiveAll(): HasMany
    {
        return $this->children()->with('childrenRecursiveAll');
    }

    public function descendantIds(): array
    {
        return $this->childrenRecursive->flatMap(fn (self $category): array => [
            $category->id,
            ...$category->descendantIds(),
        ])->all();
    }

    public function allDescendantIds(): array
    {
        return $this->childrenRecursiveAll->flatMap(fn (self $category): array => [
            $category->id,
            ...$category->allDescendantIds(),
        ])->all();
    }

    public function breadcrumbTrail(): Collection
    {
        return $this->parentRecursive
            ? $this->parentRecursive->breadcrumbTrail()->push($this)
            : collect([$this]);
    }

    public function catalogPath(): string
    {
        return $this->catalogPathFor(Locale::current());
    }

    public function catalogPathFor(string $locale): string
    {
        $segments = $this->publicPathSegments($locale);

        if ($segments->count() <= 1) {
            return '/'.$segments->implode('/');
        }

        return '/'.collect([$segments->first(), $segments->last()])->implode('/');
    }

    public function fullCatalogPath(): string
    {
        return $this->fullCatalogPathFor(Locale::current());
    }

    public function fullCatalogPathFor(string $locale): string
    {
        return '/'.$this->publicPathSegments($locale)->implode('/');
    }

    public function publicPathSegments(?string $locale = null): Collection
    {
        $locale ??= Locale::current();
        $fullPrefix = '';

        return $this->breadcrumbTrail()
            ->map(function (self $category) use (&$fullPrefix, $locale): string {
                $slug = $category->publicSlug($locale);
                $segment = $fullPrefix !== '' && str_starts_with($slug, $fullPrefix.'-')
                    ? substr($slug, strlen($fullPrefix) + 1)
                    : $slug;
                $fullPrefix = $slug;

                return $segment ?: $slug;
            });
    }

    public function publicSlug(?string $locale = null): string
    {
        $slug = $this->getRawOriginal('slug') ?: $this->attributes['slug'];

        return preg_replace('/-[a-f0-9]{10}$/i', '', $slug) ?: $slug;
    }

    public function catalogUrl(array $query = [], bool $absolute = true, ?string $locale = null): string
    {
        $locale ??= Locale::current();
        $path = Locale::prefixPath($this->catalogPathFor($locale), $locale);
        $filter = (string) ($query['filter'] ?? '');

        if ($filter !== '') {
            $tokens = collect(explode(';', $filter))
                ->map(fn (string $token): string => trim($token))
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();

            if (count($tokens) > 0 && count($tokens) <= self::MAX_INDEXABLE_FILTER_TOKENS) {
                $path .= '/search/'.implode('/', $tokens);
                unset($query['filter']);
            } elseif ($tokens !== []) {
                $query['filter'] = implode(';', $tokens);
            }
        }

        $url = $absolute ? url($path) : $path;

        if (! str_ends_with($url, '/')) {
            $url .= '/';
        }

        if ($query === []) {
            return $url;
        }

        $parts = [];

        foreach ($query as $key => $value) {
            $parts[] = $key === 'filter'
                ? 'filter='.(string) $value
                : http_build_query([$key => $value]);
        }

        $parts = array_filter($parts);

        return $parts === [] ? $url : $url.'?'.implode('&', $parts);
    }

    public function translated(string $field, ?string $locale = null): mixed
    {
        $locale ??= Locale::current();
        $primary = $this->attributes[$field] ?? null;

        if ($field === 'slug') {
            return $primary;
        }

        if ($locale === 'ru') {
            return $this->attributes[$field.'_ru'] ?? $primary;
        }

        return $primary;
    }

    public function getNameAttribute(?string $value): ?string
    {
        return Locale::isRussian() ? ($this->attributes['name_ru'] ?? $value) : $value;
    }

    public function getSlugAttribute(?string $value): ?string
    {
        return $value;
    }

    public function seoTitle(?string $locale = null): string
    {
        $locale ??= Locale::current();
        $custom = trim((string) $this->translated('seo_title', $locale));
        $name = trim((string) $this->translated('name', $locale));

        if ($custom !== '' && mb_strtolower($custom) !== mb_strtolower($name)) {
            return $custom;
        }

        return $this->generatedSeoTitle($locale);
    }

    private function generatedSeoTitle(?string $locale = null): string
    {
        $locale ??= Locale::current();
        $name = trim((string) $this->translated('name', $locale));
        $titlePrefix = $name;

        if ($this->parent_id !== null) {
            $this->loadMissing('parent');
            $parentName = trim((string) ($this->parent?->translated('name', $locale) ?? ''));

            if ($parentName !== '') {
                $titlePrefix = "{$parentName} {$name}";
            }
        }

        return $locale === 'ru'
            ? "{$titlePrefix} купить в Украине — Kubii"
            : "{$titlePrefix} купити в Україні — Kubii";
    }

    public function metaDescription(?string $locale = null): string
    {
        $locale ??= Locale::current();
        $custom = trim(strip_tags((string) ($this->translated('meta_description', $locale) ?: '')));

        if ($custom !== '') {
            return str($custom)->limit(155)->toString();
        }

        $description = trim(strip_tags((string) ($this->translated('description', $locale) ?: '')));

        if ($description !== '') {
            return str($description)->limit(155)->toString();
        }

        $name = mb_strtolower(trim((string) $this->translated('name', $locale)));
        $fallback = $locale === 'ru'
            ? "Покупайте {$name} в интернет-магазине Kubii. Товары для рыболовли, туризма и кемпинга с доставкой по Украине. Удобный выбор, актуальные цены и качественное снаряжение."
            : "Купуйте {$name} в інтернет-магазині Kubii. Товари для риболовлі, туризму та кемпінгу з доставкою по Україні. Зручний вибір, актуальні ціни та якісне спорядження.";

        return str($fallback)->limit(155)->toString();
    }

    public function pageH1(?string $locale = null): string
    {
        $locale ??= Locale::current();
        $custom = trim((string) $this->translated('h1', $locale));

        return $custom !== '' ? $custom : trim((string) $this->translated('name', $locale));
    }

    /**
     * H1 for a filtered catalog page, e.g. "Спальні мішки Tramp Зимові".
     * Falls back to the plain pageH1() when no filter suffix is given.
     * Intentionally built from the category name (not a custom h1
     * override), since the suffix already disambiguates the page.
     */
    public function filteredPageH1(string $filterSuffix, ?string $locale = null): string
    {
        $filterSuffix = trim($filterSuffix);

        if ($filterSuffix === '') {
            return $this->pageH1($locale);
        }

        $locale ??= Locale::current();
        $name = trim((string) $this->translated('name', $locale));

        return trim("{$name} {$filterSuffix}");
    }

    /**
     * SEO title for a filtered catalog page. A custom seo_title is not
     * reused here (editorial text can't account for arbitrary filter
     * combinations), so the title is always generated from the category
     * name plus the filter suffix.
     */
    public function filteredSeoTitle(string $filterSuffix, ?string $locale = null): string
    {
        $filterSuffix = trim($filterSuffix);

        if ($filterSuffix === '') {
            return $this->seoTitle($locale);
        }

        $locale ??= Locale::current();
        $name = trim((string) $this->translated('name', $locale));
        $titlePrefix = trim("{$name} {$filterSuffix}");

        return $locale === 'ru'
            ? "{$titlePrefix} купить в Украине — Kubii"
            : "{$titlePrefix} купити в Україні — Kubii";
    }

    public function filteredMetaDescription(string $filterSuffix, ?string $locale = null): string
    {
        $filterSuffix = trim($filterSuffix);

        if ($filterSuffix === '') {
            return $this->metaDescription($locale);
        }

        $locale ??= Locale::current();
        $name = mb_strtolower(trim((string) $this->translated('name', $locale)));
        $suffix = mb_strtolower($filterSuffix);
        $fallback = $locale === 'ru'
            ? "Покупайте {$name} {$suffix} в интернет-магазине Kubii. Товары для рыболовли, туризма и кемпинга с доставкой по Украине. Удобный выбор, актуальные цены и качественное снаряжение."
            : "Купуйте {$name} {$suffix} в інтернет-магазині Kubii. Товари для риболовлі, туризму та кемпінгу з доставкою по Україні. Зручний вибір, актуальні ціни та якісне спорядження.";

        return str($fallback)->limit(155)->toString();
    }
}
