<?php

namespace App\Support;

use App\Models\Order;
use Carbon\CarbonInterface;

class GoogleCustomerReviews
{
    public static function enabled(): bool
    {
        if (! config('services.google_customer_reviews.enabled', false)) {
            return false;
        }

        return self::merchantId() !== null;
    }

    public static function merchantId(): ?int
    {
        $id = trim((string) config('services.google_customer_reviews.merchant_id', ''));

        if ($id === '' || ! ctype_digit($id)) {
            return null;
        }

        return (int) $id;
    }

    public static function deliveryDays(): int
    {
        return max(0, (int) config('services.google_customer_reviews.delivery_days', 3));
    }

    public static function shouldRender(Order $order): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $email = trim((string) $order->email);

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * @return array{
     *     merchant_id: int,
     *     order_id: string,
     *     email: string,
     *     delivery_country: string,
     *     estimated_delivery_date: string
     * }|null
     */
    public static function optInPayload(Order $order): ?array
    {
        if (! self::shouldRender($order)) {
            return null;
        }

        return [
            'merchant_id' => self::merchantId(),
            'order_id' => (string) $order->number,
            'email' => trim((string) $order->email),
            'delivery_country' => self::deliveryCountry($order),
            'estimated_delivery_date' => self::estimatedDeliveryDate($order),
        ];
    }

    public static function deliveryCountry(Order $order): string
    {
        // Kubii ships via Nova Poshta in Ukraine; orders have no country column yet.
        return 'UA';
    }

    public static function estimatedDeliveryDate(Order $order, ?CarbonInterface $from = null): string
    {
        $base = $from ?? $order->created_at ?? now();

        return $base->copy()->addDays(self::deliveryDays())->format('Y-m-d');
    }
}
