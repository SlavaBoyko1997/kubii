<?php

namespace App\Support;

use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Cart
{
    private const SESSION_KEY = 'cart';

    private ?Collection $resolvedItems = null;

    private ?array $resolvedRaw = null;

    public function add(Product $product, int $quantity = 1): void
    {
        $cart = $this->raw();
        $cart[$product->id] = min(($cart[$product->id] ?? 0) + $quantity, $product->stock);

        $this->persist($cart);
    }

    public function update(Product $product, int $quantity): void
    {
        $cart = $this->raw();

        if ($quantity <= 0) {
            unset($cart[$product->id]);
        } else {
            $cart[$product->id] = min($quantity, $product->stock);
        }

        $this->persist($cart);
    }

    public function remove(Product $product): void
    {
        $cart = $this->raw();
        unset($cart[$product->id]);
        $this->persist($cart);
    }

    public function clear(): void
    {
        $this->resolvedRaw = null;
        $this->resolvedItems = null;

        if (Auth::check()) {
            CartItem::query()->where('user_id', Auth::id())->delete();

            return;
        }

        $this->clearGuest();
    }

    public function items(): Collection
    {
        return $this->resolvedItems ??= $this->itemsFrom($this->raw());
    }

    public function itemsFrom(array $cart): Collection
    {
        if ($cart === []) {
            return collect();
        }

        return Product::query()
            ->whereIn('id', array_keys($cart))
            ->where('is_active', true)
            ->get()
            ->filter(fn (Product $product): bool => $product->isPurchasable())
            ->map(function (Product $product) use ($cart): array {
                $quantity = min($cart[$product->id], $product->stock);

                return [
                    'product' => $product,
                    'quantity' => $quantity,
                    'subtotal' => $product->salePrice() * $quantity,
                ];
            })
            ->filter(fn (array $item): bool => $item['quantity'] > 0);
    }

    public function count(): int
    {
        return $this->items()->sum('quantity');
    }

    public function total(): float
    {
        return $this->items()->sum('subtotal');
    }

    public function productIds(): array
    {
        return $this->items()->pluck('product.id')->all();
    }

    public function guestContents(): array
    {
        return $this->normalize(session()->get(self::SESSION_KEY, []));
    }

    public function accountContents(?int $userId = null): array
    {
        $userId ??= Auth::id();

        if (! $userId) {
            return [];
        }

        return CartItem::query()
            ->where('user_id', $userId)
            ->pluck('quantity', 'product_id')
            ->mapWithKeys(fn ($quantity, $productId): array => [(int) $productId => (int) $quantity])
            ->all();
    }

    public function moveGuestToAccount(): void
    {
        $guest = $this->guestContents();

        if ($guest !== [] && Auth::check()) {
            $this->replaceAccount($guest);
        }

        $this->clearGuest();
    }

    public function mergeIntoAccount(array $cart): void
    {
        $merged = $this->accountContents();

        foreach ($this->normalize($cart) as $productId => $quantity) {
            $merged[$productId] = ($merged[$productId] ?? 0) + $quantity;
        }

        $this->replaceAccount($merged);
    }

    public function replaceAccount(array $cart): void
    {
        $userId = Auth::id();

        if (! $userId) {
            return;
        }

        $cart = $this->capToStock($cart);

        DB::transaction(function () use ($userId, $cart): void {
            CartItem::query()->where('user_id', $userId)->delete();

            if ($cart !== []) {
                CartItem::query()->insert(
                    collect($cart)->map(fn (int $quantity, int $productId): array => [
                        'user_id' => $userId,
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->values()->all()
                );
            }
        });
    }

    public function clearGuest(): void
    {
        $this->resolvedRaw = null;
        $this->resolvedItems = null;
        session()->forget(self::SESSION_KEY);
    }

    private function raw(): array
    {
        return $this->resolvedRaw ??= Auth::check() ? $this->accountContents() : $this->guestContents();
    }

    private function persist(array $cart): void
    {
        $this->resolvedRaw = null;
        $this->resolvedItems = null;

        if (Auth::check()) {
            $this->replaceAccount($cart);

            return;
        }

        session()->put(self::SESSION_KEY, $this->capToStock($cart));
    }

    private function capToStock(array $cart): array
    {
        $cart = $this->normalize($cart);
        $stocks = Product::query()->whereIn('id', array_keys($cart))->pluck('stock', 'id');

        return collect($cart)
            ->map(fn (int $quantity, int $productId): int => min($quantity, (int) ($stocks[$productId] ?? 0)))
            ->filter(fn (int $quantity): bool => $quantity > 0)
            ->all();
    }

    private function normalize(array $cart): array
    {
        return collect($cart)
            ->mapWithKeys(fn ($quantity, $productId): array => [(int) $productId => max(0, (int) $quantity)])
            ->filter()
            ->all();
    }
}
