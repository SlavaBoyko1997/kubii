@extends('layouts.store')

@section('title', __('Перехід до оплати'))

@section('content')
    <div class="container page-space">
        <div class="empty-state success-state liqpay-redirect-state">
            <span>₴</span>
            <h1>{{ __('Переходимо до захищеної оплати') }}</h1>
            <p>{{ __('Для замовлення №:number сума буде лише заблокована на картці. Списання відбудеться після підтвердження менеджером.', ['number' => $order->number]) }}</p>
            <a class="primary-button" href="{{ $payment['checkout_url'] }}" rel="nofollow" data-liqpay-link>{{ __('Перейти до LiqPay') }}</a>
        </div>
    </div>
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        const liqPayLink = document.querySelector('[data-liqpay-link]');
        if (liqPayLink) window.location.replace(liqPayLink.href);
    </script>
@endsection
