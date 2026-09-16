@extends('layouts.store')

@section('title', __('Реєстрація'))

@section('content')
    <div class="container page-space auth-page">
        <section class="auth-card">
            <span class="auth-kicker">{{ __('Новий клієнт') }}</span>
            <h1>{{ __('Створити профіль') }}</h1>
            <p>{{ __('Збережемо ваші замовлення в одному місці. Реєстрація займає менше хвилини.') }}</p>
            <x-auth.google-button />
            <div class="auth-divider">{{ __('або') }}</div>
            <form action="{{ localized_route('register.store') }}" method="POST">@csrf
                <label>{{ __('Прізвище') }}<input name="last_name" value="{{ old('last_name') }}" required autofocus></label>
                <label>{{ __("Ім'я") }}<input name="first_name" value="{{ old('first_name') }}" required></label>
                <label>{{ __('По батькові') }}<input name="patronymic" value="{{ old('patronymic') }}" required></label>
                <label>Email<input type="email" name="email" value="{{ old('email') }}" required></label>
                <label>{{ __('Телефон') }}<input type="tel" name="phone" value="{{ old('phone') }}" inputmode="tel" autocomplete="tel" placeholder="+380 99 123 45 67" required></label>
                <label>{{ __('Пароль') }}<input type="password" name="password" required></label>
                <label>{{ __('Повторіть пароль') }}<input type="password" name="password_confirmation" required></label>
                @if ($errors->any())<div class="validation-errors">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
                <button class="primary-button">{{ __('Зареєструватися') }}</button>
            </form>
            <div class="auth-switch">{{ __('Вже є профіль?') }} <a href="{{ localized_route('login') }}">{{ __('Увійти') }}</a></div>
        </section>
    </div>
@endsection
