<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Collection;

class GoogleAnalytics
{
    public static function enabled(): bool
    {
        if (! config('services.google_analytics.enabled', true)) {
            return false;
        }

        return self::measurementId() !== null;
    }

    public static function measurementId(): ?string
    {
        $id = trim((string) config('services.google_analytics.measurement_id', ''));

        return $id !== '' ? $id : null;
    }

    /**
     * @param  Collection<int, array{product: Product, quantity: int, subtotal: float|int}>  $items
     * @return array<int, array{item_id: string, item_name: string, item_category: ?string, price: float, quantity: int}>
     */
    public static function cartItems(Collection $items): array
    {
        return $items
            ->map(fn (array $item): array => self::productItem($item['product'], (int) $item['quantity']))
            ->values()
            ->all();
    }

    /**
     * @return array{item_id: string, item_name: string, item_category: ?string, price: float, quantity: int}
     */
    public static function productItem(Product $product, int $quantity = 1): array
    {
        return [
            'item_id' => (string) $product->id,
            'item_name' => $product->name,
            'item_category' => $product->category?->name,
            'price' => (float) $product->salePrice(),
            'quantity' => max(1, $quantity),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function viewItemPayload(Product $product): array
    {
        $item = self::productItem($product);

        return [
            'currency' => 'UAH',
            'value' => $item['price'],
            'items' => [$item],
        ];
    }

    /**
     * @param  Collection<int, array{product: Product, quantity: int, subtotal: float|int}>  $items
     * @return array<string, mixed>
     */
    public static function beginCheckoutPayload(Collection $items, float $total): array
    {
        return [
            'currency' => 'UAH',
            'value' => round($total, 2),
            'items' => self::cartItems($items),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function purchasePayload(Order $order): array
    {
        return [
            'transaction_id' => (string) $order->number,
            'value' => (float) $order->total,
            'currency' => 'UAH',
            'items' => $order->items->map(fn ($item): array => [
                'item_id' => (string) ($item->product_id ?? $item->id),
                'item_name' => $item->product_name,
                'price' => (float) $item->price,
                'quantity' => (int) $item->quantity,
            ])->values()->all(),
        ];
    }
}
