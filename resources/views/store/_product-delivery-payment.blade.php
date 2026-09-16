<section class="product-service-panel" aria-label="{{ __('Доставка та оплата') }}">
    <div class="product-service-row">
        <div class="product-service-aside">
            <span class="product-service-aside-icon" aria-hidden="true">
                <img src="{{ asset('images/nova-poshta-logo.png') }}" alt="" width="1654" height="651" loading="lazy" decoding="async">
            </span>
            <span class="product-service-aside-label">{{ __('Доставка') }}</span>
        </div>
        <div class="product-service-main">
            <div class="product-service-chips">
                <span class="product-service-chip">
                    <svg viewBox="0 0 32 32" aria-hidden="true"><path d="M5 13.5 16 5l11 8.5v13H5v-13Z"/><path d="M11 26V16h10v10M9 12h14"/></svg>
                    {{ __('Відділення') }}
                </span>
                <span @class(['product-service-chip', 'is-muted' => ! $shipmentProfile['postomat_available']])>
                    <svg viewBox="0 0 32 32" aria-hidden="true"><rect x="7" y="4" width="18" height="24" rx="2"/><path d="M7 12h18M16 12v16M11.5 8h3M19.5 8h1"/></svg>
                    {{ __('Поштомат') }}
                </span>
                <span class="product-service-chip">
                    <svg viewBox="0 0 32 32" aria-hidden="true"><path d="M4 18h17l3 4h4v-8H4v4Z"/><circle cx="10" cy="24" r="2.5"/><circle cx="23" cy="24" r="2.5"/></svg>
                    {{ __("Кур'єр") }}
                </span>
            </div>
            <p class="product-service-hint">{{ __('Нова Пошта по Україні') }}</p>
            <p class="product-service-free">{{ \App\Support\FreeDelivery::promoLabel() }}</p>
        </div>
    </div>
    <div class="product-service-row">
        <div class="product-service-aside">
            <span class="product-service-aside-icon is-payment" aria-hidden="true">₴</span>
            <span class="product-service-aside-label">{{ __('Оплата') }}</span>
        </div>
        <div class="product-service-main">
            @if($paymentOptions->isNotEmpty())
                <div class="product-service-payments">
                    @foreach($paymentOptions as $paymentOption)
                        <span class="product-service-pay" title="{{ __($paymentOption->name) }}">
                            @include('store._payment-icon', ['method' => $paymentOption->code])
                            <span>{{ __($paymentOption->name) }}</span>
                        </span>
                    @endforeach
                </div>
            @else
                <p class="product-service-hint">{{ __('Уточнюйте спосіб оплати у менеджера') }}</p>
            @endif
        </div>
    </div>
    <a class="product-service-more" href="{{ localized_route('pages.show', 'delivery') }}">{{ __('Детальніше про доставку та оплату') }} <span aria-hidden="true">→</span></a>
</section>
