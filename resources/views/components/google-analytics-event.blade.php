@props(['name', 'payload' => []])

@if(\App\Support\GoogleAnalytics::enabled())
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        window.trackAnalyticsEvent?.(@json($name), @json($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT));
    </script>
@endif
