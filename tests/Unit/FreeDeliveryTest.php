<?php

namespace Tests\Unit;

use App\Support\FreeDelivery;
use Tests\TestCase;

class FreeDeliveryTest extends TestCase
{
    public function test_qualifies_when_order_total_meets_threshold(): void
    {
        config(['services.free_delivery.threshold' => 3000]);

        $this->assertTrue(FreeDelivery::qualifies(3000));
        $this->assertTrue(FreeDelivery::qualifies(4500));
        $this->assertFalse(FreeDelivery::qualifies(2999));
    }

    public function test_remaining_and_labels(): void
    {
        config(['services.free_delivery.threshold' => 3000]);

        $this->assertSame(500.0, FreeDelivery::remaining(2500));
        $this->assertSame(0.0, FreeDelivery::remaining(3000));
        $this->assertSame('Безкоштовно', FreeDelivery::labelForTotal(3000));
        $this->assertSame('За тарифом перевізника', FreeDelivery::labelForTotal(1000));
        $this->assertStringContainsString('3 000', FreeDelivery::promoLabel());
        $this->assertStringContainsString('доставк', FreeDelivery::promoLabel());
        $this->assertStringContainsString('500', FreeDelivery::hintForTotal(2500));
        $this->assertStringContainsString('доставк', FreeDelivery::hintForTotal(2500));
        $this->assertSame(83, FreeDelivery::progressPercent(2500));
        $this->assertSame(100, FreeDelivery::progressPercent(3000));
    }

    public function test_zero_threshold_disables_free_delivery(): void
    {
        config(['services.free_delivery.threshold' => 0]);

        $this->assertFalse(FreeDelivery::qualifies(10000));
        $this->assertSame('За тарифом перевізника', FreeDelivery::labelForTotal(10000));
    }
}
