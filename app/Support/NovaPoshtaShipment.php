<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Collection;

class NovaPoshtaShipment
{
    private const POSTOMAT_MAX_WEIGHT_KG = 20;

    private const POSTOMAT_DIMENSIONS_CM = [60, 40, 30];

    private const REGULAR_WAREHOUSE_MAX_WEIGHT_KG = 30;

    private const REGULAR_WAREHOUSE_MAX_SIDE_CM = 120;

    public function profile(Collection $items): array
    {
        $totalWeightKg = 0.0;
        $maxPlaceWeightKg = 0.0;
        $largestDimensions = [0.0, 0.0, 0.0];
        $hasKnownDimensions = false;

        foreach ($items as $item) {
            /** @var Product $product */
            $product = $item['product'];
            $quantity = max(1, (int) $item['quantity']);
            $weightKg = $this->packageWeightKg($product);
            $dimensions = $this->packageDimensionsCm($product);

            if ($weightKg !== null) {
                $totalWeightKg += $weightKg * $quantity;
                $maxPlaceWeightKg = max($maxPlaceWeightKg, $weightKg);
            }

            if ($dimensions !== null) {
                $hasKnownDimensions = true;

                foreach ($dimensions as $index => $dimension) {
                    $largestDimensions[$index] = max($largestDimensions[$index], $dimension);
                }
            }
        }

        rsort($largestDimensions, SORT_NUMERIC);
        $fitsPostomatDimensions = ! $hasKnownDimensions
            || $this->dimensionsFit($largestDimensions, self::POSTOMAT_DIMENSIONS_CM);
        $postomatAvailable = $totalWeightKg <= self::POSTOMAT_MAX_WEIGHT_KG
            && $maxPlaceWeightKg <= self::POSTOMAT_MAX_WEIGHT_KG
            && $fitsPostomatDimensions;
        $requiresCargoWarehouse = $maxPlaceWeightKg > self::REGULAR_WAREHOUSE_MAX_WEIGHT_KG
            || $totalWeightKg > self::REGULAR_WAREHOUSE_MAX_WEIGHT_KG
            || ($hasKnownDimensions && max($largestDimensions) > self::REGULAR_WAREHOUSE_MAX_SIDE_CM);

        return [
            'postomat_available' => $postomatAvailable,
            'requires_cargo_warehouse' => $requiresCargoWarehouse,
            'total_weight_kg' => round($totalWeightKg, 3),
            'max_place_weight_kg' => round($maxPlaceWeightKg, 3),
            'dimensions_cm' => $largestDimensions,
            'has_known_dimensions' => $hasKnownDimensions,
        ];
    }

    public function warehouseSupports(array $warehouse, array $profile): bool
    {
        $placeLimit = (float) ($warehouse['PlaceMaxWeightAllowed'] ?? 0);
        $totalLimit = (float) ($warehouse['TotalMaxWeightAllowed'] ?? 0);

        if ($placeLimit > 0 && $profile['max_place_weight_kg'] > $placeLimit) {
            return false;
        }

        if ($totalLimit > 0 && $profile['total_weight_kg'] > $totalLimit) {
            return false;
        }

        if (! ($profile['requires_cargo_warehouse'] ?? false)) {
            return true;
        }

        if (! ($profile['has_known_dimensions'] ?? false)) {
            return true;
        }

        $limits = array_values((array) ($warehouse['ReceivingLimitationsOnDimensions'] ?? []));

        return $limits === [] || $this->dimensionsFit($profile['dimensions_cm'], $limits);
    }

    private function packageWeightKg(Product $product): ?float
    {
        $value = $this->specificationNumber($product, ['Вага в упаковці, кг', 'Вес в упаковке, кг']);

        if ($value !== null) {
            return $value;
        }

        return $product->weight_grams ? ((float) $product->weight_grams / 1000) : null;
    }

    private function packageDimensionsCm(Product $product): ?array
    {
        $length = $this->specificationNumber($product, ['Довжина в упаковці, м', 'Длина в упаковке, м']);
        $width = $this->specificationNumber($product, ['Ширина в упаковці, м', 'Ширина в упаковке, м']);
        $height = $this->specificationNumber($product, ['Висота в упаковці, м', 'Высота в упаковке, м']);

        if ($length === null || $width === null || $height === null) {
            return null;
        }

        $dimensions = [$length * 100, $width * 100, $height * 100];
        rsort($dimensions, SORT_NUMERIC);

        return $dimensions;
    }

    private function specificationNumber(Product $product, array $keys): ?float
    {
        $specifications = [
            ...(array) $product->specifications,
            ...(array) $product->specifications_ru,
        ];

        foreach ($keys as $key) {
            if (isset($specifications[$key])
                && preg_match('/[\d]+(?:[,.][\d]+)?/', (string) $specifications[$key], $matches)) {
                return (float) str_replace(',', '.', $matches[0]);
            }
        }

        return null;
    }

    private function dimensionsFit(array $dimensions, array $limits): bool
    {
        $dimensions = array_map('floatval', array_values($dimensions));
        $limits = array_map('floatval', array_values($limits));
        rsort($dimensions, SORT_NUMERIC);
        rsort($limits, SORT_NUMERIC);

        if (count($dimensions) < 3 || count($limits) < 3) {
            return true;
        }

        return $dimensions[0] <= $limits[0]
            && $dimensions[1] <= $limits[1]
            && $dimensions[2] <= $limits[2];
    }
}
