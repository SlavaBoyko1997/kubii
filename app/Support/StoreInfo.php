<?php

namespace App\Support;

class StoreInfo
{
    public static function name(): string
    {
        return (string) config('store.name');
    }

    public static function domain(): string
    {
        return (string) config('store.domain');
    }

    public static function tagline(): string
    {
        return (string) config('store.tagline');
    }

    public static function legalName(?string $locale = null): string
    {
        return self::localized('store.seller.legal_name', $locale);
    }

    public static function taxId(): string
    {
        return (string) config('store.seller.tax_id');
    }

    public static function address(?string $locale = null): string
    {
        return self::localized('store.seller.address', $locale);
    }

    public static function locality(?string $locale = null): string
    {
        return self::localized('store.seller.locality', $locale);
    }

    public static function streetAddress(?string $locale = null): string
    {
        return self::localized('store.seller.street_address', $locale);
    }

    public static function addressCountry(): string
    {
        return (string) config('store.seller.country', 'UA');
    }

    public static function email(): ?string
    {
        return filled(config('store.contacts.email')) ? config('store.contacts.email') : null;
    }

    public static function phone(): ?string
    {
        return filled(config('store.contacts.phone')) ? config('store.contacts.phone') : null;
    }

    public static function hasPhone(): bool
    {
        return self::phone() !== null;
    }

    public static function schedule(?string $locale = null): string
    {
        return self::localized('store.schedule', $locale);
    }

    /**
     * @return list<array{days: list<string>, opens: string, closes: string}>
     */
    public static function openingHours(): array
    {
        return config('store.opening_hours', []);
    }

    /**
     * @return list<string>
     */
    public static function deliveryCarriers(): array
    {
        return config('store.delivery_carriers', []);
    }

    private static function localized(string $key, ?string $locale = null): string
    {
        $locale ??= Locale::current();
        $values = config($key, []);

        return (string) ($values[$locale] ?? $values[Locale::DEFAULT] ?? '');
    }
}
