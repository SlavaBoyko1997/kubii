@php($rating = round((float) ($rating ?? 0), 1))
<span class="rating-stars" aria-label="{{ __('Рейтинг :rating з 5', ['rating' => $rating]) }}">
    @for($star = 1; $star <= 5; $star++)<i class="{{ $star <= round($rating) ? 'filled' : '' }}">★</i>@endfor
</span>
