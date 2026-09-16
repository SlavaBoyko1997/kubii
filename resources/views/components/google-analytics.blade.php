@if(\App\Support\GoogleAnalytics::enabled())
    @php($measurementId = \App\Support\GoogleAnalytics::measurementId())
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $measurementId }}"></script>
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', @json($measurementId), {
            anonymize_ip: true,
            allow_google_signals: false,
        });
        window.trackAnalyticsEvent = function (name, params) {
            if (typeof gtag !== 'function') return;
            gtag('event', name, params ?? {});
        };
    </script>
@endif
