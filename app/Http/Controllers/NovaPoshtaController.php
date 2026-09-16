<?php

namespace App\Http\Controllers;

use App\Exceptions\NovaPoshtaException;
use App\Services\NovaPoshtaService;
use App\Support\Cart;
use App\Support\FreeDelivery;
use App\Support\NovaPoshtaShipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NovaPoshtaController extends Controller
{
    public function cities(Request $request, NovaPoshtaService $novaPoshta): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->respond(fn (): array => $novaPoshta->getCities($validated['search'] ?? null));
    }

    public function warehouses(
        Request $request,
        NovaPoshtaService $novaPoshta,
        Cart $cart,
        NovaPoshtaShipment $shipment,
    ): JsonResponse
    {
        $cityRef = $request->validate([
            'city_ref' => ['required', 'string', 'max:50'],
        ])['city_ref'];

        $profile = $shipment->profile($cart->items());

        return $this->respond(fn (): array => $novaPoshta->getWarehouses($cityRef, $profile));
    }

    public function postomats(
        Request $request,
        NovaPoshtaService $novaPoshta,
        Cart $cart,
        NovaPoshtaShipment $shipment,
    ): JsonResponse
    {
        $cityRef = $request->validate([
            'city_ref' => ['required', 'string', 'max:50'],
        ])['city_ref'];

        $profile = $shipment->profile($cart->items());

        return $this->respond(fn (): array => $profile['postomat_available']
            ? $novaPoshta->getPostomats($cityRef)
            : []);
    }

    public function streets(Request $request, NovaPoshtaService $novaPoshta): JsonResponse
    {
        $validated = $request->validate([
            'city_ref' => ['required', 'string', 'max:50'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->respond(fn (): array => $novaPoshta->getStreets(
            $validated['city_ref'],
            $validated['search'] ?? null,
        ));
    }

    public function deliveryPrice(
        Request $request,
        NovaPoshtaService $novaPoshta,
        Cart $cart,
        NovaPoshtaShipment $shipment,
    ): JsonResponse
    {
        $validated = $request->validate([
            'city_ref' => ['required', 'string', 'max:50'],
            'delivery_type' => ['nullable', Rule::in(['nova_poshta_warehouse', 'nova_poshta_postomat', 'nova_poshta_courier'])],
            'warehouse_ref' => ['nullable', 'string', 'max:50'],
        ]);

        $deliveryType = $validated['delivery_type'] ?? 'nova_poshta_warehouse';
        $warehouseRef = trim((string) ($validated['warehouse_ref'] ?? ''));
        $profile = $shipment->profile($cart->items());

        if ($deliveryType === 'nova_poshta_postomat' && ! $profile['postomat_available']) {
            return response()->json([
                'data' => [
                    'available' => false,
                    'message' => __('За тарифом перевізника'),
                ],
            ]);
        }

        if ($warehouseRef !== '' && $deliveryType !== 'nova_poshta_courier') {
            try {
                $warehouse = $novaPoshta->getWarehouseByRef($warehouseRef);
            } catch (NovaPoshtaException $exception) {
                report($exception);

                return response()->json([
                    'message' => __('Не вдалося перевірити дані доставки Нової Пошти. Спробуйте ще раз пізніше.'),
                ], 503);
            }

            if (! $warehouse || $warehouse['CityRef'] !== $validated['city_ref']) {
                $warehouseRef = '';
            } elseif ($deliveryType === 'nova_poshta_postomat' && ! $warehouse['is_postomat']) {
                $warehouseRef = '';
            } elseif ($deliveryType === 'nova_poshta_warehouse' && $warehouse['is_postomat']) {
                $warehouseRef = '';
            }
        }

        return $this->respond(function () use ($novaPoshta, $validated, $profile, $cart, $deliveryType, $warehouseRef): array {
            $orderTotal = $cart->total();

            if (FreeDelivery::qualifies($orderTotal)) {
                return [
                    'available' => true,
                    'free' => true,
                    'cost' => 0,
                    'formatted' => __('Безкоштовно'),
                    'note' => FreeDelivery::promoLabel(),
                ];
            }

            $cost = $novaPoshta->estimateDeliveryCost(
                $validated['city_ref'],
                $profile,
                $orderTotal,
                $deliveryType,
                $warehouseRef !== '' ? $warehouseRef : null,
            );

            if ($cost === null) {
                return [
                    'available' => false,
                    'message' => __('За тарифом перевізника'),
                    'promo' => FreeDelivery::promoLabel(),
                ];
            }

            return [
                'available' => true,
                'cost' => $cost,
                'formatted' => __('~ :price ₴', ['price' => number_format($cost, 0, ',', ' ')]),
                'note' => __('орієнтовно'),
                'promo' => FreeDelivery::promoLabel(),
            ];
        });
    }

    private function respond(callable $callback): JsonResponse
    {
        try {
            return response()->json(['data' => $callback()]);
        } catch (NovaPoshtaException $exception) {
            report($exception);

            return response()->json([
                'message' => __('Сервіс Нової Пошти тимчасово недоступний. Спробуйте ще раз пізніше.'),
            ], 503);
        }
    }
}
