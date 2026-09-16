<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Search engine indexing
    |--------------------------------------------------------------------------
    |
    | The storefront is indexable by default. Set SEO_INDEXING_ENABLED=false
    | explicitly on staging, review apps, and other non-production sites.
    |
    */
    'indexing_enabled' => (bool) env('SEO_INDEXING_ENABLED', true),

    'sitemap_product_chunk' => (int) env('SEO_SITEMAP_PRODUCT_CHUNK', 2500),

    'structured_data' => [
        'product_attributes' => [
            'size' => [
                'Розмір',
                'Размер',
                'size',
                'Розмір взуття',
                'Міжнародний розмір',
                'Международный размер',
            ],
            'color' => [
                'Колір',
                'Цвет',
                'color',
                'Колір товару',
            ],
            'material' => [
                'Матеріал',
                'Материал',
                'material',
            ],
        ],

        'variant_properties' => [
            'size' => 'https://schema.org/size',
            'color' => 'https://schema.org/color',
            'material' => 'https://schema.org/material',
        ],

        'gtin_keys' => [
            'GTIN',
            'EAN',
            'EAN-8',
            'EAN-12',
            'EAN-13',
            'EAN-14',
            'UPC',
            'barcode',
            'Barcode',
            'Штрихкод',
            'Штрих-код',
        ],
    ],
];
