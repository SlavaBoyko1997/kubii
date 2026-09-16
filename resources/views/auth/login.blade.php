@extends('layouts.store')

@section('title', __('Вхід'))

@section('content')
    <div class="container page-space auth-page">
        <section class="auth-card">
            <span class="auth-kicker">{{ __('Особистий кабінет') }}</span>
            <h1>{{ __('З поверненням') }}</h1>
            <p>{{ __('Увійдіть, щоб бачити історію замовлень і швидше оформлювати покупки.') }}</p>
            <x-auth.google-button />
            <div class="auth-divider">{{ __('або') }}</div>
            <form action="{{ localized_route('login.store') }}" method="POST">@csrf
                <label>{{ __('Email або номер телефону') }}<input name="login" value="{{ old('login', old('email')) }}" inputmode="email" autocomplete="username" placeholder="{{ __('example@email.com або +380 99 123 45 67') }}" required autofocus></label>
                <label>{{ __('Пароль') }}<input type="password" name="password" required></label>
                <label class="check-label"><input type="checkbox" name="remember" value="1"> {{ __("Запам'ятати мене") }}</label>
                @if ($errors->any())<div class="validation-errors">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
                <button class="primary-button">{{ __('Увійти') }}</button>
            </form>
            <div class="auth-switch">{{ __('Ще немає профілю?') }} <a href="{{ localized_route('register') }}">{{ __('Зареєструватися') }}</a></div>
        </section>
    </div>
@endsection
