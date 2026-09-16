@props(['meta'])

<meta property="og:title" content="{{ $meta['title'] }}">
<meta property="og:description" content="{{ $meta['description'] }}">
<meta property="og:image" content="{{ $meta['image'] }}">
<meta property="og:image:secure_url" content="{{ $meta['image_secure_url'] }}">
<meta property="og:image:type" content="image/jpeg">
<meta property="og:image:width" content="{{ $meta['image_width'] }}">
<meta property="og:image:height" content="{{ $meta['image_height'] }}">
<meta property="og:image:alt" content="{{ $meta['image_alt'] }}">
<meta property="og:url" content="{{ $meta['url'] }}">
<meta property="og:type" content="{{ $meta['type'] }}">
<meta property="og:site_name" content="{{ $meta['site_name'] }}">
<meta property="og:locale" content="{{ $meta['locale'] }}">
@foreach($meta['locale_alternates'] as $locale)
    <meta property="og:locale:alternate" content="{{ $locale }}">
@endforeach
@foreach($meta['extra'] as $property => $content)
    <meta property="{{ $property }}" content="{{ $content }}">
@endforeach
<meta name="twitter:card" content="{{ $meta['twitter_card'] }}">
<meta name="twitter:title" content="{{ $meta['title'] }}">
<meta name="twitter:description" content="{{ $meta['description'] }}">
<meta name="twitter:image" content="{{ $meta['image'] }}">
<meta name="twitter:image:alt" content="{{ $meta['image_alt'] }}">
