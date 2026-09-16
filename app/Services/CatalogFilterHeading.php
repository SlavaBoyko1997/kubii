<?php

namespace App\Services;

use App\Support\Locale;

/**
 * Builds a short, readable suffix from active catalog filters (brand,
 * model, season, usage type, material, specifications, stock/sale flags
 * — everything except numeric price/weight ranges) that can be appended
 * to a category's name to produce a dynamic <h1>/<title> for filtered
 * catalog pages.
 *
 * Each group is rendered as "{label} {value}", e.g. "бренд Tramp колір
 * Чорний", so it stays clear which value belongs to which characteristic.
 *
 * Price and weight ranges are excluded because they are numeric spans
 * rather than discrete, readable keywords (e.g. "від 1000 до 5000 ₴"
 * reads poorly stitched into an <h1>/<title>).
 */
class CatalogFilterHeading
{
    /**
     * Groups with more distinct values than this are dropped entirely from
     * the heading (rather than listing many values) to avoid keyword spam.
     */
    private const MAX_VALUES_PER_GROUP = 2;

    /**
     * @param  list<array{label: string, values: mixed}>  $groups  ordered list of labeled filter groups,
     *                                                             e.g. [['label' => 'бренд', 'values' => ['Tramp']]]
     * @param  list<string>  $flags  already-formatted single labels always appended as-is, e.g. ['в наявності']
     */
    public function suffix(array $groups, array $flags = []): string
    {
        $parts = [];

        foreach ($groups as $group) {
            $label = trim((string) ($group['label'] ?? ''));
            $values = $this->normalizedValues($group['values'] ?? []);

            if ($label === '' || $values === [] || count($values) > self::MAX_VALUES_PER_GROUP) {
                continue;
            }

            $parts[] = "{$label} {$this->joinValues($values)}";
        }

        foreach ($flags as $flag) {
            $flag = trim((string) $flag);

            if ($flag !== '') {
                $parts[] = $flag;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @param  list<array{label: string, values: mixed}>  $groups
     * @param  list<string>  $flags
     */
    public function build(string $baseName, array $groups, array $flags = []): string
    {
        $suffix = $this->suffix($groups, $flags);

        return $suffix === '' ? $baseName : trim($baseName.' '.$suffix);
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    private function normalizedValues($values): array
    {
        return collect((array) $values)
            ->map(fn ($value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $values
     */
    private function joinValues(array $values): string
    {
        if (count($values) === 1) {
            return $values[0];
        }

        $connector = Locale::isRussian() ? 'и' : 'та';

        return implode(' '.$connector.' ', $values);
    }
}
