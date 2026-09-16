@php
    $allSwatches = $product->colorVariantSwatches(999);
    $isResponsive = (bool) ($responsive ?? $compact ?? false);
    $desktopLimit = (int) ($desktopLimit ?? 5);
    $mobileLimit = (int) ($mobileLimit ?? 3);
    $swatches = $isResponsive
        ? $allSwatches->take($desktopLimit)
        : $allSwatches->take($limit ?? 8);
    $hiddenCount = $isResponsive
        ? 0
        : max(0, $allSwatches->count() - $swatches->count());
    $desktopHiddenCount = $isResponsive ? max(0, $allSwatches->count() - $desktopLimit) : 0;
    $mobileHiddenCount = $isResponsive ? max(0, $allSwatches->count() - $mobileLimit) : 0;
    $optionLabel = $optionLabel ?? $product->cardVariantOptionLabel();
    $unavailableTitle = __('Немає в наявності');
@endphp

@if($swatches->isNotEmpty())
    <div @class(['product-card-variant-block' => $compact ?? false])>
        @if(($compact ?? false) && filled($optionLabel))
            <span class="product-card-variant-label">{{ $optionLabel }}</span>
        @endif
        <div @class([
            'product-color-swatches',
            'is-compact' => $compact ?? false,
            'is-responsive' => $isResponsive,
        ]) aria-label="{{ __('Доступні кольори') }}">
            @foreach($swatches as $index => $swatch)
                <a
                    @class([
                        'product-color-swatch',
                        'is-desktop-only-swatch' => $isResponsive && $index >= $mobileLimit,
                        'is-current' => $swatch['is_current'],
                        'is-unavailable' => ! $swatch['is_available'],
                    ])
                    href="{{ $swatch['url'] }}"
                    title="{{ $swatch['is_available'] ? $swatch['label'] : $swatch['label'].' — '.$unavailableTitle }}"
                    @if($swatch['is_current']) aria-current="page" @endif
                >
                    <img src="{{ $swatch['image'] }}" alt="{{ $swatch['label'] }}" loading="lazy" decoding="async">
                </a>
            @endforeach
            @if($isResponsive)
                @if($desktopHiddenCount > 0)
                    <span class="product-color-swatch-more is-desktop-only">+{{ $desktopHiddenCount }}</span>
                @endif
                @if($mobileHiddenCount > 0)
                    <span class="product-color-swatch-more is-mobile-only">+{{ $mobileHiddenCount }}</span>
                @endif
            @elseif($hiddenCount > 0)
                <span class="product-color-swatch-more">+{{ $hiddenCount }}</span>
            @endif
        </div>
    </div>
@endif
