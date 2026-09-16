<?php

namespace App\Services;

use App\Exceptions\NovaPoshtaException;
use App\Support\NovaPoshtaShipment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class NovaPoshtaService
{
    private const CACHE_TTL = 86400;

    public function getCities(?string $search = null): array
    {
        $cities = Cache::remember(
            'nova-poshta:cities',
            self::CACHE_TTL,
            fn (): array => $this->request('Address', 'getCities'),
        );

        $search = Str::lower(trim((string) $search));

        return collect($cities)
            ->when($search !== '', fn (Collection $items): Collection => $items
                ->filter(fn (array $city): bool => $this->cityMatches($city, $search))
                ->sortBy(fn (array $city): array => $this->citySearchRank($city, $search)))
            ->take(50)
            ->map(fn (array $city): array => $this->normalizeCity($city))
            ->values()
            ->all();
    }

    public function getWarehouses(string $cityRef, ?array $shipmentProfile = null): array
    {
        $warehouses = Cache::remember(
            'nova-poshta:warehouses:'.$cityRef,
            self::CACHE_TTL,
            fn (): array => collect($this->request('Address', 'getWarehouses', [
                'CityRef' => $cityRef,
            ]))
                ->reject(fn (array $warehouse): bool => $this->isPostomat($warehouse))
                ->map(fn (array $warehouse): array => $this->normalizeWarehouse($warehouse))
                ->values()
                ->all(),
        );

        if ($shipmentProfile === null) {
            return $warehouses;
        }

        $shipment = app(NovaPoshtaShipment::class);

        return collect($warehouses)
            ->filter(fn (array $warehouse): bool => $shipment->warehouseSupports($warehouse, $shipmentProfile))
            ->values()
            ->all();
    }

    public function getPostomats(string $cityRef): array
    {
        return Cache::remember(
            'nova-poshta:postomats:'.$cityRef,
            self::CACHE_TTL,
            fn (): array => collect($this->request('Address', 'getWarehouses', [
                'CityRef' => $cityRef,
                'TypeOfWarehouseRef' => config('services.nova_poshta.postomat_type_ref'),
            ]))
                ->filter(fn (array $warehouse): bool => $this->isPostomat($warehouse))
                ->map(fn (array $warehouse): array => $this->normalizeWarehouse($warehouse))
                ->values()
                ->all(),
        );
    }

    public function getWarehouseByRef(string $ref): ?array
    {
        return Cache::remember(
            'nova-poshta:warehouse:'.$ref,
            self::CACHE_TTL,
            function () use ($ref): ?array {
                $warehouse = $this->request('Address', 'getWarehouses', ['Ref' => $ref])[0] ?? null;

                return $warehouse ? $this->normalizeWarehouse($warehouse) : null;
            },
        );
    }

    public function getCityByRef(string $ref): ?array
    {
        return Cache::remember(
            'nova-poshta:city:'.$ref,
            self::CACHE_TTL,
            function () use ($ref): ?array {
                $city = $this->request('Address', 'getCities', ['Ref' => $ref])[0] ?? null;

                return $city ? $this->normalizeCity($city) : null;
            },
        );
    }

    public function estimateDeliveryCost(
        string $cityRecipientRef,
        array $shipmentProfile,
        float $orderCostUah,
        string $deliveryType = 'nova_poshta_warehouse',
        ?string $warehouseRecipientRef = null,
    ): ?float
    {
        $senderCityRef = (string) config('services.nova_poshta.sender_city_ref');

        if ($senderCityRef === '' || $cityRecipientRef === '') {
            return null;
        }

        $warehouseRecipientRef = trim((string) $warehouseRecipientRef);

        $weight = max(1.0, (float) ($shipmentProfile['total_weight_kg'] ?? 0));
        $cargoType = ($shipmentProfile['requires_cargo_warehouse'] ?? false) ? 'Cargo' : 'Parcel';
        $cost = max(1, (int) round($orderCostUah));
        $serviceType = $deliveryType === 'nova_poshta_courier' ? 'WarehouseDoors' : 'WarehouseWarehouse';

        $properties = [
            'CitySender' => $senderCityRef,
            'CityRecipient' => $cityRecipientRef,
            'Weight' => (string) round($weight, 2),
            'ServiceType' => $serviceType,
            'CargoType' => $cargoType,
            'SeatsAmount' => '1',
            'Cost' => (string) $cost,
        ];

        if ($warehouseRecipientRef !== '' && $deliveryType !== 'nova_poshta_courier') {
            $properties['WarehouseRecipient'] = $warehouseRecipientRef;
        }

        if ($shipmentProfile['has_known_dimensions'] ?? false) {
            $dimensions = array_map('floatval', $shipmentProfile['dimensions_cm'] ?? []);

            if (count($dimensions) >= 3) {
                rsort($dimensions);
                $volume = ($dimensions[0] / 100) * ($dimensions[1] / 100) * ($dimensions[2] / 100);

                if ($volume > 0) {
                    $properties['VolumeGeneral'] = (string) round($volume, 4);
                }
            }
        }

        $cacheKey = sprintf(
            'nova-poshta:delivery-price:%s:%s:%s:%s:%s:%s:%s:%d:%s',
            $senderCityRef,
            $cityRecipientRef,
            $deliveryType,
            $warehouseRecipientRef !== '' ? $warehouseRecipientRef : 'city',
            $properties['Weight'],
            $cargoType,
            $serviceType,
            $cost,
            $properties['VolumeGeneral'] ?? '0',
        );

        $cached = Cache::get($cacheKey);

        if (is_numeric($cached)) {
            return (float) $cached;
        }

        $result = $this->request('InternetDocument', 'getDocumentPrice', $properties)[0] ?? null;

        if (! is_array($result)) {
            return null;
        }

        $deliveryCost = (float) ($result['Cost'] ?? $result['AssessedCost'] ?? 0);

        if ($deliveryCost <= 0) {
            return null;
        }

        $deliveryCost = round($deliveryCost, 2);
        Cache::put($cacheKey, $deliveryCost, self::CACHE_TTL);

        return $deliveryCost;
    }

    public function getStreets(string $cityRef, ?string $search = null): array
    {
        $search = trim((string) $search);

        if ($search === '' || mb_strlen($search) < 2) {
            return [];
        }

        $cacheKey = 'nova-poshta:streets:'.md5($cityRef.':'.$search);

        return Cache::remember(
            $cacheKey,
            self::CACHE_TTL,
            function () use ($cityRef, $search): array {
                return collect($this->request('Address', 'getStreet', [
                    'CityRef' => $cityRef,
                    'FindByString' => $search,
                    'Page' => '1',
                ]))
                    ->take(50)
                    ->map(fn (array $street): array => $this->normalizeStreet($street))
                    ->values()
                    ->all();
            },
        );
    }

    public function getStreetByRef(string $cityRef, string $ref): ?array
    {
        if ($ref === '') {
            return null;
        }

        return Cache::remember(
            'nova-poshta:street:'.$cityRef.':'.$ref,
            self::CACHE_TTL,
            function () use ($cityRef, $ref): ?array {
                try {
                    $data = $this->request('Address', 'getStreet', [
                        'CityRef' => $cityRef,
                        'Ref' => $ref,
                    ]);
                } catch (NovaPoshtaException) {
                    return null;
                }

                $street = $data[0] ?? null;

                return $street ? $this->normalizeStreet($street) : null;
            },
        );
    }

    public function findStreet(string $cityRef, string $ref, string $search): ?array
    {
        if ($ref === '') {
            return null;
        }

        $byRef = $this->getStreetByRef($cityRef, $ref);

        if ($byRef !== null) {
            return $byRef;
        }

        foreach ($this->streetSearchCandidates($search) as $candidate) {
            $street = collect($this->getStreets($cityRef, $candidate))
                ->first(fn (array $street): bool => $street['Ref'] === $ref);

            if ($street !== null) {
                return $street;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function streetSearchCandidates(string $search): array
    {
        $search = trim($search);
        $candidates = [];

        if ($search !== '') {
            $candidates[] = $search;
        }

        if (preg_match('/^[\p{L}\.\']+\s+(.+)$/u', $search, $matches)) {
            $candidates[] = trim($matches[1]);
        }

        $parts = preg_split('/\s+/u', $search) ?: [];

        if (count($parts) > 1) {
            $candidates[] = (string) end($parts);
        }

        return array_values(array_unique(array_filter(
            $candidates,
            fn (string $candidate): bool => mb_strlen($candidate) >= 2,
        )));
    }

    private function request(string $model, string $method, array $properties = []): array
    {
        $apiKey = (string) config('services.nova_poshta.api_key');

        if ($apiKey === '') {
            throw new NovaPoshtaException('Nova Poshta API key is not configured.');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(12)
                ->retry(2, 250, throw: false)
                ->post((string) config('services.nova_poshta.endpoint'), [
                    'apiKey' => $apiKey,
                    'modelName' => $model,
                    'calledMethod' => $method,
                    'methodProperties' => (object) $properties,
                ]);
        } catch (ConnectionException $exception) {
            throw new NovaPoshtaException('Nova Poshta API is unavailable.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new NovaPoshtaException('Nova Poshta API returned HTTP '.$response->status().'.');
        }

        $payload = $response->json();

        if (! is_array($payload) || ($payload['success'] ?? false) !== true || ! is_array($payload['data'] ?? null)) {
            $message = collect($payload['errors'] ?? [])->filter()->implode('; ');

            throw new NovaPoshtaException($message ?: 'Nova Poshta API returned an invalid response.');
        }

        return $payload['data'];
    }

    private function normalizeStreet(array $street): array
    {
        $type = trim((string) ($street['StreetsType'] ?? ''));
        $description = trim((string) ($street['Description'] ?? ''));
        $label = trim($type.' '.$description);

        return [
            'Ref' => (string) ($street['Ref'] ?? ''),
            'Description' => $description,
            'StreetsType' => $type,
            'Label' => $label !== '' ? $label : $description,
        ];
    }

    private function normalizeCity(array $city): array
    {
        return [
            'Ref' => (string) ($city['Ref'] ?? ''),
            'Description' => (string) ($city['Description'] ?? ''),
            'DescriptionRu' => (string) ($city['DescriptionRu'] ?? ''),
            'AreaDescription' => (string) ($city['AreaDescription'] ?? ''),
            'RegionsDescription' => (string) ($city['RegionsDescription'] ?? ''),
        ];
    }

    private function cityMatches(array $city, string $search): bool
    {
        return collect([
            $city['Description'] ?? null,
            $city['DescriptionRu'] ?? null,
            $city['AreaDescription'] ?? null,
            $city['RegionsDescription'] ?? null,
        ])->filter()->contains(
            fn (string $value): bool => Str::contains(Str::lower($value), $search),
        );
    }

    private function citySearchRank(array $city, string $search): array
    {
        $description = Str::lower((string) ($city['Description'] ?? ''));
        $descriptionRu = Str::lower((string) ($city['DescriptionRu'] ?? ''));

        $rank = match (true) {
            $description === $search => 0,
            $descriptionRu === $search => 1,
            Str::startsWith($description, $search) => 2,
            Str::startsWith($descriptionRu, $search) => 3,
            Str::contains($description, $search) => 4,
            Str::contains($descriptionRu, $search) => 5,
            default => 6,
        };

        return [$rank, mb_strlen($description), $description];
    }

    private function normalizeWarehouse(array $warehouse): array
    {
        return [
            'Ref' => (string) ($warehouse['Ref'] ?? ''),
            'Description' => (string) ($warehouse['Description'] ?? ''),
            'ShortAddress' => (string) ($warehouse['ShortAddress'] ?? ''),
            'Number' => (string) ($warehouse['Number'] ?? ''),
            'CityRef' => (string) ($warehouse['CityRef'] ?? ''),
            'TypeOfWarehouseRef' => (string) ($warehouse['TypeOfWarehouseRef'] ?? ''),
            'CategoryOfWarehouse' => (string) ($warehouse['CategoryOfWarehouse'] ?? ''),
            'TotalMaxWeightAllowed' => (float) ($warehouse['TotalMaxWeightAllowed'] ?? 0),
            'PlaceMaxWeightAllowed' => (float) ($warehouse['PlaceMaxWeightAllowed'] ?? 0),
            'ReceivingLimitationsOnDimensions' => array_map(
                'floatval',
                (array) ($warehouse['ReceivingLimitationsOnDimensions'] ?? []),
            ),
            'WarehouseStatus' => (string) ($warehouse['WarehouseStatus'] ?? ''),
            'is_postomat' => $this->isPostomat($warehouse),
            'is_cargo' => (float) ($warehouse['PlaceMaxWeightAllowed'] ?? 0) > 30,
        ];
    }

    private function isPostomat(array $warehouse): bool
    {
        return ($warehouse['TypeOfWarehouseRef'] ?? null) === config('services.nova_poshta.postomat_type_ref')
            || Str::lower((string) ($warehouse['CategoryOfWarehouse'] ?? '')) === 'postomat'
            || Str::contains(Str::lower((string) ($warehouse['Description'] ?? '')), ['поштомат', 'почтомат']);
    }
}
