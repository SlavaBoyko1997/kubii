@extends('layouts.store')

@section('title', __('Особистий кабінет'))

@section('content')
    <div class="container page-space">
        <div class="account-head"><div><span class="auth-kicker">{{ __('Особистий кабінет') }}</span><h1>{{ __('Вітаємо, :name', ['name' => auth()->user()->fullName()]) }}</h1><p>{{ auth()->user()->email }} @if(auth()->user()->phone)<span>•</span> {{ auth()->user()->phone }}@endif</p></div><form action="{{ localized_route('logout') }}" method="POST">@csrf<button class="secondary-button">{{ __('Вийти') }}</button></form></div>
        <section class="account-profile-card">
            <div><span class="eyebrow">{{ __('Ваші дані') }}</span><h2>{{ __('Профіль покупця') }}</h2><p>{{ __('Заповнені дані доступні тільки для перегляду. Якщо частина ПІБ або телефон ще не вказані, їх можна додати один раз.') }}</p></div>
            <dl>
                <div><dt>{{ __('Прізвище') }}</dt><dd>{{ auth()->user()->last_name ?: __('Не вказано') }}</dd></div>
                <div><dt>{!! __("Ім'я") !!}</dt><dd>{{ auth()->user()->first_name ?: __('Не вказано') }}</dd></div>
                <div><dt>{{ __('По батькові') }}</dt><dd>{{ auth()->user()->patronymic ?: __('Не вказано') }}</dd></div>
                <div><dt>Email</dt><dd>{{ auth()->user()->email }}</dd></div>
                <div><dt>{{ __('Телефон') }}</dt><dd>{{ auth()->user()->phone ?: __('Не вказано') }}</dd></div>
                <div><dt>{{ __('Дата реєстрації') }}</dt><dd>{{ auth()->user()->created_at->format('d.m.Y H:i') }}</dd></div>
            </dl>
            @unless(auth()->user()->phone)
                <form class="account-phone-form" action="{{ localized_route('account.phone.store') }}" method="POST">@csrf
                    <label>{{ __('Додати телефон') }}<input type="tel" name="phone" value="{{ old('phone') }}" inputmode="tel" autocomplete="tel" placeholder="+380 99 123 45 67" required></label>
                    <button class="primary-button">{{ __('Зберегти телефон') }}</button>
                    @if ($errors->has('phone'))<div class="validation-errors"><p>{{ $errors->first('phone') }}</p></div>@endif
                </form>
            @endunless
            @if(blank(auth()->user()->last_name) || blank(auth()->user()->first_name) || blank(auth()->user()->patronymic))
                <form class="account-phone-form" action="{{ localized_route('account.profile.store') }}" method="POST">@csrf
                    @if(blank(auth()->user()->last_name))<label>{{ __('Прізвище') }}<input name="last_name" value="{{ old('last_name') }}" required></label>@endif
                    @if(blank(auth()->user()->first_name))<label>{!! __("Ім'я") !!}<input name="first_name" value="{{ old('first_name') }}" required></label>@endif
                    @if(blank(auth()->user()->patronymic))<label>{{ __('По батькові') }}<input name="patronymic" value="{{ old('patronymic') }}"></label>@endif
                    <button class="primary-button">{{ __('Зберегти дані') }}</button>
                    @if ($errors->has('last_name') || $errors->has('first_name') || $errors->has('patronymic'))<div class="validation-errors">@foreach(array_merge($errors->get('last_name'), $errors->get('first_name'), $errors->get('patronymic')) as $error)<p>{{ $error }}</p>@endforeach</div>@endif
                </form>
            @endif
        </section>
        <section class="account-orders">
            <div class="section-heading"><div><span class="eyebrow">{{ __('Історія покупок') }}</span><h2>{{ __('Мої замовлення') }}</h2></div><span class="account-orders-count">{{ __('Замовлень: :count', ['count' => $orders->total()]) }}</span></div>
            @forelse($orders as $order)
                @php
                    $statusLabel = __(match($order->status) {
                        'new' => 'Нове',
                        'confirmed' => 'Підтверджене',
                        'shipped' => 'Відправлене',
                        'completed' => 'Виконане',
                        'cancelled' => 'Скасоване',
                        default => $order->status,
                    });
                @endphp
                <details class="account-order" @if($loop->first) open @endif>
                    <summary>
                        <div class="account-order-main"><span class="account-order-number">{{ $order->number }}</span><span>{{ $order->created_at->format('d.m.Y H:i') }}</span></div>
                        <div class="account-order-total"><span>{{ $order->order_type === 'quick' ? __('Швидке замовлення') : __('Звичайне замовлення') }}</span><strong>{{ number_format($order->total, 0, ',', ' ') }} ₴</strong></div>
                        <b class="order-status status-{{ $order->status }}">{{ $statusLabel }}</b>
                        <i aria-hidden="true">⌄</i>
                    </summary>
                    <div class="account-order-details">
                        @if($order->order_type === 'quick')<div class="quick-order-note"><strong>{{ __('Швидке замовлення') }}</strong><span>{{ __("Менеджер зателефонує, щоб уточнити ім'я, доставку та спосіб оплати.") }}</span></div>@endif
                        <div class="account-order-products">
                            <h3>{{ __('Товари у замовленні') }}</h3>
                            @foreach($order->items as $item)
                                <div class="account-order-product">
                                    @if($item->product)<a href="{{ $item->product->url() }}"><img src="{{ $item->product->imageUrl() }}" alt="{{ $item->product_name }}"></a>@else<div class="account-order-image-placeholder">FT</div>@endif
                                    <div>@if($item->product)<a href="{{ $item->product->url() }}"><strong>{{ $item->product_name }}</strong></a>@else<strong>{{ $item->product_name }}</strong>@endif<span>{{ number_format($item->price, 0, ',', ' ') }} ₴ × {{ $item->quantity }}</span></div>
                                    <b>{{ number_format($item->subtotal, 0, ',', ' ') }} ₴</b>
                                </div>
                            @endforeach
                        </div>
                        @if($order->delivery_type && str_starts_with($order->delivery_type, 'nova_poshta_'))
                            @php
                                $isPostomat = $order->delivery_type === 'nova_poshta_postomat';
                                $isCourier = $order->delivery_type === 'nova_poshta_courier';
                                $deliveryCity = $order->nova_poshta_city_name ?: $order->city;
                                $deliveryPointName = $order->nova_poshta_warehouse_name ?: $order->delivery_address;
                                $deliveryAddress = $order->nova_poshta_warehouse_address ?: $order->delivery_address;
                            @endphp
                            <section class="account-delivery-card">
                                <div class="account-delivery-brand">
                                    <img src="{{ asset('images/nova-poshta-logo.png') }}" alt="{{ __('Нова Пошта') }}" width="1654" height="651">
                                    <div><span>{{ __('Спосіб доставки') }}</span><strong>{{ __('Доставка Новою Поштою') }}</strong></div>
                                </div>
                                <div class="account-delivery-grid">
                                    <div><span>{{ __('Тип отримання') }}</span><strong>{{ $isCourier ? __("Кур'єрська доставка") : ($isPostomat ? __('Поштомат') : __('Відділення')) }}</strong></div>
                                    @if($deliveryCity)
                                        <div><span>{{ __('Місто') }}</span><strong>{{ $deliveryCity }}</strong></div>
                                    @endif
                                    @if($isCourier)
                                        @if($order->nova_poshta_street_name)
                                            <div class="wide"><span>{{ __('Адреса доставки') }}</span><strong>{{ $order->delivery_address ?: $order->nova_poshta_street_name }}</strong></div>
                                        @endif
                                    @else
                                        @if($order->nova_poshta_warehouse_number)
                                            <div><span>{{ $isPostomat ? __('Номер поштомату') : __('Номер відділення') }}</span><strong>№{{ $order->nova_poshta_warehouse_number }}</strong></div>
                                        @endif
                                        @if($deliveryPointName)
                                            <div class="wide"><span>{{ $isPostomat ? __('Обраний поштомат') : __('Обране відділення') }}</span><strong>{{ $deliveryPointName }}</strong></div>
                                        @endif
                                        @if($deliveryAddress && $deliveryAddress !== $deliveryPointName)
                                            <div class="wide account-delivery-address"><span>{{ __('Адреса отримання') }}</span><strong>{{ $deliveryAddress }}</strong></div>
                                        @endif
                                    @endif
                                </div>
                            </section>
                        @elseif($order->delivery_address)
                            <section class="account-delivery-card account-delivery-legacy">
                                <div class="account-delivery-brand"><div><span>{{ __('Спосіб доставки') }}</span><strong>{{ __('Доставка') }}</strong></div></div>
                                <div class="account-delivery-grid">
                                    @if($order->city)
                                        <div><span>{{ __('Місто') }}</span><strong>{{ $order->city }}</strong></div>
                                    @endif
                                    <div class="wide"><span>{{ __('Адреса отримання') }}</span><strong>{{ $order->delivery_address }}</strong></div>
                                </div>
                            </section>
                        @endif
                        @php
                            $paymentLabel = __(match($order->payment_method) {
                                'liqpay_hold' => 'Карткою на сайті — LiqPay',
                                'mono_checkout' => 'Онлайн-оплата карткою',
                                'iban' => 'Оплата на IBAN',
                                'cash_on_delivery' => 'Післяплата у Новій Пошті',
                                'card_on_delivery' => 'Карткою при отриманні',
                                default => $order->payment_method,
                            });
                            $paymentStatusLabel = __(match($order->payment_status) {
                                'holded' => 'Кошти заблоковано',
                                'paid' => 'Сплачено',
                                'failed' => 'Оплата неуспішна',
                                'cancelled', 'reversed' => 'Холд скасовано',
                                default => 'Очікується оплата',
                            });
                        @endphp
                        <section class="account-payment-card">
                            @include('store._payment-icon', ['method' => $order->payment_method])
                            <div class="account-payment-copy">
                                <span>{{ __('Спосіб оплати') }}</span>
                                <strong>{{ $paymentLabel }}</strong>
                                @if($order->usesOnlineHoldPayment() && $order->payment_status === 'holded')
                                    <small>{{ __('Кошти вже заблоковано. Повторно оплачувати замовлення не потрібно.') }}</small>
                                @elseif($order->usesOnlineHoldPayment() && $order->payment_status === 'pending')
                                    <small>{{ __('Завершіть оплату карткою, щоб заблокувати суму замовлення.') }}</small>
                                @endif
                            </div>
                            <div class="account-payment-state">
                                @if($order->usesOnlineHoldPayment() && $order->payment_amount)
                                    <b>{{ number_format((float) $order->payment_amount, 0, ',', ' ') }} ₴</b>
                                @endif
                                <strong class="account-payment-status account-payment-status-{{ $order->payment_status }}">{{ $paymentStatusLabel }}</strong>
                            </div>
                            @if($order->usesOnlineHoldPayment() && $order->payment_status === 'pending' && $order->onlinePaymentCheckoutRoute())
                                <a class="primary-button account-pay-button" href="{{ $order->onlinePaymentCheckoutRoute() }}">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h18v11H3V7Zm0 4h18M7 15h4"/></svg>
                                    {{ __('Оплатити онлайн') }}
                                </a>
                            @endif
                        </section>
                        <div class="account-order-info">
                            <div><span>{{ __('Телефон') }}</span><strong>{{ $order->phone }}</strong></div>
                            @if($order->email)<div><span>Email</span><strong>{{ $order->email }}</strong></div>@endif
                            @if($order->usesOnlineHoldPayment() && $order->payment_amount)
                                <div><span>{{ __('Сума онлайн-оплати') }}</span><strong>{{ number_format((float) $order->payment_amount, 0, ',', ' ') }} ₴</strong></div>
                            @endif
                            @if($order->liqpay_hold_at || $order->mono_hold_at)
                                <div><span>{{ __('Дата блокування') }}</span><strong>{{ ($order->liqpay_hold_at ?? $order->mono_hold_at)->format('d.m.Y H:i') }}</strong></div>
                            @endif
                            @if($order->comment)<div class="wide"><span>{{ __('Коментар') }}</span><strong>{{ $order->comment }}</strong></div>@endif
                        </div>
                    </div>
                </details>
            @empty
                <div class="empty-state"><h3>{{ __('У вас ще немає замовлень') }}</h3><p>{{ __('Саме час обрати спорядження для наступної подорожі.') }}</p><a class="primary-button" href="{{ $catalogEntryUrl }}">{{ __('Перейти до каталогу') }}</a></div>
            @endforelse
            @include('store._catalog-pagination', [
                'paginator' => $orders,
                'ariaLabel' => __('Сторінки замовлень'),
                'itemsLabel' => __('Показано :from-:to з :total замовлень'),
                'paginationDataAttribute' => false,
            ])
        </section>
    </div>
@endsection
