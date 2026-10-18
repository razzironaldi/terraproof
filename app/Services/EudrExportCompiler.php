<?php

namespace App\Services;

use App\Models\ExportContainer;
use App\Models\HarvestLedger;

class EudrExportCompiler
{
    /**
     * Compile an EUDR-compliant Export Container from approved HarvestLedger IDs,
     * computing the binary SHA-256 Merkle Root Hash and generating a strict RFC 7946 GeoJSON FeatureCollection
     * ready for European Commission Traces-NT submission.
     *
     * @param  array<int, int>  $ledgerIds
     */
    public function compileContainer(
        array $ledgerIds,
        string $buyer,
        string $destinationPort,
        ?string $containerCode = null
    ): ExportContainer {
        $ledgers = HarvestLedger::with(['farmPlot.farmer'])
            ->whereIn('id', $ledgerIds)
            ->where('verdict', 'APPROVED')
            ->orderBy('id')
            ->get();

        if ($ledgers->isEmpty()) {
            throw new \InvalidArgumentException('Cannot compile an EUDR Export Container without APPROVED harvest ledger transactions.');
        }

        $leafHashes = [];
        $totalNetWeightKg = 0.0;
        $uniquePlots = [];
        $uniqueFarmers = [];
        $ledgerManifest = [];
        $commodityWeights = [];

        foreach ($ledgers as $ledger) {
            $plot = $ledger->farmPlot;
            if (! $plot || $plot->compliance_status !== 'COMPLIANT') {
                continue;
            }

            $leafHashes[] = $ledger->record_hash;
            $weight = (float) $ledger->weight_dry_kg;
            $totalNetWeightKg += $weight;
            $uniquePlots[$plot->id] = $plot;
            $commodityWeights[$plot->commodity] = ($commodityWeights[$plot->commodity] ?? 0.0) + $weight;

            if ($plot->farmer) {
                $uniqueFarmers[$plot->farmer->id] = $plot->farmer;
            }

            $ledgerManifest[] = [
                'transaction_code' => $ledger->transaction_code,
                'harvest_date' => $ledger->harvest_date ? $ledger->harvest_date->format('Y-m-d') : now()->toDateString(),
                'plot_code' => $plot->plot_code,
                'farmer_code' => $plot->farmer ? $plot->farmer->farmer_code : 'UNKNOWN',
                'weight_dry_kg' => round((float) $ledger->weight_dry_kg, 2),
                'moisture_pct' => round((float) $ledger->moisture_pct, 1),
                'record_hash' => $ledger->record_hash,
                'captured_offline' => (bool) $ledger->captured_offline,
            ];
        }

        arsort($commodityWeights);
        $primaryCommodity = array_key_first($commodityWeights) ?? 'ROBUSTA_COFFEE';

        $merkleTree = $this->buildBinaryMerkleTree($leafHashes);
        $merkleRootHash = $merkleTree['root'];

        // Build strict RFC 7946 GeoJSON FeatureCollection for EU Traces-NT
        $features = [];
        $minLng = INF;
        $minLat = INF;
        $maxLng = -INF;
        $maxLat = -INF;

        foreach ($uniquePlots as $plot) {
            $farmer = $plot->farmer;
            $ring = $plot->clean_polygon_ring;

            $formattedRing = [];
            foreach ($ring as $coord) {
                $lng = (float) number_format((float) $coord[0], 6, '.', '');
                $lat = (float) number_format((float) $coord[1], 6, '.', '');
                $formattedRing[] = [$lng, $lat];

                $minLng = min($minLng, $lng);
                $maxLng = max($maxLng, $lng);
                $minLat = min($minLat, $lat);
                $maxLat = max($maxLat, $lat);
            }

            $features[] = [
                'type' => 'Feature',
                'id' => $plot->plot_code,
                'properties' => [
                    'plot_code' => $plot->plot_code,
                    'plot_name' => $plot->plot_name,
                    'commodity' => $plot->commodity,
                    'hs_code' => $this->resolveHsCode($plot->commodity),
                    'country_of_production' => 'ID',
                    'region' => 'West Lampung & Tanggamus, Sumatra, Indonesia',
                    'farmer_code' => $farmer ? $farmer->farmer_code : 'N/A',
                    'farmer_national_id_sha256' => $farmer ? $farmer->national_id_sha256 : hash('sha256', $plot->plot_code),
                    'cooperative_name' => $farmer ? $farmer->cooperative_name : 'Koperasi Produsen Kopi Lestari Lampung',
                    'geodesic_area_ha' => round((float) $plot->area_hectares, 4),
                    'elevation_m' => (int) $plot->elevation_m,
                    'tree_density_per_ha' => (int) $plot->tree_density_per_ha,
                    'annual_yield_ceiling_kg' => round((float) $plot->annual_yield_ceiling_kg, 2),
                    'harvested_ytd_kg' => round((float) $plot->harvested_ytd_kg, 2),
                    'forest_overlap_pct' => 0.0,
                    'eudr_cutoff_baseline' => '2020-12-31',
                    'compliance_status' => 'COMPLIANT',
                    'deforestation_free_verified' => true,
                    'last_audited_at' => $plot->last_audited_at ? $plot->last_audited_at->toIso8601String() : now()->toIso8601String(),
                ],
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [$formattedRing],
                ],
            ];
        }

        $bbox = is_finite($minLng)
            ? [
                round($minLng, 6),
                round($minLat, 6),
                round($maxLng, 6),
                round($maxLat, 6),
            ]
            : [104.040000, -5.045000, 104.110000, -4.995000];

        $code = $containerCode ?? sprintf('EUDR-DDS-2026-%03d', ExportContainer::count() + 1);
        $compiledAt = now();

        $featureCollection = [
            'type' => 'FeatureCollection',
            'name' => $code.'_TRACES_NT_GEODATA',
            'bbox' => $bbox,
            'metadata' => [
                'standard' => 'RFC 7946 GeoJSON / EU Regulation 2023/1115 (EUDR Article 9)',
                'coordinate_reference_system' => 'urn:ogc:def:crs:OGC:1.3:CRS84 (WGS84 6-Decimal Precision)',
                'container_code' => $code,
                'buyer_entity' => $buyer,
                'destination_port' => $destinationPort,
                'merkle_root_hash' => $merkleRootHash,
                'compiled_at' => $compiledAt->toIso8601String(),
                'engine' => 'TerraProof Geodesic & Biological Mass-Balance Engine v2.6',
            ],
            'features' => $features,
        ];

        $geojsonString = json_encode($featureCollection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ddsPassportPayload = [
            'dds_reference_number' => 'EU-TRACES-NT-'.$code,
            'regulation' => 'Regulation (EU) 2023/1115 on Deforestation-Free Products (EUDR)',
            'cutoff_date_verified' => '2020-12-31',
            'exporter_cooperative' => 'Koperasi Produsen Kopi Lestari Lampung (West Lampung, Indonesia)',
            'buyer_entity' => $buyer,
            'destination_port' => $destinationPort,
            'commodity' => $primaryCommodity,
            'hs_code' => $this->resolveHsCode($primaryCommodity),
            'total_net_weight_kg' => round($totalNetWeightKg, 2),
            'total_plots_count' => count($uniquePlots),
            'total_farmers_count' => count($uniqueFarmers),
            'merkle_root_hash' => $merkleRootHash,
            'merkle_tree_levels' => $merkleTree['levels'],
            'constituent_transactions' => $ledgerManifest,
            'verification_url' => url('/containers/'.$code.'/passport'),
        ];

        return ExportContainer::updateOrCreate(
            ['container_code' => $code],
            [
                'buyer_entity' => $buyer,
                'destination_port' => $destinationPort,
                'commodity' => $primaryCommodity,
                'total_net_weight_kg' => round($totalNetWeightKg, 2),
                'total_plots_count' => count($uniquePlots),
                'total_farmers_count' => count($uniqueFarmers),
                'merkle_root_hash' => $merkleRootHash,
                'rfc7946_geojson' => $geojsonString,
                'dds_passport_payload' => $ddsPassportPayload,
                'compiled_at' => $compiledAt,
            ]
        );
    }

    /**
     * Compute a binary SHA-256 Merkle Tree over an ordered list of leaf hashes.
     *
     * @param  array<int, string>  $leafHashes
     * @return array{root: string, levels: array<int, array<int, string>>}
     */
    public function buildBinaryMerkleTree(array $leafHashes): array
    {
        if (empty($leafHashes)) {
            $emptyRoot = hash('sha256', 'TERRAPROOF_EMPTY_MERKLE_TREE');

            return [
                'root' => $emptyRoot,
                'levels' => [[$emptyRoot]],
            ];
        }

        $currentLevel = array_values($leafHashes);
        $levels = [$currentLevel];

        while (count($currentLevel) > 1) {
            if (count($currentLevel) % 2 !== 0) {
                $currentLevel[] = $currentLevel[count($currentLevel) - 1];
            }

            $nextLevel = [];
            for ($i = 0; $i < count($currentLevel); $i += 2) {
                $nextLevel[] = hash('sha256', $currentLevel[$i].$currentLevel[$i + 1]);
            }

            $levels[] = $nextLevel;
            $currentLevel = $nextLevel;
        }

        return [
            'root' => $currentLevel[0],
            'levels' => $levels,
        ];
    }

    /**
     * Resolve Harmonized System (HS) Customs Code per EUDR Annex I.
     */
    private function resolveHsCode(string $commodity): string
    {
        return match (strtoupper($commodity)) {
            'ROBUSTA_COFFEE' => '0901.11.00 (Coffee, not roasted, not decaffeinated)',
            'COCOA' => '1801.00.00 (Cocoa beans, whole or broken, raw or roasted)',
            'BLACK_PEPPER' => '0904.11.00 (Piper / Black Pepper, neither crushed nor ground)',
            default => '0901.11.00',
        };
    }
}
