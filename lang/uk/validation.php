<?php

return [
    'required' => 'Заповніть поле «:attribute».',
    'email' => 'У полі «:attribute» має бути коректна email-адреса.',
    'in' => 'Обране значення поля «:attribute» недопустиме.',
    'string' => 'Поле «:attribute» має бути текстом.',
    'max' => [
        'string' => 'Поле «:attribute» не може бути довшим за :max символів.',
    ],

    'attributes' => [
        'last_name' => 'прізвище',
        'first_name' => 'ім’я',
        'patronymic' => 'по батькові',
        'phone' => 'телефон',
        'email' => 'email',
        'delivery_type' => 'тип доставки',
        'nova_poshta_city_ref' => 'місто',
        'nova_poshta_warehouse_ref' => 'відділення або поштомат',
        'payment_method' => 'спосіб оплати',
        'comment' => 'коментар',
    ],
];
