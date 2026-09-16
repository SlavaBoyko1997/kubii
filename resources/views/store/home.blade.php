@extends('layouts.store')

@section('title', __('Головна'))

@push('head')
    <link rel="preload" as="image" href="{{ asset('images/hero-outdoor.webp') }}" type="image/webp" fetchpriority="high">
    <x-seo-schema :schema="$seoSchema" />
@endpush

@section('content')
    <section class="hero-slider" data-hero-slider>
        @foreach($heroSlides as $slide)
            <article class="hero-slide {{ $loop->first ? 'is-active' : '' }}">
                @if(filled($slide['button_url'] ?? null) && blank($slide['button_label'] ?? null))
                    <a
                        class="hero-slide-link"
                        href="{{ $slide['button_url'] }}"
                        aria-label="{{ filled($slide['title'] ?? null) ? $slide['title'] : __('Відкрити банер') }}"
                    ></a>
                @endif
                @if($loop->first)
                    <img class="hero-slide-image" src="{{ $slide['image'] }}" alt="" width="1600" height="640" fetchpriority="high" decoding="async">
                @else
                    <img class="hero-slide-image" data-src="{{ $slide['image'] }}" alt="" width="1400" height="875" loading="lazy" decoding="async">
                @endif
                <div class="container">
                    @if(filled($slide['title'] ?? null))<h1>{{ $slide['title'] }}</h1>@endif
                    @if(filled($slide['text'] ?? null))<p>{{ $slide['text'] }}</p>@endif
                    @if(filled($slide['button_label'] ?? null) && filled($slide['button_url'] ?? null))
                        <a class="hero-button" href="{{ $slide['button_url'] }}">{{ $slide['button_label'] }}</a>
                    @elseif(filled($slide['button_label'] ?? null))
                        <button class="hero-button" type="button" data-open-catalog>{{ $slide['button_label'] ?: __('До каталогу') }}</button>
                    @endif
                </div>
            </article>
        @endforeach
        @if(count($heroSlides) > 1)
            <button class="hero-nav hero-nav-prev" type="button" data-hero-prev aria-label="{{ __('Попередній банер') }}">‹</button>
            <button class="hero-nav hero-nav-next" type="button" data-hero-next aria-label="{{ __('Наступний банер') }}">›</button>
            <div class="hero-dots">@foreach($heroSlides as $slide)<button class="{{ $loop->first ? 'is-active' : '' }}" type="button" data-hero-dot="{{ $loop->index }}" aria-label="{{ __('Банер :number', ['number' => $loop->iteration]) }}"></button>@endforeach</div>
        @endif
    </section>

    <section class="features"><div class="container features-grid">
        <div class="feature"><span class="feature-icon"><x-heroicon-o-sparkles /></span><div><strong>{{ __('Якісні товари') }}</strong><span>{{ __('Тільки перевірені бренди') }}</span></div></div>
        <div class="feature"><span class="feature-icon"><x-heroicon-o-truck /></span><div><strong>{{ __('Швидка доставка') }}</strong><span>{{ __('По Україні · безкоштовно від :amount ₴', ['amount' => \App\Support\FreeDelivery::formattedThreshold()]) }}</span></div></div>
        <div class="feature"><span class="feature-icon"><x-heroicon-o-arrow-path /></span><div><strong>{{ __('Гарантія повернення') }}</strong><span>{{ __('14 днів на повернення') }}</span></div></div>
        <div class="feature"><span class="feature-icon"><x-heroicon-o-chat-bubble-left-right /></span><div><strong>{{ __('Консультація менеджера') }}</strong><span>{{ __('Підтримка у робочий час') }}</span></div></div>
    </div></section>

    <div class="container">
        <section class="section home-categories">
            <div class="home-categories-head">
                <div>
                    <span>{{ __('Маршрути і водойми') }}</span>
                    <h2>{{ __('Категорії товарів') }}</h2>
                    <p>{{ __('Від спорядження для подорожей до всього необхідного для гарного улову.') }}</p>
                </div>
                <button class="home-categories-all" type="button" data-open-catalog>{{ __('Відкрити весь каталог') }} <span>→</span></button>
            </div>
            <div class="category-grid">
                @foreach ($categories as $category)
                    <a class="category-card" href="{{ data_get($category, 'url') ?? $category->catalogUrl() }}">
                        <span class="category-card-media">@include('store._category-image', ['category' => $category])</span>
                        <span class="category-card-content">
                            <small>{{ __('Секція') }} {{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</small>
                            <strong>{{ data_get($category, 'name') ?? $category->name }}</strong>
                            <span>{{ __('Переглянути товари') }} <i>↗</i></span>
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
        @include('store._product-slider', ['title' => __('Акційні товари'), 'products' => $saleProducts])
        @include('store._product-slider', ['title' => __('Товари з найкращим рейтингом'), 'products' => $topRatedProducts])
        @include('store._product-slider', ['title' => __('Кращі товари різних брендів'), 'products' => $bestBrandProducts])
        @include('store._product-slider', ['title' => __('Популярні товари'), 'products' => $popularProducts])
        @include('store._blog-slider', ['title' => __('Корисні статті'), 'posts' => $blogPosts])
        <section class="promo"><div><h2>{{ __('Спорядження для маршруту, стоянки й улову') }}</h2><p>{{ __('Збирайте набір під конкретну поїздку: від базового кемпінгу до риболовлі на вихідні.') }}</p><button class="promo-button" type="button" data-open-catalog>{{ __('Переглянути каталог') }}</button></div><div class="promo-features"><div class="promo-feature"><strong>{{ __('Туризм') }}</strong><span>{{ __('Намети, рюкзаки, світло') }}</span></div><div class="promo-feature"><strong>{{ __('Риболовля') }}</strong><span>{{ __('Снасті та аксесуари') }}</span></div><div class="promo-feature"><strong>{{ __('Підбір') }}</strong><span>{{ __('Допоможемо з вибором') }}</span></div></div></section>
    </div>
@endsection
