<?php

// Single source of truth for the store's legal/contact identity.
// Update the values below (or the matching STORE_* env vars) once —
// the footer, contacts page, checkout copy and structured data (Schema.org)
// all read from here, so they never fall out of sync.

return [
    'name' => 'BASH',

    'domain' => env('APP_URL', 'https://kubii.com.ua'),

    'tagline' => 'Інтернет-магазин туристичного та тактичного спорядження BASH.',

    'seller' => [
        // ФОП / ТОВ і ПІБ або назва юридичної особи.
        'legal_name' => [
            'uk' => env('STORE_SELLER_LEGAL_NAME_UK', 'Товариство з обмеженою відповідальністю "Овлікс"'),
            'ru' => env('STORE_SELLER_LEGAL_NAME_RU', 'Общество с ограниченной ответственностью «Овликс»'),
        ],

        // ПІБ директора / керівника.
        'director' => [
            'uk' => env('STORE_SELLER_DIRECTOR_UK', 'Харченко Олег Іванович'),
            'ru' => env('STORE_SELLER_DIRECTOR_RU', 'Харченко Олег Иванович'),
        ],

        // ЄДРПОУ (юр. особа) або РНОКПП/ІПН (фіз. особа-підприємець).
        'tax_id' => env('STORE_SELLER_TAX_ID', '46404239'),

        'country' => 'UA',

        // Адреса для листування / юридична адреса.
        'address' => [
            'uk' => env('STORE_SELLER_ADDRESS_UK', 'Україна, 01024, місто Київ, вул. Антоновича, будинок 20, офіс 29'),
            'ru' => env('STORE_SELLER_ADDRESS_RU', 'Украина, 01024, город Киев, ул. Антоновича, дом 20, офис 29'),
        ],

        'locality' => [
            'uk' => 'Київ',
            'ru' => 'Киев',
        ],

        'street_address' => [
            'uk' => 'вул. Антоновича, будинок 20, офіс 29',
            'ru' => 'ул. Антоновича, дом 20, офис 29',
        ],
    ],

    'contacts' => [
        'email' => env('STORE_CONTACT_EMAIL', 'owlix.ua@gmail.com'),

        'phone' => env('STORE_CONTACT_PHONE', '+38 (099) 148-73-48'),

        'telegram' => env('STORE_CONTACT_TELEGRAM', 'https://t.me/+380991487348'),

        'instagram' => env('STORE_CONTACT_INSTAGRAM', 'https://www.instagram.com/bash.camp/'),
    ],

    // Єдиний графік роботи для футера, сторінки контактів і structured data.
    'schedule' => [
        'uk' => 'Пн–Пт 10:00–18:00, Сб 10:00–15:00, Нд — вихідний',
        'ru' => 'Пн–Пт 10:00–18:00, Сб 10:00–15:00, Вс — выходной',
    ],

    'opening_hours' => [
        ['days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 'opens' => '10:00', 'closes' => '18:00'],
        ['days' => ['Saturday'], 'opens' => '10:00', 'closes' => '15:00'],
    ],

    'delivery_carriers' => ['Нова Пошта'],
];
