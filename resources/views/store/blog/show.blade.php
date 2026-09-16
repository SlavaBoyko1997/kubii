@extends('layouts.store')

@section('title', $post->seoTitle())

@push('head')
    <x-seo-schema :schema="$seoSchema" />
    @if(filled($post->safeCustomCss()))
        <style nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">{!! $post->safeCustomCss() !!}</style>
    @endif
@endpush

@section('content')
    <article class="blog-post">
        @if($post->bannerImageUrl())
            <div class="blog-post-banner">
                <img src="{{ $post->bannerImageUrl() }}" alt="" width="1600" height="640" fetchpriority="high" decoding="async">
            </div>
        @endif

        <div class="container page-space blog-post-body">
            <div class="breadcrumbs">
                <a href="{{ localized_route('home') }}">{{ __('Головна') }}</a> /
                <a href="{{ localized_route('blog.index') }}">{{ __('Блог') }}</a> /
                <span>{{ $post->title }}</span>
            </div>

            <header class="blog-post-header">
                <time datetime="{{ $post->published_at?->toAtomString() }}">{{ $post->published_at?->format('d.m.Y') }}</time>
                <h1>{{ $post->title }}</h1>
                @if(filled($post->short_description))
                    <p class="blog-post-lead">{{ $post->short_description }}</p>
                @endif
            </header>

            <div class="blog-post-content">
                {!! $post->renderedContent() !!}
            </div>
        </div>
    </article>
@endsection
