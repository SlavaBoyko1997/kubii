@if(($showAdminFilterControls ?? false) && ($adminDisableFilterUrl ?? null) && ($currentCategory ?? null))
    <span class="admin-filter-controls">
        <button
            type="button"
            data-admin-disable-filter
            data-url="{{ $adminDisableFilterUrl }}"
            data-category-id="{{ $currentCategory->id }}"
            data-filter-type="{{ $type }}"
            data-filter-key="{{ $filterKey }}"
            title="{{ __('Вимкнути фільтр у цій категорії') }}"
        >×</button>
        <button
            type="button"
            data-admin-disable-filter
            data-url="{{ $adminDisableFilterUrl }}"
            data-category-id="{{ $currentCategory->id }}"
            data-filter-type="{{ $type }}"
            data-filter-key="{{ $filterKey }}"
            data-include-children="1"
            title="{{ __('Вимкнути фільтр у цій категорії та дочірніх') }}"
        >×↓</button>
    </span>
@endif
