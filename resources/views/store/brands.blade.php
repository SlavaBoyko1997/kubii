@extends('layouts.store')

@section('title', __('Бренди'))

@push('head')
    <x-seo-schema :schema="$seoSchema" />
@endpush

@section('content')
    <div class="container page-space brand-index">
        <div class="breadcrumbs"><a href="{{ localized_route('home') }}">{{ __('Головна') }}</a> / <span>{{ __('Бренди') }}</span></div>

        <header class="brand-index-header">
            <h1>{{ __('Бренди') }}</h1>
            <label class="brand-search">
                <span>{{ __('Пошук бренду') }}</span>
                <input type="search" data-brand-search placeholder="{{ __('Наприклад, Osprey') }}" autocomplete="off">
            </label>
        </header>

        @if($brands === [])
            <div class="blog-empty">{{ __('Брендів поки немає.') }}</div>
        @else
            <p class="brand-index-count">{{ __('Брендів: :count', ['count' => count($brands)]) }}</p>
            <nav class="brand-letters" aria-label="{{ __('Алфавіт') }}">
                @foreach(array_keys($brandGroups) as $letter)
                    <a href="#brand-letter-{{ $letter }}">{{ $letter }}</a>
                @endforeach
            </nav>
            @foreach($brandGroups as $letter => $group)
                <section class="brand-letter-group" id="brand-letter-{{ $letter }}">
                    <h2>{{ $letter }}</h2>
                    <div class="brand-grid">
                            @foreach($group as $brand)
                                @continue(! is_array($brand))
                                <a class="brand-card" href="{{ localized_route('brands.show', $brand['slug']) }}" data-brand-item data-brand-name="{{ mb_strtolower($brand['name']) }}">
                                <strong>{{ $brand['name'] }}</strong>
                                <span>{{ __(':count товарів', ['count' => $brand['products_count']]) }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endforeach
            <p class="brand-empty-search" data-brand-empty hidden>{{ __('Нічого не знайшли за цим запитом.') }}</p>
        @endif
    </div>
@endsection
