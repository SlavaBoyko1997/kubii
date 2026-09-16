@extends('layouts.store')

@section('title', $title)

@push('head')
    <x-seo-schema :schema="$seoSchema" />
@endpush

@section('content')
    <div class="container page-space legal-page">
        <div class="breadcrumbs"><a href="{{ localized_route('home') }}">{{ __('Головна') }}</a> / <span>{{ $title }}</span></div>
        <article>
            <h1>{{ $title }}</h1>

            @foreach($sections as $section)
                @if(! empty($section['heading']))
                    <h2>{{ $section['heading'] }}</h2>
                @endif

                @foreach($section['paragraphs'] ?? [] as $paragraph)
                    <p>{{ $paragraph }}</p>
                @endforeach

                @if(! empty($section['items']))
                    <ul>
                        @foreach($section['items'] as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                @endif

                @if(! empty($section['details']))
                    <dl class="legal-page-details">
                        @foreach($section['details'] as $label => $detail)
                            <div>
                                <dt>{{ $label }}</dt>
                                <dd>
                                    @if(! empty($detail['href']))
                                        <a href="{{ $detail['href'] }}">{{ $detail['text'] }}</a>
                                    @else
                                        {{ $detail['text'] }}
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                @endif

                @if(! empty($section['links']))
                    <ul class="legal-page-links">
                        @foreach($section['links'] as $link)
                            <li><a href="{{ localized_route($link['route'], ...($link['params'] ?? [])) }}">{{ $link['label'] }}</a></li>
                        @endforeach
                    </ul>
                @endif
            @endforeach

            @if(($page ?? null) !== 'contacts' && \App\Support\StoreInfo::email())
                <p class="legal-page-contact">{{ __('Для уточнення інформації напишіть нам на') }} <a href="mailto:{{ \App\Support\StoreInfo::email() }}">{{ \App\Support\StoreInfo::email() }}</a>.</p>
            @endif
        </article>
    </div>
@endsection
