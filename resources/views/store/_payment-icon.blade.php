@props(['method'])

@if($method === 'liqpay_hold')
    <span {{ $attributes->class(['payment-method-icon', 'payment-method-icon-liqpay']) }} aria-hidden="true">
        <img src="/logo_liqpay%20for%20white.svg" alt="" width="500" height="104">
    </span>
@elseif($method === 'mono_checkout')
    <span {{ $attributes->class(['payment-method-icon', 'payment-method-icon-mono']) }} aria-hidden="true">
        <span class="payment-method-mono-badge payment-method-mono-badge-gpay">G Pay</span>
        <span class="payment-method-mono-badge payment-method-mono-badge-apple"> Pay</span>
    </span>
@elseif($method === 'iban')
    <span {{ $attributes->class(['payment-method-icon', 'payment-method-icon-iban']) }} aria-hidden="true">
        <svg viewBox="0 0 64 44" role="img">
            <rect x="2" y="4" width="60" height="36" rx="9" fill="#eaf1ff"/>
            <path d="m32 10 20 8H12l20-8Zm-15 12h5v10h-5V22Zm10 0h5v10h-5V22Zm10 0h5v10h-5V22Zm10 0h5v10h-5V22ZM11 35h42" fill="none" stroke="#2559a7" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </span>
@else
    <span {{ $attributes->class(['payment-method-icon', 'payment-method-icon-nova-poshta']) }} aria-hidden="true">
        <img src="{{ asset('images/nova-poshta-logo.png') }}" alt="" width="1654" height="651">
    </span>
@endif
