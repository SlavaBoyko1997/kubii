<?php

namespace App\Support;

use App\Models\Order;

class OrderPresentation
{
    public static function orderTypeLabel(string $type): string
    {
        return match ($type) {
            'quick' => 'Швидке замовлення',
            default => 'Стандартне',
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'new' => 'Нове',
            'confirmed' => 'Підтверджене',
            'shipped' => 'Відправлене',
            'completed' => 'Виконане',
            'cancelled' => 'Скасоване',
            default => $status,
        };
    }

    public static function paymentMethodLabel(?string $method): string
    {
        return match ($method) {
            'liqpay_hold' => 'Карткою на сайті — LiqPay',
            'mono_checkout' => 'Онлайн-оплата карткою — Monobank',
            'iban' => 'Оплата на IBAN',
            'cash_on_delivery' => 'Післяплата у Новій Пошті',
            'card_on_delivery' => 'Карткою при отриманні',
            default => $method ?? '—',
        };
    }

    public static function paymentStatusLabel(?string $status): string
    {
        return match ($status) {
            'pending' => 'Очікується оплата',
            'holded' => 'Кошти заблоковано',
            'paid' => 'Сплачено',
            'failed' => 'Помилка оплати',
            'cancelled', 'reversed' => 'Холд скасовано',
            default => $status ?? '—',
        };
    }

    public static function deliveryTypeLabel(?string $type): ?string
    {
        return match ($type) {
            'nova_poshta_warehouse' => 'Нова Пошта — відділення',
            'nova_poshta_postomat' => 'Нова Пошта — поштомат',
            'nova_poshta_courier' => 'Нова Пошта — кур\'єр',
            default => $type,
        };
    }

    public static function isNovaPoshtaDelivery(Order $order): bool
    {
        return filled($order->delivery_type)
            && str_starts_with($order->delivery_type, 'nova_poshta_');
    }

    /**
     * @return array<int, array{label: string, value: string, wide?: bool}>
     */
    public static function deliveryFields(Order $order): array
    {
        if (self::isNovaPoshtaDelivery($order)) {
            $isPostomat = $order->delivery_type === 'nova_poshta_postomat';
            $isCourier = $order->delivery_type === 'nova_poshta_courier';
            $deliveryCity = $order->nova_poshta_city_name ?: $order->city;
            $deliveryPointName = $order->nova_poshta_warehouse_name ?: $order->delivery_address;
            $deliveryAddress = $order->nova_poshta_warehouse_address ?: $order->delivery_address;

            $fields = [
                ['label' => 'Тип отримання', 'value' => $isCourier ? 'Кур\'єрська доставка' : ($isPostomat ? 'Поштомат' : 'Відділення')],
            ];

            if (filled($deliveryCity)) {
                $fields[] = ['label' => 'Місто', 'value' => $deliveryCity];
            }

            if ($isCourier) {
                if (filled($order->nova_poshta_street_name) || filled($order->delivery_address)) {
                    $fields[] = [
                        'label' => 'Адреса доставки',
                        'value' => $order->delivery_address ?: (string) $order->nova_poshta_street_name,
                        'wide' => true,
                    ];
                }

                if (filled($order->nova_poshta_building)) {
                    $fields[] = ['label' => 'Будинок', 'value' => $order->nova_poshta_building];
                }

                if (filled($order->nova_poshta_flat)) {
                    $fields[] = ['label' => 'Квартира', 'value' => $order->nova_poshta_flat];
                }
            } else {
                if (filled($order->nova_poshta_warehouse_number)) {
                    $fields[] = [
                        'label' => $isPostomat ? 'Номер поштомату' : 'Номер відділення',
                        'value' => '№'.$order->nova_poshta_warehouse_number,
                    ];
                }

                if (filled($deliveryPointName)) {
                    $fields[] = [
                        'label' => $isPostomat ? 'Обраний поштомат' : 'Обране відділення',
                        'value' => $deliveryPointName,
                        'wide' => true,
                    ];
                }

                if (filled($deliveryAddress) && $deliveryAddress !== $deliveryPointName) {
                    $fields[] = [
                        'label' => 'Адреса отримання',
                        'value' => $deliveryAddress,
                        'wide' => true,
                    ];
                }
            }

            return $fields;
        }

        $fields = [];

        if (filled($order->city)) {
            $fields[] = ['label' => 'Місто', 'value' => $order->city];
        }

        if (filled($order->delivery_address)) {
            $fields[] = ['label' => 'Адреса отримання', 'value' => $order->delivery_address, 'wide' => true];
        }

        if (filled($order->delivery_type) && ! self::isNovaPoshtaDelivery($order)) {
            $fields[] = ['label' => 'Тип доставки', 'value' => self::deliveryTypeLabel($order->delivery_type) ?? $order->delivery_type];
        }

        return $fields;
    }

    public static function formatMoney(float|string|null $amount): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }

        return number_format((float) $amount, 0, ',', ' ').' ₴';
    }
}
