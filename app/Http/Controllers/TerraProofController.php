<?php

namespace App\Http\Controllers;

use App\Models\ExportContainer;
use App\Models\Farmer;
use App\Models\FarmPlot;
use App\Models\HarvestLedger;
use App\Models\ProtectedZone;
use App\Services\EudrExportCompiler;
use App\Services\GeodesicAuditService;
use App\Services\YieldEnvelopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class TerraProofController extends Controller
{
    public function __construct(
        private readonly GeodesicAuditService $geodesicService,
        private readonly YieldEnvelopeService $yieldService,
        private readonly EudrExportCompiler $exportCompiler
    ) {}

    /**
     * Render the main Tactical Geospatial Intelligence Command Center.
     */
    public function dashboard(): View
    {
        $protectedZones = ProtectedZone::orderBy('id')->get();
        $plots = FarmPlot::with('farmer')->orderBy('id')->get();
        $ledgers = HarvestLedger::with('farmPlot.farmer')->orderByDesc('id')->get();
        $containers = ExportContainer::orderBy('id')->get();
        $farmers = Farmer::orderBy('id')->get();

        // Precompute biological formula breakdowns and GPS spike sanitization telemetry for each plot
        $plotFormulas = [];
        $plotSanitizations = [];
        foreach ($plots as $plot) {
            $plotFormulas[$plot->plot_code] = $this->yieldService->calculateBiologicalCeiling(
                $plot->commodity,
                (float) $plot->area_hectares,
                (int) $plot->tree_density_per_ha,
                (int) $plot->tree_age_years,
                (int) $plot->elevation_m,
                (string) ($plot->cropping_system ?? 'MONOCULTURE')
            );
            $plotSanitizations[$plot->plot_code] = $this->geodesicService->sanitizeGpsTrack(
                is_array($plot->raw_gps_track) ? $plot->raw_gps_track : []
            );
        }

        $kpis = $this->buildKpiSummary();
        $chainStatus = $this->yieldService->verifyLedgerIntegrity();

        $benchmarkJsonPath = public_path('benchmark-results.json');
        $benchmarkResults = file_exists($benchmarkJsonPath)
            ? json_decode((string) file_get_contents($benchmarkJsonPath), true)
            : null;

        return view('dashboard', [
            'protectedZones' => $protectedZones,
            'plots' => $plots,
            'plotFormulas' => $plotFormulas,
            'plotSanitizations' => $plotSanitizations,
            'ledgers' => $ledgers,
            'containers' => $containers,
            'farmers' => $farmers,
            'kpis' => $kpis,
            'chainStatus' => $chainStatus,
            'benchmarkResults' => $benchmarkResults,
        ]);
    }

    /**
     * Render the Printable Cryptographic EUDR Due Diligence Passport (DDS).
     */
    public function passport(string $code): View
    {
        $container = ExportContainer::where('container_code', $code)
            ->orWhere('id', $code)
            ->firstOrFail();

        $geojson = json_decode($container->rfc7946_geojson, true);
        $payload = $container->dds_passport_payload;
        $chainStatus = $this->yieldService->verifyLedgerIntegrity();

        return view('passport', [
            'container' => $container,
            'geojson' => $geojson,
            'payload' => $payload,
            'chainStatus' => $chainStatus,
        ]);
    }

    /**
     * Download the strict RFC 7946 GeoJSON FeatureCollection for European Commission Traces-NT upload.
     */
    public function downloadGeoJson(string $code): Response
    {
        $container = ExportContainer::where('container_code', $code)
            ->orWhere('id', $code)
            ->firstOrFail();

        $filename = $container->container_code.'-EU-TRACES-NT.geojson';

        return response($container->rfc7946_geojson, 200, [
            'Content-Type' => 'application/geo+json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Live Spatial & Agronomic Polygon Audit Endpoint (/api/audit-polygon).
     * Supports preset simulations ('canopy_spike_walk', 'tnbbs_encroachment_attack') and custom map drawings.
     */
    public function auditPolygon(Request $request): JsonResponse
    {
        $preset = $request->input('preset');
        $commodity = (string) $request->input('commodity', 'ROBUSTA_COFFEE');
        $elevationM = (int) $request->input('elevation_m', 790);
        $densityPerHa = (int) $request->input('tree_density_per_ha', 1150);
        $treeAgeYears = (int) $request->input('tree_age_years', 8);
        $croppingSystem = (string) $request->input('cropping_system', 'MONOCULTURE');
        $plotName = (string) $request->input('plot_name', 'Field Walk Telemetry Audit');

        if ($preset === 'canopy_spike_walk') {
            // High-visibility 14-vertex raw track with 4 extreme canopy multipath reflection jumps
            // that sanitizes cleanly to a 10-vertex (~1.18 ha) compliant polygon
            $rawTrack = $this->buildCanopySpikeDemoTrack();
            $plotName = 'Sumber Jaya Canopy Walk Simulation (4 Multipath Spikes)';
        } elseif ($preset === 'tnbbs_encroachment_attack') {
            // Polygon cutting across longitude 104.056000 into Bukit Barisan Selatan National Park
            $rawTrack = $this->buildTnbbsEncroachmentDemoTrack();
            $plotName = 'TNBBS Western Border Intrusion Simulation';
        } else {
            if ($request->has('raw_gps_track')) {
                $rawTrack = $request->input('raw_gps_track');
                if (! is_array($rawTrack) || count($rawTrack) < 3) {
                    return response()->json([
                        'status' => 'error',
                        'error_code' => 'DEGENERATE_POLYGON_RING',
                        'message' => 'Degenerate polygon rejected: at least 3 distinct GPS vertices are required to construct a closed WGS84 geodesic ring under EUDR Article 9(1)(d).',
                    ], 422);
                }
            } else {
                $rawTrack = $this->buildCanopySpikeDemoTrack();
            }
        }

        $sanitized = $this->geodesicService->sanitizeGpsTrack($rawTrack);
        if ($sanitized['clean_vertex_count'] < 3 || $sanitized['area_hectares'] <= 0.0) {
            return response()->json([
                'status' => 'error',
                'error_code' => 'ZERO_AREA_DEGENERATE_RING',
                'message' => 'Polygon sanitization resulted in fewer than 3 valid non-collinear vertices or zero geodesic area.',
            ], 422);
        }

        $spatialConflict = $this->geodesicService->auditSpatialConflicts($sanitized['clean_polygon_ring']);
        $yieldEnvelope = $this->yieldService->calculateBiologicalCeiling(
            $commodity,
            (float) $sanitized['area_hectares'],
            $densityPerHa,
            $treeAgeYears,
            $elevationM,
            $croppingSystem
        );
        $dualEngine = $this->geodesicService->computeDualEngineAreaHa($sanitized['clean_polygon_ring']);

        $persistedPlot = null;
        if ($request->boolean('persist')) {
            $farmer = Farmer::first();
            $nextCode = sprintf('LP-PLT-%03d', FarmPlot::count() + 1);
            $persistedPlot = FarmPlot::create([
                'farmer_id' => $farmer ? $farmer->id : 1,
                'plot_code' => $nextCode,
                'plot_name' => $plotName,
                'commodity' => $commodity,
                'cropping_system' => $croppingSystem,
                'raw_gps_track' => $rawTrack,
                'clean_polygon_ring' => $sanitized['clean_polygon_ring'],
                'wkt_polygon' => $sanitized['wkt_polygon'],
                'raw_vertex_count' => $sanitized['raw_vertex_count'],
                'clean_vertex_count' => $sanitized['clean_vertex_count'],
                'spikes_removed' => $sanitized['spikes_removed'],
                'area_raw_ha' => $sanitized['area_raw_ha'],
                'area_hectares' => $sanitized['area_hectares'],
                'elevation_m' => $elevationM,
                'tree_density_per_ha' => $densityPerHa,
                'tree_age_years' => $treeAgeYears,
                'annual_yield_ceiling_kg' => $yieldEnvelope['annual_yield_ceiling_kg'],
                'harvested_ytd_kg' => 0.0,
                'remaining_quota_kg' => $yieldEnvelope['annual_yield_ceiling_kg'],
                'forest_overlap_pct' => $spatialConflict['forest_overlap_pct'],
                'neighbor_overlap_pct' => $spatialConflict['neighbor_overlap_pct'],
                'conflict_intersection_ring' => $spatialConflict['conflict_intersection_ring'],
                'compliance_status' => $spatialConflict['compliance_status'],
                'audit_latency_ms' => $spatialConflict['audit_latency_ms'],
                'last_audited_at' => now(),
            ]);
        }

        return response()->json([
            'status' => 'ok',
            'plot_name' => $plotName,
            'sanitization' => $sanitized,
            'spatial_audit' => $spatialConflict,
            'yield_envelope' => $yieldEnvelope,
            'dual_engine_verification' => $dualEngine,
            'persisted_plot' => $persistedPlot,
            'kpis' => $this->buildKpiSummary(),
        ]);
    }

    /**
     * Live Harvest Weigh-In & Anti-Laundering Gatekeeper Endpoint (/api/weigh-in).
     */
    public function weighIn(Request $request): JsonResponse
    {
        $weightDryKg = (float) $request->input('weight_dry_kg', 220.0);
        $moisturePct = (float) $request->input('moisture_pct', 11.6);

        if ($weightDryKg <= 0.0) {
            return response()->json([
                'status' => 'error',
                'error_code' => 'INVALID_HARVEST_MASS',
                'message' => 'Weigh-in rejected: weight_dry_kg must be strictly greater than 0.00 kg.',
            ], 422);
        }

        if ($moisturePct > 13.5 || $moisturePct < 3.0) {
            return response()->json([
                'status' => 'error',
                'error_code' => 'MOISTURE_QUALITY_GATE_BREACH',
                'message' => sprintf(
                    'SNI 01-2907-2008 & EU Quality Gate Breach: Moisture content (%.1f%%) exceeds the 13.5%% maximum threshold for dry export parchment/beans.',
                    $moisturePct
                ),
            ], 422);
        }

        $plotCode = $request->input('plot_code');
        $plotId = $request->input('farm_plot_id');

        $plot = $plotCode
            ? FarmPlot::with('farmer')->where('plot_code', $plotCode)->firstOrFail()
            : FarmPlot::with('farmer')->findOrFail($plotId);

        if ($request->filled('cropping_system')) {
            $croppingSystem = strtoupper((string) $request->input('cropping_system'));
            if (in_array($croppingSystem, ['MONOCULTURE', 'SHADE_AGROFORESTRY', 'MULTI_CROP_INTERCROP'], true)) {
                $updatedCalc = $this->yieldService->calculateBiologicalCeiling(
                    $plot->commodity,
                    (float) $plot->area_hectares,
                    (int) $plot->tree_density_per_ha,
                    (int) $plot->tree_age_years,
                    (int) $plot->elevation_m,
                    $croppingSystem
                );
                $plot->update([
                    'cropping_system' => $croppingSystem,
                    'annual_yield_ceiling_kg' => $updatedCalc['annual_yield_ceiling_kg'],
                    'remaining_quota_kg' => max(0.0, round($updatedCalc['annual_yield_ceiling_kg'] - (float) $plot->harvested_ytd_kg, 2)),
                ]);
                $plot->refresh();
            }
        }

        $capturedOffline = $request->boolean('captured_offline', false);
        $clientTxId = $request->input('client_tx_id') ? (string) $request->input('client_tx_id') : null;

        $ledger = $this->yieldService->processWeighIn(
            $plot,
            $weightDryKg,
            $moisturePct,
            $capturedOffline,
            null,
            null,
            $clientTxId
        );

        $freshPlot = $plot->fresh(['farmer']);
        $formula = $this->yieldService->calculateBiologicalCeiling(
            $freshPlot->commodity,
            (float) $freshPlot->area_hectares,
            (int) $freshPlot->tree_density_per_ha,
            (int) $freshPlot->tree_age_years,
            (int) $freshPlot->elevation_m,
            (string) ($freshPlot->cropping_system ?? 'MONOCULTURE')
        );

        return response()->json([
            'status' => 'ok',
            'ledger' => $ledger->load('farmPlot.farmer'),
            'plot' => $freshPlot,
            'formula' => $formula,
            'kpis' => $this->buildKpiSummary(),
            'chain_status' => $this->yieldService->verifyLedgerIntegrity(),
        ]);
    }

    /**
     * Synchronize Offline Canopy Blank-Spot Queue (IndexedDB / localStorage) into the MySQL/SQLite Cryptographic Ledger.
     * Enforces idempotency via client_tx_id so retry packets never double-deduct farmer biological quota.
     */
    public function syncOfflineQueue(Request $request): JsonResponse
    {
        $items = $request->input('items', []);
        $syncedLedgers = [];
        $skippedDuplicates = 0;

        foreach ($items as $item) {
            $type = $item['type'] ?? 'WEIGH_IN';
            if ($type === 'WEIGH_IN') {
                $clientTxId = isset($item['id'])
                    ? (string) $item['id']
                    : (isset($item['client_tx_id']) ? (string) $item['client_tx_id'] : null);

                if ($clientTxId !== null && $clientTxId !== '') {
                    $existing = HarvestLedger::with('farmPlot.farmer')->where('client_tx_id', $clientTxId)->first();
                    if ($existing) {
                        $skippedDuplicates++;
                        continue;
                    }
                }

                $weightDryKg = (float) ($item['weight_dry_kg'] ?? 185.0);
                $moisturePct = (float) ($item['moisture_pct'] ?? 11.7);
                if ($weightDryKg <= 0.0 || $moisturePct > 13.5) {
                    continue;
                }

                $plotCode = $item['plot_code'] ?? 'LP-PLT-002';
                $plot = FarmPlot::where('plot_code', $plotCode)->first() ?? FarmPlot::first();
                if ($plot) {
                    $ledger = $this->yieldService->processWeighIn(
                        $plot,
                        $weightDryKg,
                        $moisturePct,
                        true,
                        isset($item['queued_at']) ? (string) $item['queued_at'] : null,
                        null,
                        $clientTxId
                    );
                    $syncedLedgers[] = $ledger->load('farmPlot.farmer');
                }
            }
        }

        return response()->json([
            'status' => 'ok',
            'synced_count' => count($syncedLedgers),
            'skipped_duplicates' => $skippedDuplicates,
            'synced_ledgers' => $syncedLedgers,
            'kpis' => $this->buildKpiSummary(),
            'chain_status' => $this->yieldService->verifyLedgerIntegrity(),
        ]);
    }

    /**
     * Live Cryptographic SHA-256 Hash-Chain & Payload Integrity Verifier (/api/verify-ledger-chain).
     */
    public function verifyLedgerChain(): JsonResponse
    {
        $chainStatus = $this->yieldService->verifyLedgerIntegrity();

        return response()->json([
            'status' => 'ok',
            'chain_status' => $chainStatus,
            'kpis' => $this->buildKpiSummary(),
        ]);
    }

    /**
     * Interactive Database Tampering Attack & Restoration Simulator (/api/simulate-tamper).
     */
    public function simulateTamper(Request $request): JsonResponse
    {
        $mode = (string) $request->input('mode', 'corrupt');

        if ($mode === 'restore') {
            $actionResult = $this->yieldService->restoreTamperedLedger();
        } else {
            $targetId = $request->input('ledger_id') ? (int) $request->input('ledger_id') : null;
            $actionResult = $this->yieldService->simulateDatabaseTamperAttack($targetId);
        }

        return response()->json([
            'status' => 'ok',
            'action' => $actionResult,
            'chain_status' => $this->yieldService->verifyLedgerIntegrity(),
            'kpis' => $this->buildKpiSummary(),
        ]);
    }

    /**
     * Compile or refresh an EUDR Export Container (/api/compile-container).
     */
    public function compileContainer(Request $request): JsonResponse
    {
        $buyer = (string) $request->input('buyer_entity', 'Rothfos GmbH (Neumann Gruppe) — Bremen Specialty Terminal');
        $port = (string) $request->input('destination_port', 'Port of Bremerhaven, Germany (DEBRV)');
        $ledgerIds = $request->input('ledger_ids', []);

        if (empty($ledgerIds)) {
            $ledgerIds = HarvestLedger::where('verdict', 'APPROVED')
                ->orderByDesc('id')
                ->limit(8)
                ->pluck('id')
                ->all();
        }

        $container = $this->exportCompiler->compileContainer($ledgerIds, $buyer, $port);

        return response()->json([
            'status' => 'ok',
            'container' => $container,
            'kpis' => $this->buildKpiSummary(),
        ]);
    }

    /**
     * Compute real-time executive KPI metrics across all seeded and live records.
     *
     * @return array<string, mixed>
     */
    private function buildKpiSummary(): array
    {
        $plots = FarmPlot::all();
        $ledgers = HarvestLedger::all();
        $containers = ExportContainer::all();

        $totalAreaHa = round((float) $plots->sum('area_hectares'), 2);
        $totalRawAreaHa = round((float) $plots->sum('area_raw_ha'), 2);
        $totalSpikesRemoved = (int) $plots->sum('spikes_removed');
        $totalPlots = $plots->count();
        $compliantPlots = $plots->where('compliance_status', 'COMPLIANT')->count();
        $deforestationPlots = $plots->where('compliance_status', 'REJECTED_DEFORESTATION')->count();
        $overlapPlots = $plots->where('compliance_status', 'REJECTED_OVERLAP')->count();

        $blockedLedgers = $ledgers->whereIn('verdict', ['BLOCKED_LAUNDERING', 'BLOCKED_NON_COMPLIANT_PLOT']);
        $launderingBlockedKg = round((float) $blockedLedgers->sum('weight_dry_kg'), 2);
        $excessVolumePreventedKg = round((float) $blockedLedgers->sum('excess_laundered_kg'), 2);
        $approvedVolumeKg = round((float) $ledgers->where('verdict', 'APPROVED')->sum('weight_dry_kg'), 2);

        return [
            'total_geodesic_area_ha' => $totalAreaHa,
            'total_raw_distorted_area_ha' => $totalRawAreaHa,
            'total_spikes_removed' => $totalSpikesRemoved,
            'total_plots' => $totalPlots,
            'compliant_plots' => $compliantPlots,
            'deforestation_plots_blocked' => $deforestationPlots,
            'overlap_plots_blocked' => $overlapPlots,
            'blocked_transactions_count' => $blockedLedgers->count(),
            'laundering_batch_volume_blocked_kg' => $launderingBlockedKg,
            'excess_laundered_prevented_kg' => $excessVolumePreventedKg,
            'approved_harvest_volume_kg' => $approvedVolumeKg,
            'export_containers_count' => $containers->count(),
            'mean_audit_latency_ms' => round((float) $plots->avg('audit_latency_ms'), 2),
        ];
    }

    /**
     * Build the 14-vertex raw GPS track with 4 extreme canopy multipath spikes
     * that sanitizes into a 10-vertex (~1.18 ha) compliant polygon in Sumber Jaya.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildCanopySpikeDemoTrack(): array
    {
        $cLng = 104.060200;
        $cLat = -5.015600;
        $w = 0.000535;
        $h = 0.000505;

        // 10-vertex clean perimeter (~1.18 ha) + 4 extreme multipath spikes = 14 raw vertices
        $cleanCorners = [
            ['lng' => $cLng - ($w * 0.80), 'lat' => $cLat - $h],
            ['lng' => $cLng,               'lat' => $cLat - ($h * 1.05)],
            ['lng' => $cLng + ($w * 0.80), 'lat' => $cLat - $h],
            ['lng' => $cLng + $w,          'lat' => $cLat - ($h * 0.50)],
            ['lng' => $cLng + $w,          'lat' => $cLat + ($h * 0.50)],
            ['lng' => $cLng + ($w * 0.80), 'lat' => $cLat + $h],
            ['lng' => $cLng,               'lat' => $cLat + ($h * 1.05)],
            ['lng' => $cLng - ($w * 0.80), 'lat' => $cLat + $h],
            ['lng' => $cLng - $w,          'lat' => $cLat + ($h * 0.50)],
            ['lng' => $cLng - $w,          'lat' => $cLat - ($h * 0.50)],
        ];

        $spikeInjections = [
            1 => ['dlng' => 0.000480,  'dlat' => -0.000520],
            4 => ['dlng' => 0.000560,  'dlat' => 0.000460],
            6 => ['dlng' => -0.000490, 'dlat' => 0.000540],
            8 => ['dlng' => -0.000530, 'dlat' => -0.000450],
        ];

        $track = [];
        $ts = 1760600000;
        foreach ($cleanCorners as $idx => $pt) {
            $track[] = [
                'lat' => round($pt['lat'], 6),
                'lng' => round($pt['lng'], 6),
                'timestamp' => $ts,
                'accuracy_m' => 3.5,
            ];

            if (isset($spikeInjections[$idx])) {
                $sp = $spikeInjections[$idx];
                $track[] = [
                    'lat' => round($pt['lat'] + $sp['dlat'], 6),
                    'lng' => round($pt['lng'] + $sp['dlng'], 6),
                    'timestamp' => $ts + 1,
                    'accuracy_m' => 34.0,
                ];
            }

            $ts += 25;
        }

        return $track;
    }

    /**
     * Build a simulated encroachment polygon cutting into Bukit Barisan Selatan National Park (TNBBS)
     * across the 104.056000 eastern park boundary (~16.4% deforestation overlap).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildTnbbsEncroachmentDemoTrack(): array
    {
        // Spanning lng 104.055803 to 104.057003 -> 0.000197 deg inside TNBBS (<104.056000) out of 0.001200 total width = 16.42% overlap
        $pts = [
            ['lng' => 104.055803, 'lat' => -5.016500],
            ['lng' => 104.057003, 'lat' => -5.016500],
            ['lng' => 104.057003, 'lat' => -5.015500],
            ['lng' => 104.055803, 'lat' => -5.015500],
        ];

        $track = [];
        $ts = 1760605000;
        foreach ($pts as $pt) {
            $track[] = [
                'lat' => $pt['lat'],
                'lng' => $pt['lng'],
                'timestamp' => $ts,
                'accuracy_m' => 4.0,
            ];
            $ts += 40;
        }

        return $track;
    }
}
