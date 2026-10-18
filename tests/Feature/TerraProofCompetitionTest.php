<?php

namespace Tests\Feature;

use App\Models\ExportContainer;
use App\Models\FarmPlot;
use App\Models\HarvestLedger;
use App\Models\ProtectedZone;
use App\Services\EudrExportCompiler;
use App\Services\GeodesicAuditService;
use App\Services\YieldEnvelopeService;
use Database\Seeders\LampungAgroClusterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TerraProofCompetitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LampungAgroClusterSeeder::class);
    }

    public function test_canopy_gps_multipath_spike_sanitizer_removes_reflection_spikes_and_closes_ring(): void
    {
        $service = app(GeodesicAuditService::class);

        $rawTrack = [
            ['lat' => -5.012410, 'lng' => 104.061690, 'timestamp' => 1760500000, 'accuracy_m' => 3.5],
            ['lat' => -5.012410, 'lng' => 104.062310, 'timestamp' => 1760500028, 'accuracy_m' => 3.5],
            // Injected canopy multipath spike: ~55m jump in 1 second
            ['lat' => -5.012850, 'lng' => 104.062750, 'timestamp' => 1760500029, 'accuracy_m' => 32.0],
            ['lat' => -5.011590, 'lng' => 104.062310, 'timestamp' => 1760500056, 'accuracy_m' => 3.5],
            ['lat' => -5.011590, 'lng' => 104.061690, 'timestamp' => 1760500084, 'accuracy_m' => 3.5],
        ];

        $result = $service->sanitizeGpsTrack($rawTrack);

        $this->assertSame(5, $result['raw_vertex_count']);
        $this->assertSame(4, $result['clean_vertex_count']);
        $this->assertSame(1, $result['spikes_removed']);
        $this->assertGreaterThan($result['area_hectares'], $result['area_raw_ha']);

        $ring = $result['clean_polygon_ring'];
        $this->assertSame($ring[0], $ring[count($ring) - 1], 'Polygon ring must be strictly closed per RFC 7946');
    }

    public function test_wgs84_spherical_excess_geodesic_area_matches_theoretical_hectares(): void
    {
        $service = app(GeodesicAuditService::class);

        // ~100m x 100m square near -5.012 deg latitude (~1.00 ha)
        $dLat = 100.0 / 111319.49;
        $dLng = 100.0 / (111319.49 * cos(deg2rad(-5.012)));

        $ring = [
            [104.062000, -5.012000],
            [round(104.062000 + $dLng, 6), -5.012000],
            [round(104.062000 + $dLng, 6), round(-5.012000 + $dLat, 6)],
            [104.062000, round(-5.012000 + $dLat, 6)],
            [104.062000, -5.012000],
        ];

        $areaHa = $service->computeGeodesicAreaHa($ring);
        $this->assertEqualsWithDelta(1.0000, $areaHa, 0.015, '100m x 100m equatorial square must equal ~1.00 ha');
    }

    public function test_sutherland_hodgman_clipping_detects_tnbbs_deforestation_and_neighbor_overlap(): void
    {
        $this->assertSame(2, ProtectedZone::count());
        $this->assertSame(15, FarmPlot::count());

        $compliantCount = FarmPlot::where('compliance_status', 'COMPLIANT')->count();
        $deforestationCount = FarmPlot::where('compliance_status', 'REJECTED_DEFORESTATION')->count();
        $overlapCount = FarmPlot::where('compliance_status', 'REJECTED_OVERLAP')->count();

        $this->assertSame(10, $compliantCount);
        $this->assertSame(3, $deforestationCount);
        $this->assertSame(2, $overlapCount);

        $encroachedPlot = FarmPlot::where('plot_code', 'LP-PLT-012')->firstOrFail();
        $this->assertGreaterThan(1.0, $encroachedPlot->forest_overlap_pct);
        $this->assertNotEmpty($encroachedPlot->conflict_intersection_ring);
    }

    public function test_biological_yield_ceiling_formula_matches_agronomic_specification(): void
    {
        $yieldService = app(YieldEnvelopeService::class);

        // 1.00 ha, 1000 trees/ha, ROBUSTA_COFFEE (1.35), Age 8 yrs (1.00), Elev 750m (1.05) => 1417.50 kg
        $calc = $yieldService->calculateBiologicalCeiling('ROBUSTA_COFFEE', 1.00, 1000, 8, 750);

        $this->assertSame(1417.50, $calc['annual_yield_ceiling_kg']);
        $this->assertSame(1.35, $calc['base_yield_kg_per_tree']);
        $this->assertSame(1.00, $calc['age_factor_phi']);
        $this->assertSame(1.05, $calc['elevation_factor_psi']);
    }

    public function test_anti_laundering_gatekeeper_blocks_over_quota_harvest_and_preserves_remaining_quota(): void
    {
        $yieldService = app(YieldEnvelopeService::class);

        $plot = FarmPlot::where('plot_code', 'LP-PLT-001')->firstOrFail();
        $quotaBefore = (float) $plot->remaining_quota_kg;
        $this->assertEqualsWithDelta(340.00, $quotaBefore, 0.05);

        // Attempt to launder 1,600 kg through plot with only ~340 kg remaining quota
        $ledger = $yieldService->processWeighIn($plot, 1600.00, 12.2, false);

        $this->assertSame('BLOCKED_LAUNDERING', $ledger->verdict);
        $this->assertEqualsWithDelta(1260.00, (float) $ledger->excess_laundered_kg, 0.05);
        $this->assertEqualsWithDelta($quotaBefore, (float) $plot->fresh()->remaining_quota_kg, 0.01);
    }

    public function test_sha256_harvest_ledger_chain_is_cryptographically_intact(): void
    {
        $yieldService = app(YieldEnvelopeService::class);
        $integrity = $yieldService->verifyLedgerIntegrity();

        $this->assertSame(25, $integrity['total_records']);
        $this->assertSame(0, $integrity['broken_links']);
        $this->assertTrue($integrity['chain_intact']);
        $this->assertSame(64, strlen($integrity['head_record_hash']));
    }

    public function test_eudr_export_compiler_generates_valid_merkle_root_and_rfc7946_geojson(): void
    {
        $container = ExportContainer::where('container_code', 'EUDR-DDS-2026-001')->firstOrFail();

        $this->assertSame(64, strlen($container->merkle_root_hash));
        $geojson = json_decode($container->rfc7946_geojson, true);

        $this->assertIsArray($geojson);
        $this->assertSame('FeatureCollection', $geojson['type']);
        $this->assertNotEmpty($geojson['features']);

        $firstFeature = $geojson['features'][0];
        $this->assertSame('Feature', $firstFeature['type']);
        $this->assertSame('Polygon', $firstFeature['geometry']['type']);
        $this->assertTrue($firstFeature['properties']['deforestation_free_verified']);

        // Verify HTTP endpoints respond 200 OK
        $this->get('/')->assertStatus(200)->assertSee('TerraProof');
        $this->get('/containers/EUDR-DDS-2026-001/passport')->assertStatus(200)->assertSee($container->merkle_root_hash);
        $this->get('/api/containers/EUDR-DDS-2026-001/geojson')->assertStatus(200);

        $this->postJson('/api/audit-polygon', ['preset' => 'canopy_spike_walk'])
            ->assertStatus(200)
            ->assertJsonPath('status', 'ok');
    }

    public function test_slow_terrace_corner_turn_is_preserved_while_fast_multipath_spike_is_stripped(): void
    {
        $service = app(GeodesicAuditService::class);

        // Construct a triangular/terraced plot with an acute (~12 deg) switchback corner walked slowly (v <= 1.8 m/s, accuracy 3.5m)
        $trackWithSlowSwitchback = [
            ['lat' => -5.012000, 'lng' => 104.061000, 'timestamp' => 1760500000, 'accuracy_m' => 3.5],
            // Legitimate acute terrace switchback vertex: ~22m away, walked over 25 seconds (~0.88 m/s <= 1.8 m/s)
            ['lat' => -5.012000, 'lng' => 104.061200, 'timestamp' => 1760500025, 'accuracy_m' => 3.5],
            ['lat' => -5.011965, 'lng' => 104.061020, 'timestamp' => 1760500050, 'accuracy_m' => 3.5],
            ['lat' => -5.011600, 'lng' => 104.061000, 'timestamp' => 1760500080, 'accuracy_m' => 3.5],
        ];

        $result = $service->sanitizeGpsTrack($trackWithSlowSwitchback);
        $this->assertSame(0, $result['spikes_removed'], 'Slow legitimate terrace switchback (v <= 1.8 m/s) must not be falsely stripped');
        $this->assertSame(4, $result['clean_vertex_count']);
    }

    public function test_intercropping_factor_kappa_scales_biological_yield_ceiling_deterministically(): void
    {
        $yieldService = app(YieldEnvelopeService::class);

        $mono = $yieldService->calculateBiologicalCeiling('ROBUSTA_COFFEE', 1.00, 1000, 8, 750, 'MONOCULTURE');
        $shade = $yieldService->calculateBiologicalCeiling('ROBUSTA_COFFEE', 1.00, 1000, 8, 750, 'SHADE_AGROFORESTRY');
        $multi = $yieldService->calculateBiologicalCeiling('ROBUSTA_COFFEE', 1.00, 1000, 8, 750, 'MULTI_CROP_INTERCROP');

        $this->assertSame(1417.50, $mono['annual_yield_ceiling_kg']);
        $this->assertSame(1.00, $mono['intercrop_factor_kappa']);

        // 1417.50 * 0.92 = 1304.10 kg
        $this->assertSame(1304.10, $shade['annual_yield_ceiling_kg']);
        $this->assertSame(0.92, $shade['intercrop_factor_kappa']);

        // 1417.50 * 0.85 = 1204.88 kg
        $this->assertSame(1204.88, $multi['annual_yield_ceiling_kg']);
        $this->assertSame(0.85, $multi['intercrop_factor_kappa']);
    }

    public function test_sha256_payload_tamper_detection_catches_single_centigram_sql_mutation_and_restores_cleanly(): void
    {
        // 1. Verify initial chain is 100% intact via API
        $this->postJson('/api/verify-ledger-chain')
            ->assertStatus(200)
            ->assertJsonPath('chain_status.chain_intact', true)
            ->assertJsonPath('chain_status.broken_links', 0);

        // 2. Simulate a +0.01 kg SQL tampering attack on a historical record
        $tamperRes = $this->postJson('/api/simulate-tamper', ['mode' => 'corrupt'])
            ->assertStatus(200)
            ->assertJsonPath('chain_status.chain_intact', false);

        $this->assertGreaterThanOrEqual(1, $tamperRes->json('chain_status.broken_links'));
        $this->assertNotEmpty($tamperRes->json('chain_status.corrupted_blocks'));

        // 3. Restore tampered record and confirm 100% integrity is recovered
        $this->postJson('/api/simulate-tamper', ['mode' => 'restore'])
            ->assertStatus(200)
            ->assertJsonPath('chain_status.chain_intact', true)
            ->assertJsonPath('chain_status.broken_links', 0);
    }

    public function test_api_validation_rejects_degenerate_polygons_negative_weights_and_excessive_moisture(): void
    {
        // 2-vertex degenerate polygon must return 422
        $this->postJson('/api/audit-polygon', [
            'raw_gps_track' => [
                ['lat' => -5.012000, 'lng' => 104.061000, 'timestamp' => 1760500000],
                ['lat' => -5.012500, 'lng' => 104.061500, 'timestamp' => 1760500030],
            ],
        ])->assertStatus(422)->assertJsonPath('error_code', 'DEGENERATE_POLYGON_RING');

        // Negative harvest weight must return 422
        $this->postJson('/api/weigh-in', [
            'plot_code' => 'LP-PLT-001',
            'weight_dry_kg' => -45.0,
            'moisture_pct' => 11.5,
        ])->assertStatus(422)->assertJsonPath('error_code', 'INVALID_HARVEST_MASS');

        // Moisture > 13.5% must return 422
        $this->postJson('/api/weigh-in', [
            'plot_code' => 'LP-PLT-001',
            'weight_dry_kg' => 120.0,
            'moisture_pct' => 18.4,
        ])->assertStatus(422)->assertJsonPath('error_code', 'MOISTURE_QUALITY_GATE_BREACH');
    }

    public function test_offline_sync_queue_enforces_client_tx_id_idempotency(): void
    {
        $payload = [
            'items' => [
                [
                    'id' => 'OFFLINE-TEST-IDEMPOTENT-001',
                    'type' => 'WEIGH_IN',
                    'plot_code' => 'LP-PLT-002',
                    'weight_dry_kg' => 115.0,
                    'moisture_pct' => 11.4,
                    'queued_at' => '2026-10-17T09:30:00Z',
                ],
            ],
        ];

        // First sync inserts 1 record
        $this->postJson('/api/sync-offline-queue', $payload)
            ->assertStatus(200)
            ->assertJsonPath('synced_count', 1)
            ->assertJsonPath('skipped_duplicates', 0);

        // Second sync with identical client_tx_id skips duplicate and does not double-deduct quota
        $this->postJson('/api/sync-offline-queue', $payload)
            ->assertStatus(200)
            ->assertJsonPath('synced_count', 0)
            ->assertJsonPath('skipped_duplicates', 1);
    }
}

