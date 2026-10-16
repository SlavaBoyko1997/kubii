<?php

namespace App\Support;

class WarehousePickup
{
    public const TYPE = 'warehouse_pickup';

    public const PAYMENT_CODE = 'pay_in_store';

    public const CITY = 'Бориспіль';

    public const STREET = 'вул. Київський шлях, 79в';

    public static function fullAddress(): string
    {
        return self::STREET.', '.self::CITY;
    }

    public static function isPickup(?string $deliveryType): bool
    {
        return $deliveryType === self::TYPE;
    }
}
