<?php

namespace App\Support;

use App\Models\Order;

class TelegramOrderMessage
{
    public static function fromOrder(Order $order): string
    {
        $order->loadMissing('items');

        $lines = [
            '<b>🛒 Нове замовлення №'.self::e((string) $order->number).'</b>',
            'Тип: '.self::e(OrderPresentation::orderTypeLabel((string) ($order->order_type ?: 'standard'))),
            'Статус: '.self::e(OrderPresentation::statusLabel((string) ($order->status ?: 'new'))),
            '',
            '<b>👤 Клієнт</b>',
            'Імʼя: '.self::e($order->customer_name ?: '—'),
            'Телефон: '.self::e($order->phone ?: '—'),
            'Email: '.self::e($order->email ?: '—'),
        ];

        $deliveryType = OrderPresentation::deliveryTypeLabel($order->delivery_type);
        $deliveryFields = OrderPresentation::deliveryFields($order);

        if ($deliveryType || $deliveryFields !== []) {
            $lines[] = '';
            $lines[] = '<b>📦 Доставка</b>';

            if ($deliveryType) {
                $lines[] = 'Спосіб: '.self::e($deliveryType);
            }

            foreach ($deliveryFields as $field) {
                $lines[] = self::e($field['label']).': '.self::e($field['value']);
            }
        }

        $lines[] = '';
        $lines[] = '<b>💳 Оплата</b>';
        $lines[] = 'Спосіб: '.self::e(OrderPresentation::paymentMethodLabel($order->payment_method));
        $lines[] = 'Статус: '.self::e(OrderPresentation::paymentStatusLabel($order->payment_status));

        if ($order->items->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '<b>🛍 Товари</b>';

            foreach ($order->items as $index => $item) {
                $lines[] = ($index + 1).'. '.self::e($item->product_name)
                    .' — '.(int) $item->quantity
                    .' × '.OrderPresentation::formatMoney($item->price)
                    .' = '.OrderPresentation::formatMoney($item->subtotal);
            }
        }

        $lines[] = '';
        $lines[] = '<b>💰 Разом: '.OrderPresentation::formatMoney($order->total).'</b>';

        if (filled($order->comment)) {
            $lines[] = '';
            $lines[] = '<b>💬 Коментар</b>';
            $lines[] = self::e($order->comment);
        }

        $adminUrl = self::adminOrderUrl($order);

        if ($adminUrl !== null) {
            $lines[] = '';
            $lines[] = '<a href="'.self::e($adminUrl).'">Відкрити в адмінці</a>';
        }

        return implode("\n", $lines);
    }

    private static function adminOrderUrl(Order $order): ?string
    {
        try {
            return url('/admin/orders/'.$order->id.'/edit');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function e(?string $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
