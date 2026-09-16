@php($categoryName = data_get($category, 'name'))
@php($categoryImage = match (true) {
    is_object($category) && method_exists($category, 'imageUrl') => $category->imageUrl(),
    filled(data_get($category, 'image_src')) => data_get($category, 'image_src'),
    filled(data_get($category, 'image_path')) => \App\Support\StoredAsset::url(data_get($category, 'image_path')),
    filled(data_get($category, 'image_url')) => \App\Support\StoredAsset::url(data_get($category, 'image_url')) ?? asset(ltrim((string) data_get($category, 'image_url'), '/')),
    default => null,
})
<img src="{{ $categoryImage ?: asset('images/hero-outdoor.webp') }}" alt="{{ $categoryName }}" width="600" height="420" loading="lazy" decoding="async" data-image-fallback="{{ asset('images/hero-outdoor.webp') }}">
