<?php

namespace Database\Seeders;

use App\Models\ExportContainer;
use App\Models\Farmer;
use App\Models\FarmPlot;
use App\Models\HarvestLedger;
use App\Models\ProtectedZone;
use App\Services\EudrExportCompiler;
use App\Services\GeodesicAuditService;
use App\Services\YieldEnvelopeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LampungAgroClusterSeeder extends Seeder
{
    /**
     * Seed realistic West Lampung (Sumber Jaya, Balik Bukit, Way Tenong) & Tanggamus (Ulubelu)
     * smallholder agro-forestry data bordering Bukit Barisan Selatan National Park (TNBBS).
     */
    public function run(): void
    {
        $geodesicService = app(GeodesicAuditService::class);
        $yieldService = app(YieldEnvelopeService::class);
        $exportCompiler = app(EudrExportCompiler::class);

        DB::statement('PRAGMA foreign_keys = OFF;');
        ExportContainer::truncate();
        HarvestLedger::truncate();
        FarmPlot::truncate();
        Farmer::truncate();
        ProtectedZone::truncate();
        DB::statement('PRAGMA foreign_keys = ON;');

        // =========================================================================
        // 1. SEED 2 PROTECTED FOREST ZONES (TNBBS UNESCO RAINFOREST & REGISTER 39)
        // =========================================================================
        $tnbbsRing = $geodesicService->ensureCounterClockwiseAndClosed([
            [104.034000, -5.048000],
            [104.056000, -5.048000],
            [104.056000, -4.992000],
            [104.034000, -4.992000],
            [104.034000, -5.048000],
        ]);

        ProtectedZone::create([
            'zone_code' => 'TNBBS-SECTOR-LIWA',
            'name' => 'Bukit Barisan Selatan National Park (UNESCO World Heritage Rainforest)',
            'zone_type' => 'NATIONAL_PARK',
            'polygon_ring' => $tnbbsRing,
            'wkt_polygon' => $geodesicService->ringToWkt($tnbbsRing),
            'area_hectares' => $geodesicService->computeGeodesicAreaHa($tnbbsRing),
            'managing_authority' => 'Balai Besar TNBBS — Ministry of Environment and Forestry (KLHK RI)',
        ]);

        $reg39Ring = $geodesicService->ensureCounterClockwiseAndClosed([
            [104.056500, -5.010200],
            [104.072000, -5.010200],
            [104.072000, -4.996000],
            [104.056500, -4.996000],
            [104.056500, -5.010200],
        ]);

        ProtectedZone::create([
            'zone_code' => 'HL-REG-39',
            'name' => 'Register 39 Kota Agung Utara Protected Forest Corridor',
            'zone_type' => 'PROTECTED_FOREST',
            'polygon_ring' => $reg39Ring,
            'wkt_polygon' => $geodesicService->ringToWkt($reg39Ring),
            'area_hectares' => $geodesicService->computeGeodesicAreaHa($reg39Ring),
            'managing_authority' => 'KPHL Kota Agung Utara — Lampung Provincial Forestry Service',
        ]);

        // =========================================================================
        // 2. SEED 12 SMALLHOLDER FARMERS (KOPERASI PRODUSEN KOPI LESTARI LAMPUNG)
        // =========================================================================
        $farmerSpecs = [
            ['code' => 'FRM-LP-001', 'name' => 'Hendra Wijaya Kusuma', 'village' => 'Tugu Sari', 'district' => 'Sumber Jaya', 'regency' => 'West Lampung', 'phone' => '+62-812-7901-****'],
            ['code' => 'FRM-LP-002', 'name' => 'Siti Rahmawati Putri', 'village' => 'Sindang Pagar', 'district' => 'Sumber Jaya', 'regency' => 'West Lampung', 'phone' => '+62-813-6822-****'],
            ['code' => 'FRM-LP-003', 'name' => 'Ahmad Syarifuddin', 'village' => 'Way Empulau Ulu', 'district' => 'Balik Bukit', 'regency' => 'West Lampung', 'phone' => '+62-821-7740-****'],
            ['code' => 'FRM-LP-004', 'name' => 'Budi Santoso Pratama', 'village' => 'Padang Cahya', 'district' => 'Balik Bukit', 'regency' => 'West Lampung', 'phone' => '+62-852-6619-****'],
            ['code' => 'FRM-LP-005', 'name' => 'Dewi Sartika Ningsih', 'village' => 'Puralaksana', 'district' => 'Way Tenong', 'regency' => 'West Lampung', 'phone' => '+62-812-7290-****'],
            ['code' => 'FRM-LP-006', 'name' => 'M. Ridwan Al-Farizi', 'village' => 'Karang Agung', 'district' => 'Way Tenong', 'regency' => 'West Lampung', 'phone' => '+62-813-7988-****'],
            ['code' => 'FRM-LP-007', 'name' => 'Gunawan Setiawan', 'village' => 'Mutun Jaya', 'district' => 'Sumber Jaya', 'regency' => 'West Lampung', 'phone' => '+62-822-8104-****'],
            ['code' => 'FRM-LP-008', 'name' => 'Nurhayati Hasanah', 'village' => 'Datarajan', 'district' => 'Ulubelu', 'regency' => 'Tanggamus', 'phone' => '+62-811-7205-****'],
            ['code' => 'FRM-LP-009', 'name' => 'Zulkifli Hasan Basri', 'village' => 'Gunung Tiga', 'district' => 'Ulubelu', 'regency' => 'Tanggamus', 'phone' => '+62-853-8411-****'],
            ['code' => 'FRM-LP-010', 'name' => 'Lestari Handayani', 'village' => 'Ngarip', 'district' => 'Ulubelu', 'regency' => 'Tanggamus', 'phone' => '+62-812-6509-****'],
            ['code' => 'FRM-LP-011', 'name' => 'Suhendar Pranoto', 'village' => 'Kubu Perahu', 'district' => 'Balik Bukit', 'regency' => 'West Lampung', 'phone' => '+62-821-7603-****'],
            ['code' => 'FRM-LP-012', 'name' => 'Taufik Hidayatullah', 'village' => 'Sukapura', 'district' => 'Sumber Jaya', 'regency' => 'West Lampung', 'phone' => '+62-813-6955-****'],
        ];

        $farmers = [];
        foreach ($farmerSpecs as $idx => $spec) {
            $farmers[$spec['code']] = Farmer::create([
                'farmer_code' => $spec['code'],
                'full_name' => $spec['name'],
                'cooperative_name' => 'Koperasi Produsen Kopi Lestari Lampung',
                'village' => $spec['village'],
                'district' => $spec['district'],
                'regency' => $spec['regency'],
                'national_id_sha256' => hash('sha256', 'NIK-1804-LAMPUNG-'.$spec['code']),
                'phone_masked' => $spec['phone'],
                'registered_at' => now()->subDays(180 - ($idx * 7)),
            ]);
        }

        // =========================================================================
        // 3. SEED 15 FARM PLOTS (10 COMPLIANT, 3 DEFORESTATION, 2 NEIGHBOR OVERLAP)
        //    Cohesive West Lampung Cadastral Corridor (104.0558°E – 104.0642°E)
        // =========================================================================
        $plotBlueprints = [
            // --- 10 COMPLIANT PLOTS (Safe Agro-Forestry Corridor East of 104.0568) ---
            [
                'code' => 'LP-PLT-001',
                'farmer' => 'FRM-LP-001',
                'name' => 'Sumber Jaya Block A-01 (Tugu Sari Terrace)',
                'commodity' => 'ROBUSTA_COFFEE',
                'center_lng' => 104.057900,
                'center_lat' => -5.011700,
                'half_w_deg' => 0.000430,
                'half_h_deg' => 0.000400,
                'spikes' => 4,
                'elevation_m' => 780,
                'density' => 1100,
                'age' => 8,
            ],
            [
                'code' => 'LP-PLT-002',
                'farmer' => 'FRM-LP-002',
                'name' => 'Sindang Pagar Shade Grown Coffee B-02',
                'commodity' => 'ROBUSTA_COFFEE',
                'center_lng' => 104.059600,
                'center_lat' => -5.011900,
                'half_w_deg' => 0.000520,
                'half_h_deg' => 0.000480,
                'spikes' => 4,
                'elevation_m' => 810,
                'density' => 1150,
                'age' => 9,
            ],
            [
                'code' => 'LP-PLT-003',
                'farmer' => 'FRM-LP-003',
                'name' => 'Way Empulau Highland Robusta C-03',
                'commodity' => 'ROBUSTA_COFFEE',
                'center_lng' => 104.061700,
                'center_lat' => -5.011600,
                'half_w_deg' => 0.000560,
                'half_h_deg' => 0.000510,
                'spikes' => 3,
                'elevation_m' => 845,
                'density' => 1200,
                'age' => 11,
            ],
            [
                'code' => 'LP-PLT-004',
                'farmer' => 'FRM-LP-004',
                'name' => 'Padang Cahya Volcanic Loam Plot D-04',
                'commodity' => 'ROBUSTA_COFFEE',
                'center_lng' => 104.063500,
                'center_lat' => -5.012100,
                'half_w_deg' => 0.000490,
                'half_h_deg' => 0.000460,
                'spikes' => 5,
                'elevation_m' => 760,
                'density' => 1100,
                'age' => 7,
            ],
            [
                'code' => 'LP-PLT-005',
                'farmer' => 'FRM-LP-005',
                'name' => 'Puralaksana Way Tenong Estate E-05',
                'commodity' => 'ROBUSTA_COFFEE',
                'center_lng' => 104.058100,
                'center_lat' => -5.014200,
                'half_w_deg' => 0.000580,
                'half_h_deg' => 0.000520,
                'spikes' => 4,
                'elevation_m' => 720,
                'density' => 1150,
                'age' => 10,
            ],
            [
                'code' => 'LP-PLT-006',
                'farmer' => 'FRM-LP-006',
                'name' => 'Karang Agung Agro-Forestry Parcel F-06',
                'commodity' => 'COCOA',
                'center_lng' => 104.060900,
                'center_lat' => -5.014300,
                'half_w_deg' => 0.000500,
                'half_h_deg' => 0.000470,
                'spikes' => 3,
                'elevation_m' => 640,
                'density' => 950,
                'age' => 8,
            ],
            [
                'code' => 'LP-PLT-007',
                'farmer' => 'FRM-LP-007',
                'name' => 'Mutun Jaya Ridge Robusta G-07',
                'commodity' => 'ROBUSTA_COFFEE',
                'center_lng' => 104.063200,
                'center_lat' => -5.014500,
                'half_w_deg' => 0.000540,
                'half_h_deg' => 0.000480,
                'spikes' => 5,
                'elevation_m' => 795,
                'density' => 1180,
                'age' => 12,
            ],
            [
                'code' => 'LP-PLT-008',
                'farmer' => 'FRM-LP-008',
                'name' => 'Datarajan Ulubelu Geodesic Plot H-08',
                'commodity' => 'ROBUSTA_COFFEE',
                'center_lng' => 104.058200,
                'center_lat' => -5.016900,
                'half_w_deg' => 0.000570,
                'half_h_deg' => 0.000520,
                'spikes' => 4,
                'elevation_m' => 830,
                'density' => 1200,
                'age' => 9,
            ],
            [
                'code' => 'LP-PLT-009',
                'farmer' => 'FRM-LP-009',
                'name' => 'Gunung Tiga Pepper & Coffee Intercrop I-09',
                'commodity' => 'BLACK_PEPPER',
                'center_lng' => 104.060700,
                'center_lat' => -5.017000,
                'half_w_deg' => 0.000460,
                'half_h_deg' => 0.000440,
                'spikes' => 3,
                'elevation_m' => 690,
                'density' => 1400,
                'age' => 6,
            ],
            [
                'code' => 'LP-PLT-010',
                'farmer' => 'FRM-LP-010',
                'name' => 'Ngarip Sustainable Canopy Lot J-10',
                'commodity' => 'COCOA',
                'center_lng' => 104.063100,
                'center_lat' => -5.017100,
                'half_w_deg' => 0.000530,
                'half_h_deg' => 0.000490,
                'spikes' => 4,
                'elevation_m' => 610,
                'density' => 1000,
                'age' => 10,
            ],

            // --- 3 REJECTED_DEFORESTATION PLOTS (Western Vertices Cross TNBBS Border at 104.056000) ---
            [
                'code' => 'LP-PLT-011',
                'farmer' => 'FRM-LP-011',
                'name' => 'Kubu Perahu Western Margin K-11 (TNBBS Encroachment)',
                'commodity' => 'ROBUSTA_COFFEE',
                'center_lng' => 104.056400,
                'center_lat' => -5.011900,
                'half_w_deg' => 0.000550,
                'half_h_deg' => 0.000480,
                'spikes' => 3,
                'elevation_m' => 880,
                'density' => 1100,
                'age' => 4,
            ],
            [
                'code' => 'LP-PLT-012',
                'farmer' => 'FRM-LP-011',
                'name' => 'Liwa Corridor Buffer Breach L-12 (TNBBS Encroachment)',
                'commodity' => 'ROBUSTA_COFFEE',
                'center_lng' => 104.056200,
                'center_lat' => -5.014400,
                'half_w_deg' => 0.000600,
                'half_h_deg' => 0.000500,
                'spikes' => 4,
                'elevation_m' => 910,
                'density' => 1050,
                'age' => 3,
            ],
            [
                'code' => 'LP-PLT-013',
                'farmer' => 'FRM-LP-012',
                'name' => 'Sukapura Deep Canopy Intrusion M-13 (TNBBS Encroachment)',
                'commodity' => 'ROBUSTA_COFFEE',
                'center_lng' => 104.055850,
                'center_lat' => -5.017000,
                'half_w_deg' => 0.000580,
                'half_h_deg' => 0.000520,
                'spikes' => 4,
                'elevation_m' => 860,
                'density' => 1100,
                'age' => 5,
            ],

            // --- 2 REJECTED_OVERLAP PLOTS (Overlapping ~15-18% with Neighboring Compliant Plots) ---
            [
                // Overlaps eastern edge of LP-PLT-002 (104.059600, -5.011900)
                'code' => 'LP-PLT-014',
                'farmer' => 'FRM-LP-012',
                'name' => 'Sindang Pagar Disputed Boundary Claim N-14',
                'commodity' => 'ROBUSTA_COFFEE',
                'center_lng' => 104.060320,
                'center_lat' => -5.011900,
                'half_w_deg' => 0.000480,
                'half_h_deg' => 0.000460,
                'spikes' => 3,
                'elevation_m' => 805,
                'density' => 1100,
                'age' => 7,
            ],
            [
                // Overlaps eastern edge of LP-PLT-005 (104.058100, -5.014200)
                'code' => 'LP-PLT-015',
                'farmer' => 'FRM-LP-004',
                'name' => 'Puralaksana Double-Claimed Cadastral Lot O-15',
                'commodity' => 'ROBUSTA_COFFEE',
                'center_lng' => 104.058920,
                'center_lat' => -5.014200,
                'half_w_deg' => 0.000520,
                'half_h_deg' => 0.000500,
                'spikes' => 3,
                'elevation_m' => 725,
                'density' => 1150,
                'age' => 8,
            ],
        ];

        $plotsByCode = [];
        foreach ($plotBlueprints as $idx => $bp) {
            $rawTrack = $this->buildOctagonalTrackWithCanopySpikes(
                $bp['center_lng'],
                $bp['center_lat'],
                $bp['half_w_deg'],
                $bp['half_h_deg'],
                $bp['spikes'],
                $idx
            );

            $sanitized = $geodesicService->sanitizeGpsTrack($rawTrack);
            $spatialAudit = $geodesicService->auditSpatialConflicts($sanitized['clean_polygon_ring']);
            $yieldCalc = $yieldService->calculateBiologicalCeiling(
                $bp['commodity'],
                $sanitized['area_hectares'],
                $bp['density'],
                $bp['age'],
                $bp['elevation_m']
            );

            $plot = FarmPlot::create([
                'farmer_id' => $farmers[$bp['farmer']]->id,
                'plot_code' => $bp['code'],
                'plot_name' => $bp['name'],
                'commodity' => $bp['commodity'],
                'raw_gps_track' => $rawTrack,
                'clean_polygon_ring' => $sanitized['clean_polygon_ring'],
                'wkt_polygon' => $sanitized['wkt_polygon'],
                'raw_vertex_count' => $sanitized['raw_vertex_count'],
                'clean_vertex_count' => $sanitized['clean_vertex_count'],
                'spikes_removed' => $sanitized['spikes_removed'],
                'area_raw_ha' => $sanitized['area_raw_ha'],
                'area_hectares' => $sanitized['area_hectares'],
                'elevation_m' => $bp['elevation_m'],
                'tree_density_per_ha' => $bp['density'],
                'tree_age_years' => $bp['age'],
                'annual_yield_ceiling_kg' => $yieldCalc['annual_yield_ceiling_kg'],
                'harvested_ytd_kg' => 0.0,
                'remaining_quota_kg' => $yieldCalc['annual_yield_ceiling_kg'],
                'forest_overlap_pct' => $spatialAudit['forest_overlap_pct'],
                'neighbor_overlap_pct' => $spatialAudit['neighbor_overlap_pct'],
                'conflict_intersection_ring' => $spatialAudit['conflict_intersection_ring'],
                'compliance_status' => $spatialAudit['compliance_status'],
                'audit_latency_ms' => $spatialAudit['audit_latency_ms'],
                'last_audited_at' => now()->subHours(6 + ($idx * 2)),
            ]);

            $plotsByCode[$bp['code']] = $plot;
        }

        // =========================================================================
        // 4. SEED 25 HASH-CHAINED HARVEST LEDGER TRANSACTIONS
        //    (20 APPROVED + 5 INTERCEPTED LAUNDERING / NON-COMPLIANT BREACHES)
        // =========================================================================
        $p1Ceiling = (float) $plotsByCode['LP-PLT-001']->annual_yield_ceiling_kg;
        // Leave exactly 340.00 kg of remaining biological quota on LP-PLT-001 before the 1,650 kg laundering attack
        $p1LegitFirst = round(($p1Ceiling - 340.00) * 0.55, 2);
        $p1LegitSecond = round(($p1Ceiling - 340.00) - $p1LegitFirst, 2);

        $ledgerEvents = [
            // Batch 1: Legitimate Early-Season Harvests
            ['plot' => 'LP-PLT-001', 'kg' => $p1LegitFirst, 'moisture' => 11.8, 'offline' => false, 'days_ago' => 35],
            ['plot' => 'LP-PLT-001', 'kg' => $p1LegitSecond, 'moisture' => 11.5, 'offline' => true,  'days_ago' => 32],
            // ATTACK #1 (Flagship Case): Attempt to launder 1,650 kg of illegal encroachment coffee through LP-PLT-001 (only 340 kg quota left)
            ['plot' => 'LP-PLT-001', 'kg' => 1650.00,       'moisture' => 12.4, 'offline' => false, 'days_ago' => 30],

            ['plot' => 'LP-PLT-002', 'kg' => 420.00,        'moisture' => 11.6, 'offline' => false, 'days_ago' => 29],
            ['plot' => 'LP-PLT-002', 'kg' => 380.00,        'moisture' => 11.9, 'offline' => true,  'days_ago' => 27],
            ['plot' => 'LP-PLT-003', 'kg' => 510.00,        'moisture' => 11.4, 'offline' => false, 'days_ago' => 26],
            ['plot' => 'LP-PLT-003', 'kg' => 490.00,        'moisture' => 11.7, 'offline' => false, 'days_ago' => 24],
            // ATTACK #2: Over-quota laundering attempt on LP-PLT-003
            ['plot' => 'LP-PLT-003', 'kg' => 1850.00,       'moisture' => 12.8, 'offline' => true,  'days_ago' => 23],

            ['plot' => 'LP-PLT-004', 'kg' => 360.00,        'moisture' => 11.5, 'offline' => false, 'days_ago' => 22],
            ['plot' => 'LP-PLT-004', 'kg' => 310.00,        'moisture' => 11.8, 'offline' => false, 'days_ago' => 20],
            ['plot' => 'LP-PLT-005', 'kg' => 480.00,        'moisture' => 11.3, 'offline' => true,  'days_ago' => 19],
            ['plot' => 'LP-PLT-005', 'kg' => 440.00,        'moisture' => 11.6, 'offline' => false, 'days_ago' => 18],
            // ATTACK #3: Direct delivery attempt from TNBBS Deforestation Plot LP-PLT-012
            ['plot' => 'LP-PLT-012', 'kg' => 920.00,        'moisture' => 12.9, 'offline' => false, 'days_ago' => 17],

            ['plot' => 'LP-PLT-006', 'kg' => 390.00,        'moisture' => 7.2,  'offline' => false, 'days_ago' => 16],
            ['plot' => 'LP-PLT-006', 'kg' => 340.00,        'moisture' => 7.4,  'offline' => false, 'days_ago' => 15],
            ['plot' => 'LP-PLT-007', 'kg' => 460.00,        'moisture' => 11.5, 'offline' => true,  'days_ago' => 14],
            ['plot' => 'LP-PLT-007', 'kg' => 410.00,        'moisture' => 11.7, 'offline' => false, 'days_ago' => 13],
            // ATTACK #4: Over-quota laundering injection on LP-PLT-007
            ['plot' => 'LP-PLT-007', 'kg' => 1720.00,       'moisture' => 12.5, 'offline' => false, 'days_ago' => 12],

            ['plot' => 'LP-PLT-008', 'kg' => 520.00,        'moisture' => 11.2, 'offline' => false, 'days_ago' => 10],
            ['plot' => 'LP-PLT-008', 'kg' => 470.00,        'moisture' => 11.6, 'offline' => true,  'days_ago' => 9],
            ['plot' => 'LP-PLT-009', 'kg' => 410.00,        'moisture' => 10.8, 'offline' => false, 'days_ago' => 8],
            ['plot' => 'LP-PLT-009', 'kg' => 350.00,        'moisture' => 10.9, 'offline' => false, 'days_ago' => 7],
            // ATTACK #5: Over-quota laundering injection on LP-PLT-004
            ['plot' => 'LP-PLT-004', 'kg' => 1490.00,       'moisture' => 12.6, 'offline' => false, 'days_ago' => 5],

            ['plot' => 'LP-PLT-010', 'kg' => 430.00,        'moisture' => 7.1,  'offline' => false, 'days_ago' => 4],
            ['plot' => 'LP-PLT-010', 'kg' => 395.00,        'moisture' => 7.3,  'offline' => true,  'days_ago' => 2],
        ];

        $approvedIdsHamburg = [];
        $approvedIdsTrieste = [];

        foreach ($ledgerEvents as $idx => $ev) {
            $plot = $plotsByCode[$ev['plot']]->fresh();
            $eventMoment = now()->subDays($ev['days_ago'])->addMinutes($idx * 11);

            $ledger = $yieldService->processWeighIn(
                $plot,
                $ev['kg'],
                $ev['moisture'],
                $ev['offline'],
                $eventMoment->toIso8601String(),
                $eventMoment->toDateString()
            );

            if ($ledger->verdict === 'APPROVED') {
                if (count($approvedIdsHamburg) < 11) {
                    $approvedIdsHamburg[] = $ledger->id;
                } else {
                    $approvedIdsTrieste[] = $ledger->id;
                }
            }
        }

        // =========================================================================
        // 5. COMPILE 2 EXPORT CONTAINERS (HAMBURG, GERMANY & TRIESTE, ITALY)
        // =========================================================================
        $exportCompiler->compileContainer(
            $approvedIdsHamburg,
            'Neumann Kaffee Gruppe (NKG) — Hamburg Green Coffee Division GmbH',
            'Port of Hamburg, Germany (DEHAM)',
            'EUDR-DDS-2026-001'
        );

        $exportCompiler->compileContainer(
            $approvedIdsTrieste,
            'Lavazza Group — Trieste Adriatic Specialty Terminal S.p.A.',
            'Port of Trieste, Italy (ITTRS)',
            'EUDR-DDS-2026-002'
        );
    }

    /**
     * Build a realistic 8-vertex octagonal field walk in WGS84 coordinates and inject
     * deterministic canopy multipath GPS spikes (outward jumps of 42-58 meters over 1 second).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildOctagonalTrackWithCanopySpikes(
        float $centerLng,
        float $centerLat,
        float $halfW,
        float $halfH,
        int $spikeCount,
        int $seedIdx = 0
    ): array {
        // Organic terrace corner variation for compliant parcels so polygons look like authentic surveyed farm plots
        $skewA = ($seedIdx < 10) ? (0.68 + (($seedIdx % 3) * 0.06)) : 0.75;
        $skewB = ($seedIdx < 10) ? (0.58 + ((($seedIdx + 1) % 3) * 0.07)) : 0.65;
        $skewC = ($seedIdx < 10) ? (0.72 + ((($seedIdx + 2) % 3) * 0.05)) : 0.75;

        $baseVertices = [
            ['lng' => $centerLng - ($halfW * $skewA), 'lat' => $centerLat - $halfH],
            ['lng' => $centerLng + ($halfW * $skewC), 'lat' => $centerLat - ($halfH * 0.96)],
            ['lng' => $centerLng + $halfW,            'lat' => $centerLat - ($halfH * $skewB)],
            ['lng' => $centerLng + ($halfW * 0.97),   'lat' => $centerLat + ($halfH * $skewA)],
            ['lng' => $centerLng + ($halfW * $skewB), 'lat' => $centerLat + $halfH],
            ['lng' => $centerLng - ($halfW * $skewC), 'lat' => $centerLat + ($halfH * 0.95)],
            ['lng' => $centerLng - $halfW,            'lat' => $centerLat + ($halfH * $skewB)],
            ['lng' => $centerLng - ($halfW * 0.98),   'lat' => $centerLat - ($halfH * $skewB)],
        ];

        // Outward direction vectors for spike injection at selected octant vertices
        $spikeCandidateIndices = [1, 3, 5, 6, 7];
        $selectedSpikeMap = [];
        for ($s = 0; $s < min($spikeCount, count($spikeCandidateIndices)); $s++) {
            $selectedSpikeMap[$spikeCandidateIndices[$s]] = true;
        }

        $track = [];
        $currentTime = 1760500000;

        foreach ($baseVertices as $idx => $v) {
            $track[] = [
                'lat' => round($v['lat'], 6),
                'lng' => round($v['lng'], 6),
                'timestamp' => $currentTime,
                'accuracy_m' => 3.8,
            ];

            if (isset($selectedSpikeMap[$idx])) {
                // Inject a sharp outward canopy multipath reflection spike 1 second after vertex $idx
                $dirLng = ($v['lng'] >= $centerLng) ? 1.0 : -1.0;
                $dirLat = ($v['lat'] >= $centerLat) ? 1.0 : -1.0;
                $spikeOffsetDeg = 0.000380 + ($idx * 0.000025); // ~45 to 60 meters jump

                $track[] = [
                    'lat' => round($v['lat'] + ($dirLat * $spikeOffsetDeg), 6),
                    'lng' => round($v['lng'] + ($dirLng * $spikeOffsetDeg), 6),
                    'timestamp' => $currentTime + 1,
                    'accuracy_m' => 28.5,
                ];
            }

            // Advance 45 seconds between legitimate perimeter corners (~85m edge / 45s = 1.88 m/s walking pace)
            $currentTime += 45;
        }

        return $track;
    }
}
