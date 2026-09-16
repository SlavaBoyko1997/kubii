@php
    $allOptions = $product->chipVariantOptions(999);
    $isResponsive = (bool) ($responsive ?? $compact ?? false);
    $desktopLimit = (int) ($desktopLimit ?? 5);
    $mobileLimit = (int) ($mobileLimit ?? 3);
    $options = $isResponsive
        ? $allOptions->take($desktopLimit)
        : $allOptions->take($limit ?? 8);
    $hiddenCount = $isResponsive
        ? 0
        : max(0, $allOptions->count() - $options->count());
    $desktopHiddenCount = $isResponsive ? max(0, $allOptions->count() - $desktopLimit) : 0;
    $mobileHiddenCount = $isResponsive ? max(0, $allOptions->count() - $mobileLimit) : 0;
    $optionLabel = $optionLabel ?? $product->cardVariantOptionLabel();
    $unavailableTitle = __('Немає в наявності');
@endphp

@if($options->isNotEmpty())
    <div @class(['product-card-variant-block' => $compact ?? false])>
        @if(($compact ?? false) && filled($optionLabel))
            <span class="product-card-variant-label">{{ $optionLabel }}</span>
        @endif
        <div @class([
            'product-size-options',
            'is-compact' => $compact ?? false,
            'is-responsive' => $isResponsive,
        ]) aria-label="{{ __('Доступні варіанти') }}">
            @foreach($options as $index => $option)
                <a
                    @class([
                        'variant-chip',
                        'is-card',
                        'is-desktop-only-option' => $isResponsive && $index >= $mobileLimit,
                        'is-current' => $option['is_current'],
                        'is-unavailable' => ! $option['is_available'],
                    ])
                    href="{{ $option['url'] }}"
                    title="{{ $option['is_available'] ? $option['label'] : $option['label'].' — '.$unavailableTitle }}"
                    @if($option['is_current']) aria-current="page" @endif
                >
                    <span>{{ $option['label'] }}</span>
                </a>
            @endforeach
            @if($isResponsive)
                @if($desktopHiddenCount > 0)
                    <span class="product-size-option-more is-desktop-only">+{{ $desktopHiddenCount }}</span>
                @endif
                @if($mobileHiddenCount > 0)
                    <span class="product-size-option-more is-mobile-only">+{{ $mobileHiddenCount }}</span>
                @endif
            @elseif($hiddenCount > 0)
                <span class="product-size-option-more">+{{ $hiddenCount }}</span>
            @endif
        </div>
    </div>
@endif
