<?php

namespace App\Support;

class FreeDelivery
{
    public static function threshold(): int
    {
        return max(0, (int) config('services.free_delivery.threshold', 3000));
    }

    public static function qualifies(float $orderTotal): bool
    {
        $threshold = self::threshold();

        return $threshold > 0 && $orderTotal >= $threshold;
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

    public static function progressPercent(float $orderTotal): int
    {
        $threshold = self::threshold();

        if ($threshold <= 0) {
            return 0;
        }

        return (int) min(100, max(0, round(($orderTotal / $threshold) * 100)));
    }

    public static function promoLabel(): string
    {
        return __('Безкоштовна доставка від :amount ₴', ['amount' => self::formattedThreshold()]);
    }

    public static function hintForTotal(float $orderTotal): string
    {
        if (self::qualifies($orderTotal)) {
            return __('Безкоштовна доставка Новою Поштою');
        }

        $remaining = self::remaining($orderTotal);

        if ($remaining <= 0) {
            return self::promoLabel();
        }

        return __('Ще :remaining ₴ до безкоштовної доставки', [
            'remaining' => number_format($remaining, 0, ',', ' '),
        ]);
    }

    public static function labelForTotal(float $orderTotal): string
    {
        return self::qualifies($orderTotal)
            ? __('Безкоштовно')
            : __('За тарифом перевізника');
    }
}
