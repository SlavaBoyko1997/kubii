@extends('layouts.store')

@section('title', __('Замовлення прийнято'))

@push('analytics')
    @if($trackPurchase ?? false)
        <x-google-analytics-event name="purchase" :payload="\App\Support\GoogleAnalytics::purchasePayload($order)" />
    @endif
    <x-google-customer-reviews-opt-in :order="$order" />
@endpush

@section('content')
    @php
        $isPostomat = $order->delivery_type === 'nova_poshta_postomat';
        $paymentLabel = __(match($order->payment_method) {
            'liqpay_hold' => 'Карткою на сайті — LiqPay',
            'mono_checkout' => 'Онлайн-оплата карткою',
            'iban' => 'Оплата на IBAN',
            'cash_on_delivery' => 'Післяплата у Новій Пошті',
            'card_on_delivery' => 'Карткою при отриманні',
            default => $order->payment_method,
        });
        $paymentStatus = __(match($order->payment_status) {
            'holded' => 'Кошти заблоковано',
            'paid' => 'Сплачено',
            'failed' => 'Оплата неуспішна',
            'reversed', 'cancelled' => 'Холд скасовано',
            default => 'Очікується оплата',
        });
    @endphp
    <div class="container page-space order-success-page">
        <header class="order-success-hero">
            <span class="order-success-check">✓</span>
            <div>
                <span class="eyebrow">{{ __('Замовлення успішно оформлено') }}</span>
                <h1>{{ __('Дякуємо за замовлення!') }}</h1>
                <div class="order-success-number">
                    <span>{{ __('Номер замовлення') }}</span>
                    <strong>№{{ $order->number }}</strong>
                </div>
                <p>{{ __('Ми перевіримо наявність товарів і зв’яжемося з вами для підтвердження.') }}</p>
            </div>
        </header>

        <div class="order-success-layout">
            <section class="order-success-card">
                <div class="order-success-card-head">
                    <div><span class="eyebrow">{{ __('Склад замовлення') }}</span><h2>{{ __('Ваші товари') }}</h2></div>
                    <strong>{{ trans_choice(':count товар|:count товари|:count товарів', $order->items->sum('quantity'), ['count' => $order->items->sum('quantity')]) }}</strong>
                </div>
                <div class="order-success-items">
                    @foreach($order->items as $item)
                        <article class="order-success-item">
                            @if($item->product)
                                <a href="{{ $item->product->url(false) }}"><img src="{{ $item->product->imageUrl() }}" alt="{{ $item->product_name }}"></a>
                            @else
                                <div class="order-success-image-placeholder">FT</div>
                            @endif
                            <div>
                                @if($item->product)
                                    <a href="{{ $item->product->url(false) }}"><strong>{{ $item->product_name }}</strong></a>
                                @else
                                    <strong>{{ $item->product_name }}</strong>
                                @endif
                                <span>{{ number_format((float) $item->price, 0, ',', ' ') }} ₴ × {{ $item->quantity }}</span>
                            </div>
                            <b>{{ number_format((float) $item->subtotal, 0, ',', ' ') }} ₴</b>
                        </article>
                    @endforeach
                </div>
            </section>

            <aside class="order-success-card order-success-summary">
                <div class="order-success-card-head"><div><span class="eyebrow">{{ __('Підсумок') }}</span><h2>{{ __('Деталі замовлення') }}</h2></div></div>
                <dl>
                    <div><dt>{{ __('Номер замовлення') }}</dt><dd>№{{ $order->number }}</dd></div>
                    <div><dt>{{ __('Сума замовлення') }}</dt><dd>{{ number_format((float) $order->total, 0, ',', ' ') }} ₴</dd></div>
                    <div><dt>{{ __('Доставка') }}</dt><dd>{{ $isPostomat ? __('Нова Пошта — поштомат') : __('Нова Пошта — відділення') }}</dd></div>
                    @if($order->nova_poshta_city_name || $order->city)
                        <div><dt>{{ __('Місто') }}</dt><dd>{{ $order->nova_poshta_city_name ?: $order->city }}</dd></div>
                    @endif
                    @if($order->nova_poshta_warehouse_name || $order->delivery_address)
                        <div><dt>{{ $isPostomat ? __('Поштомат') : __('Відділення') }}</dt><dd>{{ $order->nova_poshta_warehouse_name ?: $order->delivery_address }}</dd></div>
                    @endif
                    <div><dt>{{ __('Спосіб оплати') }}</dt><dd>{{ $paymentLabel }}</dd></div>
                    @if($order->usesOnlineHoldPayment())
                        <div><dt>{{ __('Статус оплати') }}</dt><dd><span class="account-payment-status account-payment-status-{{ $order->payment_status }}">{{ $paymentStatus }}</span></dd></div>
                    @endif
                </dl>
                @if($order->usesOnlineHoldPayment() && $order->payment_status === 'pending' && $order->onlinePaymentCheckoutRoute())
                    <a class="primary-button order-success-pay" href="{{ $order->onlinePaymentCheckoutRoute() }}">{{ __('Оплатити онлайн') }}</a>
                @endif
                <a class="secondary-button order-success-continue" href="{{ $catalogEntryUrl }}">{{ __('Продовжити покупки') }}</a>
            </aside>
        </div>
    </div>
@endsection
