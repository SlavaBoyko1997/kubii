<?php

// Single source of truth for the store's legal/contact identity.
// Update the values below (or the matching STORE_* env vars) once —
// the footer, contacts page, checkout copy and structured data (Schema.org)
// all read from here, so they never fall out of sync.

return [
    'name' => 'Kubii',

    'domain' => env('APP_URL', 'https://kubii.com.ua'),

    'tagline' => 'Інтернет-магазин товарів для риболовлі, туризму, кемпінгу та відпочинку на природі.',

    'seller' => [
        // ФОП / ТОВ і ПІБ або назва юридичної особи.
        'legal_name' => [
            'uk' => env('STORE_SELLER_LEGAL_NAME_UK', "ФОП Солов'ян Ілона Юріївна"),
            'ru' => env('STORE_SELLER_LEGAL_NAME_RU', 'ФЛП Соловьян Илона Юрьевна'),
        ],

        // ЄДРПОУ (юр. особа) або РНОКПП/ІПН (фіз. особа-підприємець).
        'tax_id' => env('STORE_SELLER_TAX_ID', '3674302504'),

        'country' => 'UA',

        // Адреса для листування / юридична адреса.
        'address' => [
            'uk' => env('STORE_SELLER_ADDRESS_UK', 'Україна, м. Київ, вул. Святослава Хороброго, 11'),
            'ru' => env('STORE_SELLER_ADDRESS_RU', 'Украина, г. Киев, ул. Святослава Хороброго, 11'),
        ],

        'locality' => [
            'uk' => 'Київ',
            'ru' => 'Киев',
        ],

        'street_address' => [
            'uk' => 'вул. Святослава Хороброго, 11',
            'ru' => 'ул. Святослава Хороброго, 11',
        ],
    ],

    'contacts' => [
        'email' => env('STORE_CONTACT_EMAIL', 'hello@kubii.com.ua'),

        // Залиште null, доки номера немає — він просто не показуватиметься на сайті.
        'phone' => env('STORE_CONTACT_PHONE'),
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

    'delivery_carriers' => ['Нова Пошта', 'Укрпошта'],
];
