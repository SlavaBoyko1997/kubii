<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductVariantGroup;
use Illuminate\Support\Collection;

class ProductColorVariants
{
    public const COLOR_OPTION_KEYS = [
        'Колір товару',
        'Колір',
    ];

    public const SIZE_OPTION_KEYS = [
        'Розмір',
        'Розмір взуття',
        'Міжнародний розмір',
        'Международный размер',
    ];

    private const OPTION_KEY_LABELS = [
        'Международный размер' => 'Міжнародний розмір',
    ];

    /** @deprecated Use COLOR_OPTION_KEYS */
    public const OPTION_KEYS = self::COLOR_OPTION_KEYS;

    private const LETTER_SIZE_ORDER = [
        'XXS' => 1,
        'XS' => 2,
        'S' => 3,
        'M' => 4,
        'L' => 5,
        'XL' => 6,
        'XXL' => 7,
        'XXXL' => 8,
        '4XL' => 9,
        '5XL' => 10,
    ];

    public function colorOptionKey(Product $product): ?string
    {
        return $this->firstMatchingOptionKey($product, self::COLOR_OPTION_KEYS);
    }

    /** @deprecated Use colorOptionKey() */
    public function optionKey(Product $product): ?string
    {
        return $this->colorOptionKey($product);
    }

    public function sizeOptionKey(Product $product): ?string
    {
        return $this->firstMatchingOptionKey($product, self::SIZE_OPTION_KEYS);
    }

    public function numericOptionKey(Product $product): ?string
    {
        $group = $this->resolveGroup($product);

        if ($group === null) {
            return null;
        }

        $siblings = $this->siblings($product);

        foreach ($group->variant_option_keys ?? [] as $key) {
            if ($this->isColorOptionKey($key) || $this->isSizeOptionKey($key)) {
                continue;
            }

            $values = $siblings
                ->filter(fn (Product $variant): bool => $variant->is_active && filled($this->optionValue($variant, $key)))
                ->map(fn (Product $variant): string => (string) $this->optionValue($variant, $key))
                ->unique()
                ->values();

            if ($values->count() < 2) {
                continue;
            }

            if ($values->every(fn (string $value): bool => $this->isNumericValue($value))) {
                return $key;
            }
        }

        return null;
    }

    public function chipOptionKey(Product $product): ?string
    {
        return $this->sizeOptionKey($product) ?? $this->numericOptionKey($product);
    }

    /**
     * @param  list<string>  $keys
     */
    private function firstMatchingOptionKey(Product $product, array $keys): ?string
    {
        $group = $this->resolveGroup($product);

        if ($group === null) {
            return null;
        }

        $matched = collect($group->variant_option_keys ?? [])
            ->first(fn (string $key): bool => in_array($key, $keys, true));

        if ($matched !== null) {
            return $matched;
        }

        $siblings = $this->siblings($product);

        return collect($keys)
            ->first(function (string $key) use ($siblings): bool {
                return $siblings
                    ->map(fn (Product $variant): ?string => $this->optionValue($variant, $key))
                    ->filter()
                    ->unique()
                    ->count() >= 2;
            });
    }

    private function resolveGroup(Product $product): ?ProductVariantGroup
    {
        $group = $product->variantGroup;

        if ($group?->isVisibleOnFrontend()) {
            return $group;
        }

        if (! $product->variant_group_id) {
            return null;
        }

        return ProductVariantGroup::query()
            ->whereKey($product->variant_group_id)
            ->whereIn('status', ProductVariantGroup::FRONTEND_STATUSES)
            ->first(['id', 'variant_option_keys', 'status']);
    }

    public function isColorOptionKey(string $key): bool
    {
        return in_array($key, self::COLOR_OPTION_KEYS, true);
    }

    public function isSizeOptionKey(string $key): bool
    {
        return in_array($key, self::SIZE_OPTION_KEYS, true);
    }

    public function isAscendingChipOptionKey(string $key, Collection $variantProducts): bool
    {
        if ($this->isSizeOptionKey($key)) {
            return true;
        }

        $values = $variantProducts
            ->map(fn (Product $product): ?string => $this->optionValue($product, $key))
            ->filter()
            ->unique()
            ->values();

        return $values->count() >= 2
            && $values->every(fn (string $value): bool => $this->isNumericValue($value));
    }

    public function isNumericValue(string $value): bool
    {
        $normalized = trim(str_replace(',', '.', $value));

        if (preg_match('/(\d+(?:\.\d+)?)/', $normalized) !== 1) {
            return false;
        }

        $lettersOnly = mb_strtoupper(preg_replace('/[^A-Za-zА-Яа-я]/u', '', $normalized) ?? '');

        return ! ($lettersOnly !== '' && isset(self::LETTER_SIZE_ORDER[$lettersOnly]) && ! preg_match('/\d/u', $normalized));
    }

    public function isColorGroup(Product $product): bool
    {
        return $this->colorOptionKey($product) !== null;
    }

    public function isSizeGroup(Product $product): bool
    {
        return $this->sizeOptionKey($product) !== null;
    }

    public function translateOptionKey(?string $key): ?string
    {
        if (! filled($key)) {
            return null;
        }

        $key = self::OPTION_KEY_LABELS[$key] ?? $key;

        return __($key);
    }

    public function optionValue(Product $product, string $optionKey): ?string
    {
        $value = data_get($product->variant_options, $optionKey);

        if (filled($value)) {
            return (string) $value;
        }

        $value = data_get($product->specifications, $optionKey);

        return filled($value) ? (string) $value : null;
    }

    /**
     * @return Collection<int, array{label: string, image: string, url: string, is_current: bool, is_available: bool}>
     */
    public function swatches(Product $product, int $limit = 8): Collection
    {
        $optionKey = $this->colorOptionKey($product);

        if ($optionKey === null) {
            return collect();
        }

        return $this->finalizeOptions(
            $this->groupedOptions($product, $optionKey),
            'color',
            $limit,
        );
    }

    /**
     * @return Collection<int, array{label: string, url: string, is_current: bool, is_available: bool}>
     */
    public function chipOptions(Product $product, int $limit = 8): Collection
    {
        $optionKey = $this->chipOptionKey($product);

        if ($optionKey === null) {
            return collect();
        }

        return $this->finalizeOptions(
            $this->groupedOptions($product, $optionKey),
            'size',
            $limit,
        );
    }

    /**
     * @return Collection<int, array{label: string, url: string, is_current: bool, is_available: bool}>
     */
    public function sizeOptions(Product $product, int $limit = 8): Collection
    {
        return $this->chipOptions($product, $limit);
    }

    /**
     * @return Collection<int, array{label: string, image: string, url: string, is_current: bool, is_available: bool}>
     */
    private function groupedOptions(Product $product, string $optionKey): Collection
    {
        $siblings = $this->siblings($product);

        if ($siblings->isEmpty()) {
            return collect();
        }

        return $siblings
            ->filter(fn (Product $variant): bool => $variant->is_active && filled($this->optionValue($variant, $optionKey)))
            ->groupBy(fn (Product $variant): string => (string) $this->optionValue($variant, $optionKey))
            ->map(function (Collection $variants) use ($product, $optionKey): array {
                /** @var Product $variant */
                $variant = $variants
                    ->sortByDesc(fn (Product $item): array => [
                        $item->isPurchasable(),
                        $item->is_primary_variant,
                        -$item->id,
                    ])
                    ->first();

                if ($variant->category_id === $product->category_id && $product->relationLoaded('category')) {
                    $variant->setRelation('category', $product->category);
                }

                return [
                    'label' => (string) $this->optionValue($variant, $optionKey),
                    'image' => $variant->imageUrl(),
                    'url' => $variant->url(absolute: false),
                    'is_current' => $variant->is($product),
                    'is_available' => $variant->isPurchasable(),
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, array{label: string, image: string, url: string, is_current: bool, is_available: bool}>  $options
     * @return Collection<int, array{label: string, image?: string, url: string, is_current: bool, is_available: bool}>
     */
    private function finalizeOptions(Collection $options, string $type, int $limit): Collection
    {
        if ($options->count() < 2) {
            return collect();
        }

        $sorted = $type === 'color'
            ? $options->sortByDesc(fn (array $option): array => [
                $option['is_available'],
                $option['is_current'],
            ])
            : $options->sortBy(fn (array $option): array => [
                ! $option['is_available'],
                $this->sizeSortValue($option['label']),
                mb_strtolower($option['label']),
            ]);

        return $sorted
            ->take($limit)
            ->values()
            ->map(function (array $option) use ($type): array {
                if ($type === 'size') {
                    unset($option['image']);
                }

                return $option;
            });
    }

    /**
     * @return Collection<int, Product>
     */
    private function siblings(Product $product): Collection
    {
        $siblings = $product->relationLoaded('variantGroup') && $product->variantGroup?->relationLoaded('products')
            ? $product->variantGroup->products
            : collect();

        if ($siblings->isNotEmpty() || ! $product->variant_group_id) {
            return $siblings;
        }

        return Product::query()
            ->where('variant_group_id', $product->variant_group_id)
            ->where('is_active', true)
            ->select([
                'id',
                'category_id',
                'variant_group_id',
                'image_url',
                'image_path',
                'variant_options',
                'variant_options_ru',
                'specifications',
                'specifications_ru',
                'slug',
                'stock',
                'price',
                'sale_price',
                'discount_percent',
                'is_primary_variant',
                'is_active',
            ])
            ->orderByDesc('is_primary_variant')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Product>  $variantProducts
     * @param  Collection<int, string>  $values
     * @return Collection<int, string>
     */
    public function sortValuesByAvailability(Collection $variantProducts, string $optionKey, Collection $values): Collection
    {
        return $this->sortOptionValues($variantProducts, $optionKey, $values);
    }

    /**
     * @param  Collection<int, Product>  $variantProducts
     * @param  Collection<int, string>  $values
     * @return Collection<int, string>
     */
    public function sortOptionValues(Collection $variantProducts, string $optionKey, Collection $values): Collection
    {
        if ($this->isColorOptionKey($optionKey)) {
            return $values
                ->sortByDesc(function (string $value) use ($variantProducts, $optionKey): bool {
                    $variant = $variantProducts->first(
                        fn (Product $product): bool => $this->optionValue($product, $optionKey) === $value,
                    );

                    return $variant?->isPurchasable() ?? false;
                })
                ->values();
        }

        if ($this->isAscendingChipOptionKey($optionKey, $variantProducts)) {
            return $values
                ->sort(function (string $left, string $right) use ($variantProducts, $optionKey): int {
                    $leftVariant = $variantProducts->first(
                        fn (Product $product): bool => $this->optionValue($product, $optionKey) === $left,
                    );
                    $rightVariant = $variantProducts->first(
                        fn (Product $product): bool => $this->optionValue($product, $optionKey) === $right,
                    );
                    $leftAvailable = $leftVariant?->isPurchasable() ?? false;
                    $rightAvailable = $rightVariant?->isPurchasable() ?? false;

                    if ($leftAvailable !== $rightAvailable) {
                        return $leftAvailable ? -1 : 1;
                    }

                    return $this->compareSizeValues($left, $right);
                })
                ->values();
        }

        return $values;
    }

    public function compareSizeValues(string $left, string $right): int
    {
        $leftSort = $this->sizeSortValue($left);
        $rightSort = $this->sizeSortValue($right);

        if ($leftSort !== $rightSort) {
            return $leftSort <=> $rightSort;
        }

        return strnatcasecmp($left, $right);
    }

    public function sizeSortValue(string $value): float
    {
        $normalized = trim(str_replace(',', '.', $value));

        if (preg_match('/(\d+(?:\.\d+)?)/', $normalized, $matches) === 1) {
            return (float) $matches[1];
        }

        $letters = mb_strtoupper(preg_replace('/[^A-Za-zА-Яа-я]/u', '', $normalized) ?? '');

        if ($letters !== '' && isset(self::LETTER_SIZE_ORDER[$letters])) {
            return 100 + self::LETTER_SIZE_ORDER[$letters];
        }

        return 1000 + (float) sprintf('0.%u', crc32(mb_strtolower($normalized)));
    }

    public function hasSwatches(Product $product): bool
    {
        return $this->swatches($product)->isNotEmpty();
    }

    public function hasSizeOptions(Product $product): bool
    {
        return $this->chipOptions($product)->isNotEmpty();
    }

    public function hasChipOptions(Product $product): bool
    {
        return $this->hasSizeOptions($product);
    }
}
