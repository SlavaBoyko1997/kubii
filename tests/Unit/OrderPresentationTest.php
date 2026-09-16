<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Support\OrderPresentation;
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
    }
}
