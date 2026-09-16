@php
    $filterEntries = collect($values);
    $initialEntries = $filterEntries
        ->filter(fn ($count, $value) => in_array((string) $value, array_map('strval', $selected), true))
        ->union($filterEntries->take(5));
    $deferredEntries = $filterEntries->except($initialEntries->keys());
@endphp
@if($filterEntries->isNotEmpty())
<fieldset data-filter-group>
    <legend>{{ $title }}@include('store._admin-filter-controls', ['type' => str_starts_with($name, 'spec[') ? 'spec' : 'base', 'filterKey' => $trackingKey ?? $name])</legend>
    @if(isset($searchPlaceholder))
        <input class="filter-search" placeholder="{{ $searchPlaceholder }}" data-filter-search="{{ $name }}">
    @endif
    <div data-filter-options-list>
    @foreach($initialEntries as $value => $count)
        <label data-filter-option="{{ $name }}">
            <span><input type="checkbox" name="{{ $name }}[]" value="{{ $value }}" data-filter-key="{{ $trackingKey ?? $name }}" @checked(in_array($value, $selected))> @if(isset($filterOptionUrl))<a href="{{ $filterOptionUrl($name, (string) $value) }}" data-filter-option-link>{{ $value }}</a>@else{{ $value }}@endif</span><small class="filter-option-count">({{ $count }})</small>
        </label>
    @endforeach
    </div>
    @if($deferredEntries->isNotEmpty())
        <script type="application/json" data-filter-options-payload data-filter-extra hidden>{!! json_encode([
            'name' => $name,
            'trackingKey' => $trackingKey ?? $name,
            'selected' => array_values($selected),
            'options' => $deferredEntries->map(fn ($count, $value) => [
                'value' => (string) $value,
                'count' => (int) $count,
            ])->values()->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
        <button class="filter-more" type="button" data-toggle-filter-options>
            <span data-filter-more-label>{{ __('Показати ще') }}</span>
            <i>+{{ $deferredEntries->count() }}</i>
        </button>
    @endif
</fieldset>
@endif
