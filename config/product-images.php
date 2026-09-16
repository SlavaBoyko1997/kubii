<?php

return [
    'webp_quality' => (int) env('PRODUCT_IMAGE_WEBP_QUALITY', 88),
    'jpeg_quality' => (int) env('PRODUCT_IMAGE_JPEG_QUALITY', 90),
    'max_source_bytes' => (int) env('PRODUCT_IMAGE_MAX_SOURCE_BYTES', 12_000_000),
    'mirror_batch_size' => (int) env('PRODUCT_IMAGE_MIRROR_BATCH_SIZE', 1),
    'mirror_gallery_batch_size' => (int) env('PRODUCT_IMAGE_MIRROR_GALLERY_BATCH_SIZE', 2),
    'mirror_gallery_images_per_job' => (int) env('PRODUCT_IMAGE_MIRROR_IMAGES_PER_JOB', 3),
    'mirror_gallery_product_time_budget' => (int) env('PRODUCT_IMAGE_MIRROR_GALLERY_PRODUCT_BUDGET', 90),
    'mirror_gallery_orchestrator_batch' => (int) env('PRODUCT_IMAGE_MIRROR_GALLERY_ORCHESTRATOR_BATCH', 150),
    'mirror_job_timeout' => (int) env('PRODUCT_IMAGE_MIRROR_JOB_TIMEOUT', 180),
    'mirror_job_time_budget_seconds' => (int) env('PRODUCT_IMAGE_MIRROR_TIME_BUDGET', 90),
    'http_timeout' => (int) env('PRODUCT_IMAGE_HTTP_TIMEOUT', 20),
    'http_connect_timeout' => (int) env('PRODUCT_IMAGE_HTTP_CONNECT_TIMEOUT', 5),
    'user_agent' => env('PRODUCT_IMAGE_USER_AGENT', 'Kubii-ProductImageMirror/1.0'),
    'browser_user_agent' => env(
        'PRODUCT_IMAGE_BROWSER_USER_AGENT',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    ),
    'trusted_hosts' => array_values(array_filter(array_map(
        static fn (string $host): string => mb_strtolower(trim($host)),
        explode(',', (string) env('PRODUCT_IMAGE_TRUSTED_HOSTS', 'file.pobedov.com,ranger.ua,atlantmarket.com.ua,komiz.io,camotec.ua,mangalzavod.com.ua,images.prom.ua,tactic-shop.in.ua,24.ecomm.plus,scdn.ibis-gear.com')),
    ))),
];
