@php
    use App\Support\FreeDelivery;

    $threshold = FreeDelivery::threshold();
    $deliveryIsFree = FreeDelivery::qualifies($total);
    $progress = FreeDelivery::progressPercent($total);
    $isDrawer = ($context ?? '') === 'drawer';
@endphp
<div @class(['delivery-progress', 'is-free' => $deliveryIsFree, 'delivery-progress--drawer' => $isDrawer])>
    <div class="delivery-progress__head">
        <span class="delivery-progress__title">{{ __('Доставка') }}</span>
        <strong>{{ $deliveryIsFree ? __('Безкоштовно') : __('за тарифами') }}</strong>
    </div>
    @if($threshold > 0 && ! $deliveryIsFree)
        <div
            class="delivery-progress__track"
            role="progressbar"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-valuenow="{{ $progress }}"
            aria-label="{{ FreeDelivery::hintForTotal($total) }}"
        >
            <i style="width: {{ $progress }}%"></i>
        </div>
        <div class="delivery-progress__meta">
            <span>{{ FreeDelivery::promoLabel() }}</span>
            <b>{{ __('ще :remaining ₴ → безкоштовно', ['remaining' => FreeDelivery::formattedRemaining($total)]) }}</b>
        </div>
    @elseif($deliveryIsFree)
        <p class="delivery-progress__note">{{ __('Нова Пошта по Україні') }}</p>
    @endif
</div>
