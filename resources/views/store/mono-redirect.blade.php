@extends('layouts.store')

@section('title', __('Перехід до оплати'))

@section('content')
    <div class="container page-space">
        <div class="empty-state success-state liqpay-redirect-state">
            <span>₴</span>
            <h1>{{ __('Переходимо до захищеної оплати') }}</h1>
            <p>{{ __('Для замовлення №:number сума буде лише заблокована на картці. Списання відбудеться після підтвердження менеджером.', ['number' => $order->number]) }}</p>
            <a class="primary-button" href="{{ $payment['page_url'] }}" rel="nofollow" data-mono-link>{{ __('Перейти до оплати') }}</a>
        </div>
    </div>
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        const monoLink = document.querySelector('[data-mono-link]');
        if (monoLink) window.location.replace(monoLink.href);
    </script>
@endsection
