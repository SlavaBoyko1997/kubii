<?php

namespace App\Support;

use App\Models\Category;
use App\Models\PaymentOption;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PaymentOptions
{
    public function enabled(): Collection
    {
        $cached = Cache::get('checkout:payment-options');

        if (is_array($cached)) {
            return PaymentOption::hydrate($cached);
        }

        if ($cached !== null) {
            Cache::forget('checkout:payment-options');
        }

        $rows = PaymentOption::query()
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (PaymentOption $option): array => $option->attributesToArray())
            ->all();

        Cache::put('checkout:payment-options', $rows, now()->addHour());

        return PaymentOption::hydrate($rows);
    }

    public function forCart(Collection $items): Collection
    {
        $enabled = $this->enabled();

        if ($items->isEmpty()) {
            return $enabled;
        }

        $allowedCodes = $this->allowedCodesForCart($items);

        return $enabled
            ->filter(fn (PaymentOption $option): bool => in_array($option->code, $allowedCodes, true))
            ->values();
    }

    public function forProduct(Product $product): Collection
    {
        return $this->forCart(collect([
            ['product' => $product, 'quantity' => 1],
        ]));
    }

    public function codes(): array
    {
        return $this->enabled()->pluck('code')->all();
    }

    public function codesForCart(Collection $items): array
    {
        return $this->forCart($items)->pluck('code')->all();
    }

    public function isEnabled(string $code): bool
    {
        return in_array($code, $this->codes(), true);
    }

    private function allowedCodesForCart(Collection $items): array
    {
        $enabledCodes = $this->enabled()->pluck('code')->all();
        $products = $items
            ->pluck('product')
            ->filter(fn (mixed $product): bool => $product instanceof Product)
            ->unique('id')
            ->values();

        if ($products->isEmpty()) {
            return $enabledCodes;
        }

        $categories = Category::query()
            ->with('paymentOptions')
            ->whereIn('id', $products->pluck('category_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $allowedPerCategory = $products
            ->map(function (Product $product) use ($categories, $enabledCodes): array {
                $category = $categories->get($product->category_id);

                if (! $category) {
                    return $enabledCodes;
                }

                $restrictedCodes = $category->restrictedPaymentCodes();

                if ($restrictedCodes === []) {
                    return $enabledCodes;
                }

                return array_values(array_intersect($enabledCodes, $restrictedCodes));
            })
            ->values();

        return $allowedPerCategory->reduce(
            fn (?array $carry, array $codes): array => $carry === null
                ? $codes
                : array_values(array_intersect($carry, $codes)),
            null,
        ) ?? $enabledCodes;
    }
}
