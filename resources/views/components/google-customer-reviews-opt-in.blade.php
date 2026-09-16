@props(['order'])

@php
    $payload = \App\Support\GoogleCustomerReviews::optInPayload($order);
@endphp

@if($payload !== null)
    {{-- Same official Google Survey Opt-in as Merchant Center docs; dynamic order values via @json. --}}
    <script>
        window.renderOptIn = function () {
            window.gapi.load('surveyoptin', function () {
                window.gapi.surveyoptin.render(@json($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT));
            });
        };
    </script>
    <script src="https://apis.google.com/js/platform.js?onload=renderOptIn" async defer></script>
@endif
