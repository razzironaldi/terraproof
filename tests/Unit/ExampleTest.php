<?php

namespace Tests\Unit;

use App\Services\GeodesicAuditService;
use App\Services\YieldEnvelopeService;
use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Verify Haversine great-circle distance and biological yield factors in isolation.
     */
    public function test_haversine_distance_and_yield_ceiling_unit_math(): void
    {
        $geo = new GeodesicAuditService();
        $dist = $geo->haversineDistanceM(-5.012000, 104.062000, -5.012000, 104.062900);
        $this->assertGreaterThan(95.0, $dist);
        $this->assertLessThan(105.0, $dist);

        $yield = new YieldEnvelopeService();
        $res = $yield->calculateBiologicalCeiling('COCOA', 1.20, 1000, 10, 650);
        $this->assertSame(1890.00, $res['annual_yield_ceiling_kg']);
    }
}
