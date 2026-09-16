@extends('layouts.store')

@section('title', __('Сторінку не знайдено'))

@push('head')
    <meta name="robots" content="noindex, follow">
@endpush

@section('content')
    <main class="container page-space">
        <section class="error-page">
            <div class="error-visual" aria-hidden="true">
                <span>404</span>
                <svg viewBox="0 0 220 160" role="img">
                    <path d="M19 116c35-32 62-45 91-43 36 2 54 30 91 19" fill="none" stroke="currentColor" stroke-width="9" stroke-linecap="round"/>
                    <path d="M54 72c20-28 42-43 70-45 30 9 49 29 63 61" fill="none" stroke="currentColor" stroke-width="8" stroke-linecap="round" stroke-linejoin="round" opacity=".35"/>
                    <path d="M68 111c26 11 57 12 92-2" fill="none" stroke="currentColor" stroke-width="7" stroke-linecap="round" opacity=".7"/>
                    <circle cx="151" cy="65" r="8" fill="currentColor" opacity=".55"/>
                </svg>
            </div>
            <div>
                <span class="auth-kicker">{{ __('Маршрут загубився') }}</span>
                <h1>{{ __('Сторінку не знайдено') }}</h1>
                <p>{{ __('Схоже, це посилання вже неактуальне або адреса введена з помилкою. Поверніться на головну чи відкрийте каталог, щоб швидко знайти потрібне спорядження.') }}</p>
                <div class="error-actions">
                    <a class="primary-button" href="{{ localized_route('home') }}">{{ __('На головну') }}</a>
                    <button class="quick-order-button" type="button" data-open-catalog>{{ __('Відкрити каталог') }}</button>
                </div>
            </div>
        </section>
    </main>
@endsection
