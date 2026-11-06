@php
    use App\Support\MinimumOrder;

    $meetsMinimumOrder = MinimumOrder::qualifies($total);
@endphp
@if (! $meetsMinimumOrder)
    <p class="min-order-hint">{{ MinimumOrder::hintForTotal($total) }}</p>
@endif
@if ($meetsMinimumOrder)
    <a class="primary-button" href="{{ localized_route('checkout.create') }}">{{ __('Оформити замовлення') }}</a>
@else
    <span class="primary-button is-disabled" aria-disabled="true">{{ __('Оформити замовлення') }}</span>
@endif
