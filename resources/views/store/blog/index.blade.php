@extends('layouts.store')

@section('title', __('Блог Kubii'))

@push('head')
    <x-seo-schema :schema="$seoSchema" />
@endpush

@section('content')
    <div class="container page-space blog-index">
        <div class="breadcrumbs"><a href="{{ localized_route('home') }}">{{ __('Головна') }}</a> / <span>{{ __('Блог') }}</span></div>

        <header class="blog-index-header">
            <h1>{{ __('Блог Kubii') }}</h1>
            <p>{{ __('Корисні статті про туризм, кемпінг, риболовлю та вибір спорядження.') }}</p>
        </header>

        @if($posts->isEmpty())
            <div class="blog-empty">{{ __('Статей поки немає. Загляньте пізніше.') }}</div>
        @else
            <div class="blog-grid">
                @foreach($posts as $post)
                    <article class="blog-card">
                        <a class="blog-card-image" href="{{ $post->url() }}" aria-hidden="true" tabindex="-1">
                            <img src="{{ $post->cardImageUrl() }}" alt="" width="640" height="400" loading="lazy" decoding="async">
                        </a>
                        <div class="blog-card-body">
                            @if($post->is_featured)
                                <span class="blog-card-badge">{{ __('Рекомендуємо') }}</span>
                            @endif
                            <time class="blog-card-date" datetime="{{ $post->published_at?->toDateString() }}">{{ $post->published_at?->format('d.m.Y') }}</time>
                            <h2><a href="{{ $post->url() }}">{{ $post->title }}</a></h2>
                            @if(filled($post->short_description))
                                <p>{{ $post->short_description }}</p>
                            @endif
                            <a class="blog-card-link" href="{{ $post->url() }}">{{ __('Читати') }} →</a>
                        </div>
                    </article>
                @endforeach
            </div>

            @if($posts->hasPages())
                <nav class="blog-pagination">{{ $posts->links() }}</nav>
            @endif
        @endif
    </div>
@endsection
