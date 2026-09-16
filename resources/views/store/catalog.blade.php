@extends('layouts.store')

@section('title', $currentCategory?->filteredSeoTitle($filterHeadingSuffix ?? '') ?? $catalogHeading ?? __('Каталог товарів'))

@push('head')
    @if($products->previousPageUrl())<link rel="prev" href="{{ browser_url($products->previousPageUrl()) }}">@endif
    @if($products->nextPageUrl())<link rel="next" href="{{ browser_url($products->nextPageUrl()) }}">@endif
    <x-seo-schema :schema="$seoSchema" />
@endpush

@section('content')
    <div class="container page-space catalog-page">
        <div class="breadcrumbs"><a href="{{ localized_route('home') }}">{{ __('Головна') }}</a>@if($currentBrand ?? null) / <a href="{{ localized_route('brands.index') }}">{{ __('Бренди') }}</a> / <strong>{{ $currentBrand }}</strong>@elseif($currentCategory) @foreach($currentCategory->breadcrumbTrail() as $crumb) / @if($loop->last)<strong>{{ $crumb->name }}</strong>@else<a href="{{ $crumb->catalogUrl(absolute: false) }}">{{ $crumb->name }}</a>@endif @endforeach @endif</div>
        @if($currentBrand ?? null)
            <a class="catalog-parent-link" href="{{ localized_route('brands.index') }}">
                <span aria-hidden="true">←</span>
                <small>{{ __('Назад до брендів') }}</small>
                <strong>{{ __('Усі бренди') }}</strong>
            </a>
        @elseif($currentCategory?->parent)
            <a class="catalog-parent-link" href="{{ $currentCategory->parent->catalogUrl(absolute: false) }}">
                <span aria-hidden="true">←</span>
                <small>{{ __('Назад до розділу') }}</small>
                <strong>{{ $currentCategory->parent->name }}</strong>
            </a>
        @endif
        @if($showcaseCategories->isNotEmpty())
            <section class="category-showcase">
                <div class="category-showcase-head">
                    <div>
                        <span class="category-showcase-kicker">{{ __('Оберіть потрібний розділ') }}</span>
                        <h1 data-catalog-heading>{{ $catalogHeading ?? $currentCategory?->pageH1() ?? __('Каталог товарів') }}</h1>
                    </div>
                    <div class="category-showcase-meta">
                        <span class="category-showcase-count">{{ __('Категорій: :count', ['count' => $showcaseCategories->count()]) }}</span>
                    </div>
                </div>
                <div class="showcase-scroll-shell {{ $showcaseCategories->count() > 2 ? 'has-overflow-cue' : '' }}">
                    <div class="showcase-grid">
                        @foreach($showcaseCategories as $showcaseCategory)
                            @php($showcaseCategoryId = data_get($showcaseCategory, 'id'))
                            @php($isCurrentShowcaseCategory = $showcaseCategoryId ? $currentCategory?->id === $showcaseCategoryId : $currentCategory?->is($showcaseCategory))
                            <a class="showcase-category-card {{ $isCurrentShowcaseCategory ? 'active' : '' }}" href="{{ data_get($showcaseCategory, 'url') ?? $showcaseCategory->catalogUrl(absolute: false) }}" @if($isCurrentShowcaseCategory) aria-current="page" @endif>
                                <span class="showcase-category-media">
                                    @include('store._category-image', ['category' => $showcaseCategory])
                                    @if($isCurrentShowcaseCategory)<span class="showcase-category-selected">{{ __('Вибрано') }}</span>@endif
                                </span>
                                <span class="showcase-category-title">
                                    <strong>{{ data_get($showcaseCategory, 'name') ?? $showcaseCategory->name }}</strong>
                                    <span aria-hidden="true">{{ $isCurrentShowcaseCategory ? '✓' : '↗' }}</span>
                                </span>
                            </a>
                        @endforeach
                    </div>
                    @if($showcaseCategories->count() > 2)
                        <button class="showcase-scroll-arrow" type="button" data-showcase-scroll-next aria-label="{{ __('Показати ще категорії') }}">→</button>
                    @endif
                </div>
            </section>
        @else
            <div class="catalog-heading-only">
                <div class="catalog-heading-main">
                    <div class="catalog-heading-title-row">
                        <h1 data-catalog-heading>{{ $catalogHeading ?? $currentCategory?->pageH1() ?? __('Каталог товарів') }}</h1>
                        <span class="catalog-heading-count">{{ __(':count товарів', ['count' => $products->total()]) }}</span>
                    </div>
                </div>
            </div>
        @endif
        <div class="mobile-catalog-controls">
            <button type="button" data-toggle-mobile-filters>{{ __('Фільтри') }} <span>⌄</span></button>
            <button type="button" data-toggle-mobile-sort>{{ __('Сортування') }} <span>⌄</span></button>
        </div>
        <div class="sheet-overlay" data-sheet-overlay></div>
        <div class="catalog-layout">
            <aside class="catalog-aside" data-mobile-filters>
                <div class="mobile-sheet-head"><div><span>{{ __('Каталог товарів') }}</span><h3>{{ __('Фільтри') }}</h3></div><button type="button" data-close-mobile-sheets>×</button></div>
                <form class="catalog-filters" action="{{ $catalogBaseUrl }}" method="GET" data-filter-form data-filter-category="{{ $currentCategory?->id }}">
                    <div data-filter-fields @if($deferFilterHydration ?? false) data-deferred-filters @endif>
                        @if($deferFilterHydration ?? false)
                            <div class="catalog-filter-loading" role="status">
                                <h3>{{ __('Фільтри') }}</h3>
                                <span aria-hidden="true"></span>
                                <p>{{ __('Завантажуємо доступні фільтри…') }}</p>
                            </div>
                        @elseif(isset($filterFieldsHtml))
                            {!! $filterFieldsHtml !!}
                        @else
                            @include('store._catalog-filter-fields')
                        @endif
                    </div>
                </form>
            </aside>
            <section>
                <form class="catalog-toolbar" action="{{ $catalogBaseUrl }}" method="GET" data-toolbar-form data-mobile-sort>
                    <div class="mobile-sheet-head"><div><span>{{ __('Порядок товарів') }}</span><h3>{{ __('Сортування') }}</h3></div><button type="button" data-close-mobile-sheets>×</button></div>
                    @foreach(request()->except(['sort', 'per_page', 'page']) as $key => $value)
                        @if($key === 'spec')
                            @foreach((array) $value as $specKey => $items)
                                @foreach((array) $items as $item)<input type="hidden" name="spec[{{ $specKey }}][]" value="{{ $item }}">@endforeach
                            @endforeach
                        @elseif(is_array($value)) @foreach($value as $item)<input type="hidden" name="{{ $key }}[]" value="{{ $item }}">@endforeach @else <input type="hidden" name="{{ $key }}" value="{{ $value }}"> @endif
                    @endforeach
                    <label>{{ __('Сортування') }}<select name="sort"><option value="popular" @selected($sort === 'popular')>{{ __('Популярність') }}</option><option value="newest" @selected($sort === 'newest')>{{ __('Новинки') }}</option><option value="price_asc" @selected($sort === 'price_asc')>{{ __('Від дешевих') }}</option><option value="price_desc" @selected($sort === 'price_desc')>{{ __('Від дорогих') }}</option></select></label>
                    <label>{{ __('На сторінці') }}<select name="per_page"><option @selected($perPage === 20)>20</option><option @selected($perPage === 40)>40</option><option @selected($perPage === 60)>60</option></select></label>
                </form>
                <div data-catalog-results>
                    @if(isset($catalogResultsHtml))
                        {!! $catalogResultsHtml !!}
                    @else
                        @include('store._catalog-results')
                    @endif
                </div>
            </section>
        </div>
    </div>
@endsection
