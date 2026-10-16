<?php

namespace App\Support;

class WarehousePickup
{
    public const TYPE = 'warehouse_pickup';

    public const PAYMENT_CODE = 'pay_in_store';

    public const CITY = 'Бориспіль';

    public const STREET = 'вул. Київський шлях, 79в';

    public const SHOP = 'магазин kubik';

    public const SHORT_LABEL = 'Самовивіз з магазину';

    public const ADDRESS_LINE = 'м. Бориспіль, вул. Київський шлях, 79в (магазин kubik)';

    public const LABEL = 'Самовивіз з магазину м. Бориспіль, вул. Київський шлях, 79в (магазин kubik)';

    public static function fullAddress(): string
    {
        return self::STREET.', '.self::CITY.' ('.self::SHOP.')';
    }

    public static function isPickup(?string $deliveryType): bool
    {
        return $deliveryType === self::TYPE;
    }
}
