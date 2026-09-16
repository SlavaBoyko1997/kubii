@extends('layouts.store')

@section('title', __('Оформлення замовлення'))

@push('analytics')
    <x-google-analytics-event name="begin_checkout" :payload="\App\Support\GoogleAnalytics::beginCheckoutPayload($items, $total)" />
@endpush

@section('content')
    <div class="container page-space">
        <div class="breadcrumbs"><a href="{{ localized_route('cart.index') }}">{{ __('Кошик') }}</a> / <span>{{ __('Оформлення') }}</span></div>
        <div class="checkout-hero">
            <span class="auth-kicker">Kubii checkout</span>
            <h1>{{ __('Оформлення замовлення') }}</h1>
        </div>
        <form class="checkout-layout" action="{{ localized_route('checkout.store') }}" method="POST" data-checkout-wizard data-account-check-url="{{ localized_route('checkout.account-check') }}">
            @csrf
            <nav class="checkout-progress" aria-label="{{ __('Стан оформлення') }}">
                <button type="button" data-checkout-step-target="contacts"><i>1</i><span><strong>{{ __('Контакти') }}</strong><small data-checkout-nav-status>{{ __('Потрібно заповнити') }}</small></span></button>
                <button type="button" data-checkout-step-target="order"><i>2</i><span><strong>{{ __('Ваше замовлення') }}</strong><small data-checkout-nav-status>{{ __('Перевірте товари') }}</small></span></button>
                <button type="button" data-checkout-step-target="delivery"><i>3</i><span><strong>{{ __('Доставка') }}</strong><small data-checkout-nav-status>{{ __('Потрібно заповнити') }}</small></span></button>
                <button type="button" data-checkout-step-target="payment"><i>4</i><span><strong>{{ __('Оплата') }}</strong><small data-checkout-nav-status>{{ __('Потрібно обрати') }}</small></span></button>
            </nav>
            <section class="checkout-form">
                <section class="checkout-step" id="checkout-contacts" data-checkout-section="contacts">
                    <div class="checkout-step-title"><span>1</span><div><h2>{{ __('Контакти клієнта') }}</h2><small data-checkout-section-status>{{ __("Заповніть обов'язкові поля") }}</small></div></div>
                    <div class="form-grid">
                        <label>{{ __('Прізвище') }} *<input name="last_name" value="{{ auth()->check() ? (auth()->user()->last_name ?: old('last_name')) : old('last_name') }}" @readonly(auth()->check() && filled(auth()->user()->last_name)) @class(['readonly-input' => auth()->check() && filled(auth()->user()->last_name)]) required></label>
                        <label>{{ __("Ім'я") }} *<input name="first_name" value="{{ auth()->check() ? (auth()->user()->first_name ?: old('first_name')) : old('first_name') }}" @readonly(auth()->check() && filled(auth()->user()->first_name)) @class(['readonly-input' => auth()->check() && filled(auth()->user()->first_name)]) required></label>
                        <label>{{ __('По батькові') }} *<input name="patronymic" value="{{ auth()->check() ? (auth()->user()->patronymic ?: old('patronymic')) : old('patronymic') }}" @readonly(auth()->check() && filled(auth()->user()->patronymic)) @class(['readonly-input' => auth()->check() && filled(auth()->user()->patronymic)]) required></label>
                        <label>{{ __('Телефон') }} *<input type="tel" name="phone" value="{{ auth()->check() ? auth()->user()->phone : old('phone') }}" inputmode="tel" autocomplete="tel" placeholder="+380 99 123 45 67" @auth readonly class="readonly-input" @endauth required></label>
                        <label>Email *<input type="email" name="email" value="{{ auth()->check() ? auth()->user()->email : old('email') }}" @auth readonly class="readonly-input" @endauth required></label>
                    </div>
                    @guest
                        <div class="checkout-account-prompt" data-checkout-account-prompt data-login-email="{{ session('checkout_login_email') }}" @unless(session('checkout_existing_account')) hidden @endunless>
                            <span class="checkout-account-icon">✓</span>
                            <div><strong>{{ __('Схоже, у вас уже є акаунт') }}</strong><p data-checkout-account-message>{{ __('Увійдіть, щоб продовжити оформлення з даними вашого профілю.') }}</p></div>
                            <button type="button" data-checkout-login>{{ __('Увійти') }}</button>
                        </div>
                    @endguest
                </section>
                @if ($errors->any())<div class="validation-errors">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
                <section class="checkout-order checkout-step" id="checkout-order" data-checkout-section="order" data-checkout-order>
                    @include('store._checkout-order')
                </section>
                <section class="checkout-step" id="checkout-delivery" data-checkout-section="delivery">
                    <div class="checkout-step-title nova-poshta-title">
                        <span>3</span>
                        <div class="nova-poshta-title-copy"><h2>{{ __('Доставка Новою Поштою') }}</h2><small data-checkout-section-status>{{ __('Оберіть місто та точку доставки') }}</small></div>
                        <div class="nova-poshta-brand" aria-label="{{ __('Нова Пошта') }}">
                            <img src="{{ asset('images/nova-poshta-logo.png') }}" alt="{{ __('Нова Пошта') }}" width="1654" height="651">
                        </div>
                    </div>
                    <p class="nova-poshta-intro">{{ __('Вкажіть місто, оберіть зручний формат отримання і знайдіть потрібну точку або адресу доставки.') }}</p>
                    @php($selectedDeliveryType = old('delivery_type', $checkoutDefaults['delivery_type']))
                    <div
                        class="form-grid nova-poshta-delivery"
                        data-nova-poshta
                        data-cities-url="{{ route('api.nova-poshta.cities') }}"
                        data-warehouses-url="{{ route('api.nova-poshta.warehouses') }}"
                        data-postomats-url="{{ route('api.nova-poshta.postomats') }}"
                        data-streets-url="{{ route('api.nova-poshta.streets') }}"
                        data-delivery-price-url="{{ route('api.nova-poshta.delivery-price') }}"
                        data-selected-warehouse="{{ old('nova_poshta_warehouse_ref', $checkoutDefaults['nova_poshta_warehouse_ref']) }}"
                        data-selected-street="{{ old('nova_poshta_street_ref', $checkoutDefaults['nova_poshta_street_ref']) }}"
                        data-postomat-available="{{ $shipmentProfile['postomat_available'] ? '1' : '0' }}"
                        data-cargo-only="{{ $shipmentProfile['requires_cargo_warehouse'] ? '1' : '0' }}"
                    >
                        <div class="wide nova-poshta-stage">
                            <div class="nova-poshta-stage-head"><b>1</b><div><strong>{{ __('Оберіть місто') }}</strong><small>{{ __('Почніть вводити назву та виберіть місто зі списку') }}</small></div></div>
                            <label class="nova-poshta-city">
                                <span class="sr-only">{{ __('Місто') }}</span>
                                <input
                                    name="nova_poshta_city_name"
                                    value="{{ old('nova_poshta_city_name', $checkoutDefaults['nova_poshta_city_name']) }}"
                                    placeholder="{{ __('Наприклад, Київ') }}"
                                    autocomplete="off"
                                    data-nova-poshta-city-input
                                    required
                                >
                                <input type="hidden" name="nova_poshta_city_ref" value="{{ old('nova_poshta_city_ref', $checkoutDefaults['nova_poshta_city_ref']) }}" data-nova-poshta-city-ref>
                                <div class="nova-poshta-suggestions" data-nova-poshta-cities hidden></div>
                            </label>
                        </div>
                        <div class="wide nova-poshta-stage">
                            <div class="nova-poshta-stage-head"><b>2</b><div><strong>{{ __('Як бажаєте отримати замовлення?') }}</strong><small>{{ __('Відділення приймає більші посилки, поштомат зручний для самостійного отримання') }}</small></div></div>
                            <fieldset class="nova-poshta-types">
                                <legend class="sr-only">{{ __('Тип доставки') }}</legend>
                                <label>
                                    <input type="radio" name="delivery_type" value="nova_poshta_warehouse" @checked($selectedDeliveryType === 'nova_poshta_warehouse' || (! $shipmentProfile['postomat_available'] && ! in_array($selectedDeliveryType, ['nova_poshta_postomat', 'nova_poshta_courier'], true)))>
                                    <svg class="nova-poshta-type-icon" viewBox="0 0 32 32" aria-hidden="true">
                                        <path d="M5 13.5 16 5l11 8.5v13H5v-13Z"/>
                                        <path d="M11 26V16h10v10M9 12h14"/>
                                    </svg>
                                    <span><strong>{{ __('Відділення') }}</strong><small>{{ __('Отримання у відділенні Нової Пошти') }}</small></span>
                                </label>
                                <label class="{{ $shipmentProfile['postomat_available'] ? '' : 'is-disabled' }}">
                                    <input type="radio" name="delivery_type" value="nova_poshta_postomat" @checked($selectedDeliveryType === 'nova_poshta_postomat' && $shipmentProfile['postomat_available']) @disabled(! $shipmentProfile['postomat_available'])>
                                    <svg class="nova-poshta-type-icon" viewBox="0 0 32 32" aria-hidden="true">
                                        <rect x="7" y="4" width="18" height="24" rx="2"/>
                                        <path d="M7 12h18M16 12v16M11.5 8h3M19.5 8h1M10.5 17h2M19.5 17h2M10.5 23h2M19.5 23h2"/>
                                    </svg>
                                    <span>
                                        <strong>{{ __('Поштомат') }}</strong>
                                        <small>{{ $shipmentProfile['postomat_available'] ? __('Самостійне отримання без черги') : __('Недоступний через вагу або габарити товарів') }}</small>
                                    </span>
                                </label>
                                <label>
                                    <input type="radio" name="delivery_type" value="nova_poshta_courier" @checked($selectedDeliveryType === 'nova_poshta_courier')>
                                    <svg class="nova-poshta-type-icon" viewBox="0 0 32 32" aria-hidden="true">
                                        <path d="M4 18h17l3 4h4v-8H4v4Z"/>
                                        <circle cx="10" cy="24" r="2.5"/>
                                        <circle cx="23" cy="24" r="2.5"/>
                                        <path d="M21 10h6v8"/>
                                    </svg>
                                    <span><strong>{{ __("Кур'єрська доставка") }}</strong><small>{{ __('Доставка за вашою адресою у вибраному місті') }}</small></span>
                                </label>
                            </fieldset>
                            @if($shipmentProfile['requires_cargo_warehouse'])
                                <div class="nova-poshta-cargo-notice">
                                    <strong>{{ __('Потрібне вантажне відділення') }}</strong>
                                    <span>{{ __('У кошику є великогабаритний або важкий товар. Ми покажемо лише відділення, які можуть прийняти таке відправлення.') }}</span>
                                </div>
                            @endif
                        </div>
                        <div class="wide nova-poshta-stage" data-nova-poshta-point-stage @if($selectedDeliveryType === 'nova_poshta_courier') hidden @endif>
                            <div class="nova-poshta-stage-head"><b>3</b><div><strong data-nova-poshta-point-title>{{ __('Оберіть відділення') }}</strong><small data-nova-poshta-point-help>{{ __('Шукайте за номером відділення, вулицею або адресою') }}</small></div></div>
                            <label class="nova-poshta-point-search">
                                <span class="sr-only" data-nova-poshta-point-label>{{ __('Відділення') }}</span>
                                <input
                                    name="nova_poshta_warehouse_search"
                                    value="{{ old('nova_poshta_warehouse_search', $checkoutDefaults['nova_poshta_warehouse_name']) }}"
                                    placeholder="{{ __('Спочатку оберіть місто') }}"
                                    autocomplete="off"
                                    data-nova-poshta-point-input
                                    @required($selectedDeliveryType !== 'nova_poshta_courier')
                                    @disabled($selectedDeliveryType === 'nova_poshta_courier')
                                >
                                <input type="hidden" name="nova_poshta_warehouse_ref" value="{{ old('nova_poshta_warehouse_ref', $checkoutDefaults['nova_poshta_warehouse_ref']) }}" data-nova-poshta-point-ref>
                                <div class="nova-poshta-suggestions nova-poshta-point-results" data-nova-poshta-points hidden></div>
                            </label>
                            <div class="nova-poshta-selected" data-nova-poshta-selected hidden></div>
                        </div>
                        <div class="wide nova-poshta-stage" data-nova-poshta-courier-stage @unless($selectedDeliveryType === 'nova_poshta_courier') hidden @endunless>
                            <div class="nova-poshta-stage-head"><b>3</b><div><strong>{{ __('Адреса доставки') }}</strong><small>{{ __('Вкажіть вулицю, будинок і за потреби квартиру') }}</small></div></div>
                            <label class="nova-poshta-street-search">
                                <span class="sr-only">{{ __('Вулиця') }}</span>
                                <input
                                    name="nova_poshta_street_name"
                                    value="{{ old('nova_poshta_street_name', $checkoutDefaults['nova_poshta_street_name']) }}"
                                    placeholder="{{ __('Спочатку оберіть місто') }}"
                                    autocomplete="off"
                                    data-nova-poshta-street-input
                                    @required($selectedDeliveryType === 'nova_poshta_courier')
                                    @disabled($selectedDeliveryType !== 'nova_poshta_courier')
                                >
                                <input type="hidden" name="nova_poshta_street_ref" value="{{ old('nova_poshta_street_ref', $checkoutDefaults['nova_poshta_street_ref']) }}" data-nova-poshta-street-ref>
                                <input type="hidden" name="nova_poshta_street_description" value="{{ old('nova_poshta_street_description') }}" data-nova-poshta-street-description-input>
                                <div class="nova-poshta-suggestions" data-nova-poshta-streets hidden></div>
                            </label>
                            <div class="form-grid nova-poshta-address-grid">
                                <label>{{ __('Будинок') }} *
                                    <input
                                        name="nova_poshta_building"
                                        value="{{ old('nova_poshta_building', $checkoutDefaults['nova_poshta_building']) }}"
                                        placeholder="{{ __('Наприклад, 10') }}"
                                        data-nova-poshta-building-input
                                        @required($selectedDeliveryType === 'nova_poshta_courier')
                                        @disabled($selectedDeliveryType !== 'nova_poshta_courier')
                                    >
                                </label>
                                <label>{{ __('Квартира') }} <small>{{ __('необов’язково') }}</small>
                                    <input
                                        name="nova_poshta_flat"
                                        value="{{ old('nova_poshta_flat', $checkoutDefaults['nova_poshta_flat']) }}"
                                        placeholder="{{ __('Наприклад, 12') }}"
                                        data-nova-poshta-flat-input
                                        @disabled($selectedDeliveryType !== 'nova_poshta_courier')
                                    >
                                </label>
                            </div>
                        </div>
                        <div class="wide nova-poshta-state" data-nova-poshta-state role="status" hidden></div>
                        <label class="wide nova-poshta-comment">{{ __('Коментар до доставки') }} <small>{{ __('необов’язково') }}</small><textarea name="comment" rows="3" placeholder="{{ __('Наприклад, зателефонуйте перед відправленням') }}">{{ old('comment') }}</textarea></label>
                    </div>
                </section>
                <section class="checkout-step" id="checkout-payment" data-checkout-section="payment">
                    <div class="checkout-step-title"><span>4</span><div><h2>{{ __('Оплата') }}</h2><small data-checkout-section-status>{{ __('Оберіть зручний спосіб оплати') }}</small></div></div>
                    <fieldset class="checkout-payment-options">
                        <legend class="sr-only">{{ __('Спосіб оплати') }}</legend>
                        @forelse($paymentOptions as $paymentOption)
                            <label>
                                <input type="radio" name="payment_method" value="{{ $paymentOption->code }}" @checked(old('payment_method', $checkoutDefaults['payment_method']) === $paymentOption->code) required>
                                @include('store._payment-icon', ['method' => $paymentOption->code])
                                <span>
                                    <strong>{{ __($paymentOption->name) }}</strong>
                                    @if($paymentOption->code === 'iban')
                                        <small>{{ __('Після оформлення менеджер надасть реквізити для переказу') }}</small>
                                    @elseif($paymentOption->code === 'mono_checkout')
                                        <small>{{ __('Google Pay, Apple Pay або карткою. Сума блокується до підтвердження менеджером') }}</small>
                                    @elseif($paymentOption->code === 'cash_on_delivery')
                                        <small>{{ __('Оплата під час отримання у відділенні або поштоматі') }}</small>
                                    @endif
                                </span>
                            </label>
                        @empty
                            <div class="checkout-payment-unavailable">{{ __('Наразі немає доступних способів оплати. Зверніться до менеджера магазину.') }}</div>
                        @endforelse
                    </fieldset>
                </section>
            </section>
            <aside class="cart-summary checkout-summary-card" id="checkout-summary" data-checkout-section="summary" data-checkout-summary>@include('store._checkout-summary')</aside>
        </form>
    </div>
@endsection
