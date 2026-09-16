@extends('layouts.store')

@section('title', __('Статус оплати'))

@section('content')
    <div class="container page-space">
        <div class="empty-state success-state">
            <span>{{ $order->payment_status === 'holded' ? '✓' : '₴' }}</span>
            <h1>{{ $order->payment_status === 'holded' ? __('Кошти успішно заблоковано') : __('Замовлення збережено') }}</h1>
            <p>
                {{ $paymentError ?? __('Поточний статус оплати: :status.', ['status' => $order->payment_status]) }}
                {{ __('Номер замовлення: :number.', ['number' => $order->number]) }}
            </p>
            <a class="primary-button" href="{{ localized_route('checkout.success', $order) }}">{{ __('Переглянути замовлення') }}</a>
        </div>
    </div>
@endsection
