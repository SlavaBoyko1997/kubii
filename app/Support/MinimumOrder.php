<?php

namespace App\Support;

class MinimumOrder
{
    public static function threshold(): int
    {
        return max(0, (int) config('services.min_order.threshold', 1500));
    }

    public static function qualifies(float $orderTotal): bool
    {
        return $orderTotal >= self::threshold();
    }

    public static function remaining(float $orderTotal): float
    {
        return max(0, self::threshold() - $orderTotal);
    }

    public static function formattedThreshold(): string
    {
        return number_format(self::threshold(), 0, ',', ' ');
    }

    public static function formattedRemaining(float $orderTotal): string
    {
        return number_format(self::remaining($orderTotal), 0, ',', ' ');
    }

    public static function hintForTotal(float $orderTotal): string
    {
        if (self::qualifies($orderTotal)) {
            return __('Мінімальна сума замовлення :amount ₴', [
                'amount' => self::formattedThreshold(),
            ]);
        }

        return __('Мінімальне замовлення :amount ₴. Додайте ще на :remaining ₴', [
            'amount' => self::formattedThreshold(),
            'remaining' => self::formattedRemaining($orderTotal),
        ]);
    }

    public static function errorMessage(float $orderTotal): string
    {
        return self::hintForTotal($orderTotal);
    }

    public static function productHint(): string
    {
        return __('Мін. замовлення :amount ₴ · від 20 000 ₴ — повна оплата', [
            'amount' => self::formattedThreshold(),
        ]);
    }
}
