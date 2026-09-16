@php
    use App\Support\FreeDelivery;

    $regularTotal = $items->sum(fn ($item) => $item['product']->price * $item['quantity']);
    $savings = max(0, $regularTotal - $total);
    $deliveryIsFree = FreeDelivery::qualifies($total);
@endphp
<div class="summary-head"><span>{{ __('Підсумки') }}</span><h2>{{ __('Товари') }} {{ $items->sum('quantity') }}</h2></div>
<p class="summary-delivery-note">{{ __('Відправка після підтвердження менеджером') }}</p>
<div><span>{{ __('Сума замовлення') }}</span><strong>{{ number_format($total, 0, ',', ' ') }} ₴</strong></div>
@if($savings > 0)<div class="summary-saving"><span>{{ __('Ваша вигода') }}</span><strong>{{ number_format($savings, 0, ',', ' ') }} ₴</strong></div>@endif
<div class="summary-delivery-line">
    <span>{{ __('Доставка') }}</span>
    <strong
        data-checkout-delivery-price
        aria-live="polite"
        @class(['is-free-delivery' => $deliveryIsFree])
        data-free-delivery-qualified="{{ $deliveryIsFree ? '1' : '0' }}"
        data-free-delivery-threshold="{{ FreeDelivery::threshold() }}"
        data-cart-total="{{ $total }}"
    >
        <span data-checkout-delivery-label>{{ FreeDelivery::labelForTotal($total) }}</span>
        <small data-checkout-delivery-estimate class="summary-delivery-estimate" @if($deliveryIsFree) hidden @endif>{{ FreeDelivery::promoLabel() }}</small>
    </strong>
</div>
<div class="summary-total"><span>{{ __('До сплати') }}</span><strong>{{ number_format($total, 0, ',', ' ') }} ₴</strong></div>
@auth<p class="readonly-hint">{{ __('Email і телефон беруться з вашого профілю. Якщо частина ПІБ ще не заповнена, ми збережемо її після оформлення.') }}</p>@endauth
<button class="primary-button" type="button" data-checkout-submit data-checkout-empty="{{ $items->isEmpty() ? 1 : 0 }}" aria-disabled="true">{{ __('Замовлення підтверджую') }}</button>
