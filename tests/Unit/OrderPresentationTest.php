<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Support\OrderPresentation;
use App\Support\WarehousePickup;
use Tests\TestCase;

class OrderPresentationTest extends TestCase
{
    public function test_delivery_fields_for_nova_poshta_warehouse(): void
    {
        $order = new Order([
            'delivery_type' => 'nova_poshta_warehouse',
            'nova_poshta_city_name' => 'Київ',
            'nova_poshta_warehouse_number' => '15',
            'nova_poshta_warehouse_name' => 'Відділення №15',
            'nova_poshta_warehouse_address' => 'вул. Хрещатик, 1',
        ]);

        $fields = OrderPresentation::deliveryFields($order);

        $this->assertSame('Місто', $fields[1]['label']);
        $this->assertSame('Київ', $fields[1]['value']);
        $this->assertSame('№15', collect($fields)->firstWhere('label', 'Номер відділення')['value']);
    }

    public function test_payment_labels_are_human_readable(): void
    {
        $this->assertSame('Кошти заблоковано', OrderPresentation::paymentStatusLabel('holded'));
        $this->assertSame('Онлайн-оплата карткою — Monobank', OrderPresentation::paymentMethodLabel('mono_checkout'));
        $this->assertSame('Оплата в магазині', OrderPresentation::paymentMethodLabel('pay_in_store'));
        $this->assertSame('Самовивіз зі складу', OrderPresentation::deliveryTypeLabel(WarehousePickup::TYPE));
    }

    public function test_delivery_fields_for_warehouse_pickup(): void
    {
        $order = new Order([
            'delivery_type' => WarehousePickup::TYPE,
            'city' => WarehousePickup::CITY,
            'delivery_address' => WarehousePickup::fullAddress(),
        ]);

        $fields = OrderPresentation::deliveryFields($order);

        $this->assertSame('Бориспіль', collect($fields)->firstWhere('label', 'Місто')['value']);
        $this->assertSame(
            'вул. Київський шлях, 79в, Бориспіль',
            collect($fields)->firstWhere('label', 'Адреса складу')['value'],
        );
    }
}
