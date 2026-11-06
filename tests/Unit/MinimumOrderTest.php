<?php

namespace Tests\Unit;

use App\Support\MinimumOrder;
use Tests\TestCase;

class MinimumOrderTest extends TestCase
{
    public function test_qualifies_when_order_total_meets_threshold(): void
    {
        config(['services.min_order.threshold' => 1500]);

        $this->assertTrue(MinimumOrder::qualifies(1500));
        $this->assertTrue(MinimumOrder::qualifies(2000));
        $this->assertFalse(MinimumOrder::qualifies(1499));
    }

    public function test_remaining_and_hints(): void
    {
        config(['services.min_order.threshold' => 1500]);

        $this->assertSame(500.0, MinimumOrder::remaining(1000));
        $this->assertSame(0.0, MinimumOrder::remaining(1500));
        $this->assertStringContainsString('1 500', MinimumOrder::formattedThreshold());
        $this->assertStringContainsString('500', MinimumOrder::hintForTotal(1000));
        $this->assertStringContainsString('1 500', MinimumOrder::productHint());
    }

    public function test_zero_threshold_allows_any_order(): void
    {
        config(['services.min_order.threshold' => 0]);

        $this->assertTrue(MinimumOrder::qualifies(1));
        $this->assertTrue(MinimumOrder::qualifies(0));
    }
}
