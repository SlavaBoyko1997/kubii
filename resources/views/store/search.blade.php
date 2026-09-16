@extends('layouts.store')

@section('title', $query !== '' ? __('Пошук: :query', ['query' => $query]) : __('Пошук товарів'))

@push('head')
    <x-seo-schema :schema="$seoSchema" />
@endpush

@section('content')
    <section class="container page-space search-page">
        <div class="breadcrumbs"><a href="{{ localized_route('home') }}">{{ __('Головна') }}</a> / <span>{{ $query !== '' ? __('Пошук: :query', ['query' => $query]) : __('Пошук товарів') }}</span></div>
        <div class="section-heading">
            <div>
                <span class="eyebrow">{{ __('Розумний пошук') }}</span>
                <h1>{{ $query !== '' ? __('Результати для «:query»', ['query' => $query]) : __('Пошук товарів') }}</h1>
                @if($corrected)<p>{{ __('Також враховано запит:') }} <strong>{{ $corrected }}</strong></p>@endif
            </div>
        </div>

        @if($query === '')
            <div class="empty-state"><h2>{{ __('Що шукаємо?') }}</h2><p>{{ __('Введіть назву товару, категорію, бренд, модель або артикул у полі зверху.') }}</p></div>
        @elseif($products->isEmpty() && $categories->isEmpty())
            <div class="empty-state"><h2>{{ __('Нічого точного не знайшли') }}</h2><p>{{ __('Спробуйте коротшу назву або перевірте одне ключове слово.') }}</p></div>
        @else
            @if($categories->isNotEmpty())
                <div class="search-page-categories">
                    @foreach($categories as $category)
                        <a href="{{ $category->catalogUrl() }}" data-search-result data-search-type="category" data-search-id="{{ $category->id }}" data-search-query="{{ $query }}">
                            <x-heroicon-o-squares-2x2 />
                            <span><strong>{{ $category->name }}</strong><small>{{ $category->breadcrumbTrail()->pluck('name')->implode(' / ') }}</small></span>
                        </a>
                    @endforeach
                </div>
            @endif

            @if($products->isNotEmpty())
                <div class="search-page-products">
                    @foreach($products as $product)
                        <a href="{{ $product->url() }}" data-search-result data-search-type="product" data-search-id="{{ $product->id }}" data-search-query="{{ $query }}">
                            <img src="{{ $product->imageUrl() }}" alt="{{ $product->name }}" width="160" height="160" loading="lazy" decoding="async">
                            <span><strong>{{ $product->name }}</strong><small>{{ $product->category?->name }}@if($product->brand) · {{ $product->brand }}@endif</small></span>
                            <b>{{ $product->isPricePending() ? __('Ціна уточнюється') : number_format($product->salePrice(), 0, ',', ' ').' ₴' }}</b>
                        </a>
                    @endforeach
                </div>
            @endif
        @endif
    </section>
@endsection
