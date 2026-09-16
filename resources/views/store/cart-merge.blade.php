@extends('layouts.store')

@section('title', __('Об’єднання кошиків'))

@section('content')
    <div class="container page-space cart-merge-page">
        <div class="cart-merge-head">
            <span class="auth-kicker">{{ __('Кошик синхронізовано') }}</span>
            <h1>{{ __('У вас є товари у двох кошиках') }}</h1>
            <p>{{ __('Натисніть на кошик, який потрібно залишити, або об’єднайте всі товари.') }}</p>
        </div>
        <div class="cart-merge-actions">
            <span>{{ __('Хочете зберегти всі товари?') }}</span>
            <form action="{{ localized_route('cart.merge') }}" method="POST">@csrf<button class="primary-button">{{ __('Об’єднати обидва кошики') }}</button></form>
        </div>
        <div class="cart-merge-grid">
            <form class="cart-merge-option" action="{{ localized_route('cart.keep-device') }}" method="POST">
                @csrf
                <button class="cart-merge-card" type="submit" aria-label="{{ __('Залишити кошик цього пристрою') }}">
                    <span class="cart-merge-card-head"><span>{{ __('Цей пристрій') }}</span><strong>{{ $guestItems->sum('quantity') }} {{ __('товарів') }}</strong></span>
                    @foreach($guestItems as $item)
                        <span class="cart-merge-item">
                            <img src="{{ $item['product']->imageUrl() }}" alt="{{ $item['product']->name }}">
                            <span><strong>{{ $item['product']->name }}</strong><small>{{ $item['quantity'] }} × {{ number_format($item['product']->salePrice(), 0, ',', ' ') }} ₴</small></span>
                        </span>
                    @endforeach
                    <span class="cart-merge-total"><span>{{ __('Разом') }}</span><strong>{{ number_format($guestTotal, 0, ',', ' ') }} ₴</strong></span>
                    <span class="cart-choice-action"><span class="cart-choice-check">✓</span> {{ __('Залишити цей кошик') }}</span>
                </button>
            </form>
            <form class="cart-merge-option" action="{{ localized_route('cart.keep-account') }}" method="POST">
                @csrf
                <button class="cart-merge-card account-cart" type="submit" aria-label="{{ __('Залишити кошик акаунта') }}">
                    <span class="cart-merge-card-head"><span>{{ __('Ваш акаунт') }}</span><strong>{{ $accountItems->sum('quantity') }} {{ __('товарів') }}</strong></span>
                    @foreach($accountItems as $item)
                        <span class="cart-merge-item">
                            <img src="{{ $item['product']->imageUrl() }}" alt="{{ $item['product']->name }}">
                            <span><strong>{{ $item['product']->name }}</strong><small>{{ $item['quantity'] }} × {{ number_format($item['product']->salePrice(), 0, ',', ' ') }} ₴</small></span>
                        </span>
                    @endforeach
                    <span class="cart-merge-total"><span>{{ __('Разом') }}</span><strong>{{ number_format($accountTotal, 0, ',', ' ') }} ₴</strong></span>
                    <span class="cart-choice-action"><span class="cart-choice-check">✓</span> {{ __('Залишити цей кошик') }}</span>
                </button>
            </form>
        </div>
        <p class="cart-merge-note">{{ __('При об’єднанні кількість однакових товарів підсумовується в межах доступного залишку.') }}</p>
    </div>
@endsection
