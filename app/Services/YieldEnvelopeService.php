<?php

namespace App\Services;

use App\Models\ExportContainer;
use App\Models\FarmPlot;
use App\Models\HarvestLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class YieldEnvelopeService
{
    /**
     * Base dry commodity yield (kg/tree/year) under standard Sumatran smallholder conditions.
     */
    public const BASE_YIELD_KG_PER_TREE = [
        'ROBUSTA_COFFEE' => 1.35,
        'COCOA' => 1.50,
        'BLACK_PEPPER' => 1.80,
    ];

    /**
     * Agronomic Shade-Grown & Intercropping Canopy Factors (kappa_intercrop):
     * - MONOCULTURE: 1.00 (Full-sun unshaded or single-strata stand)
     * - SHADE_AGROFORESTRY: 0.92 (Under Gliricidia sepium / Erythrina leguminous shade canopy)
     * - MULTI_CROP_INTERCROP: 0.85 (Multi-strata intercrop, e.g., Coffee + Black Pepper / Cocoa)
     */
    public const CROPPING_SYSTEM_FACTORS = [
        'MONOCULTURE' => 1.00,
        'SHADE_AGROFORESTRY' => 0.92,
        'MULTI_CROP_INTERCROP' => 0.85,
    ];

    /**
     * Maximum export-grade dry bean moisture percentage per SNI 01-2907-2008 / EUDR quality rules.
     */
    public const MAX_EXPORT_MOISTURE_PCT = 13.5;

    /**
     * Genesis 64-zero SHA-256 hash anchor.
     */
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Calculate the deterministic biological annual dry yield ceiling (Y_max in kg/year):
     * Y_max = A_ha * D_trees/ha * y_base(c) * phi_age(t) * psi_elev(h) * kappa_intercrop(s)
     *
     * @return array<string, mixed>
     */
    public function calculateBiologicalCeiling(
        string $commodity,
        float $areaHa,
        int $densityPerHa,
        int $treeAgeYears,
        int $elevationM,
        string $croppingSystem = 'MONOCULTURE'
    ): array {
        $normalizedCommodity = strtoupper(trim($commodity));
        $baseYield = self::BASE_YIELD_KG_PER_TREE[$normalizedCommodity] ?? 1.35;

        $normalizedCropping = strtoupper(trim($croppingSystem));
        if (! isset(self::CROPPING_SYSTEM_FACTORS[$normalizedCropping])) {
            $normalizedCropping = 'MONOCULTURE';
        }
        $intercropFactor = self::CROPPING_SYSTEM_FACTORS[$normalizedCropping];

        $croppingLabel = match ($normalizedCropping) {
            'SHADE_AGROFORESTRY' => 'Leguminous Shade Agroforestry (κ=0.92)',
            'MULTI_CROP_INTERCROP' => 'Multi-Crop Intercrop System (κ=0.85)',
            default => 'Standard Single-Strata Stand (κ=1.00)',
        };

        // Physiological Tree Age Curve phi_age(t)
        if ($treeAgeYears < 3) {
            $ageFactor = 0.15;
            $agePhaseLabel = 'Juvenile Vegetative (<3 yrs)';
        } elseif ($treeAgeYears <= 4) {
            $ageFactor = 0.65;
            $agePhaseLabel = 'Early Bearing (3-4 yrs)';
        } elseif ($treeAgeYears <= 15) {
            $ageFactor = 1.00;
            $agePhaseLabel = 'Peak Reproductive Maturity (5-15 yrs)';
        } else {
            $ageFactor = 0.78;
            $agePhaseLabel = 'Senescent Woody Decline (>15 yrs)';
        }

        // Elevation Microclimate Factor psi_elev(h)
        if ($elevationM >= 400 && $elevationM <= 900) {
            $elevationFactor = 1.05;
            $elevationBandLabel = 'Optimal Volcanic Slope (400-900m ASL)';
        } elseif ($elevationM < 400) {
            $elevationFactor = 0.85;
            $elevationBandLabel = 'Lowland Thermal Stress (<400m ASL)';
        } else {
            $elevationFactor = 0.92;
            $elevationBandLabel = 'Highland Cool Maturation (>900m ASL)';
        }

        $annualCeilingKg = round(
            $areaHa * $densityPerHa * $baseYield * $ageFactor * $elevationFactor * $intercropFactor,
            2
        );
        $totalTrees = (int) round($areaHa * $densityPerHa);

        $formulaString = sprintf(
            'Y_max = %.4f ha × %d trees/ha × %.2f kg/tree × %.2f (φ_age) × %.2f (ψ_elev) × %.2f (κ_crop) = %.2f kg/yr',
            $areaHa,
            $densityPerHa,
            $baseYield,
            $ageFactor,
            $elevationFactor,
            $intercropFactor,
            $annualCeilingKg
        );

        return [
            'commodity' => $normalizedCommodity,
            'cropping_system' => $normalizedCropping,
            'cropping_system_label' => $croppingLabel,
            'intercrop_factor_kappa' => $intercropFactor,
            'area_ha' => round($areaHa, 4),
            'tree_density_per_ha' => $densityPerHa,
            'total_trees_estimated' => $totalTrees,
            'tree_age_years' => $treeAgeYears,
            'elevation_m' => $elevationM,
            'base_yield_kg_per_tree' => $baseYield,
            'age_factor_phi' => $ageFactor,
            'age_phase_label' => $agePhaseLabel,
            'elevation_factor_psi' => $elevationFactor,
            'elevation_band_label' => $elevationBandLabel,
            'annual_yield_ceiling_kg' => $annualCeilingKg,
            'formula_expression' => $formulaString,
        ];
    }

    /**
     * Process a harvest weigh-in against the plot's spatial compliance status and remaining biological quota,
     * appending a SHA-256 hash-chained record to the immutable HarvestLedger.
     */
    public function processWeighIn(
        FarmPlot $plot,
        float $weightDryKg,
        float $moisturePct,
        bool $capturedOffline = false,
        ?string $customTimestamp = null,
        ?string $customDate = null,
        ?string $clientTxId = null
    ): HarvestLedger {
        $weightDryKg = round($weightDryKg, 2);
        $moisturePct = round($moisturePct, 1);

        if ($weightDryKg <= 0.0) {
            throw new \InvalidArgumentException('Harvest weigh-in dry weight must be strictly positive (> 0.00 kg).');
        }

        $quotaBefore = round((float) $plot->remaining_quota_kg, 2);
        $quotaAfter = $quotaBefore;
        $excessLaunderedKg = 0.0;

        if ($plot->compliance_status !== 'COMPLIANT') {
            $verdict = 'BLOCKED_NON_COMPLIANT_PLOT';
            $excessLaunderedKg = $weightDryKg;
            $explanation = sprintf(
                'SPATIAL LOCK: Plot %s carries status [%s] (Forest Overlap: %.2f%%, Neighbor Overlap: %.2f%%). Zero harvest volume from spatially non-compliant polygons may enter EUDR export containers.',
                $plot->plot_code,
                $plot->compliance_status,
                $plot->forest_overlap_pct,
                $plot->neighbor_overlap_pct
            );
        } elseif ($weightDryKg > $quotaBefore) {
            $verdict = 'BLOCKED_LAUNDERING';
            $excessLaunderedKg = round($weightDryKg - $quotaBefore, 2);
            $explanation = sprintf(
                'BIOLOGICAL YIELD-CEILING BREACH: Attempted delivery of %.2f kg exceeds remaining biological quota (%.2f kg of %.2f kg/yr ceiling) by +%.2f kg. Suspected Polygon Laundering of illicit encroachment harvest. Batch rejected; plot quota locked at %.2f kg.',
                $weightDryKg,
                $quotaBefore,
                $plot->annual_yield_ceiling_kg,
                $excessLaunderedKg,
                $quotaBefore
            );
        } else {
            $verdict = 'APPROVED';
            $quotaAfter = round($quotaBefore - $weightDryKg, 2);
            $plot->harvested_ytd_kg = round((float) $plot->harvested_ytd_kg + $weightDryKg, 2);
            $plot->remaining_quota_kg = $quotaAfter;
            $plot->save();

            $explanation = sprintf(
                'MASS-BALANCE VERIFIED: %.2f kg dry weight (%.1f%% moisture) within biological yield envelope. Remaining annual quota deducted from %.2f kg to %.2f kg.',
                $weightDryKg,
                $moisturePct,
                $quotaBefore,
                $quotaAfter
            );
        }

        $lastLedger = HarvestLedger::query()->orderByDesc('id')->first();
        $prevHash = $lastLedger ? $lastLedger->record_hash : self::GENESIS_HASH;

        $timestamp = $customTimestamp ?? now()->toIso8601String();
        $harvestDate = $customDate ?? now()->toDateString();

        $hashPayload = implode('|', [
            $prevHash,
            $plot->plot_code,
            number_format($weightDryKg, 2, '.', ''),
            $verdict,
            $timestamp,
        ]);
        $recordHash = hash('sha256', $hashPayload);

        $seq = HarvestLedger::count() + 1;
        $txCode = sprintf('TX-LP-%04d-%s', $seq, strtoupper(substr($recordHash, 0, 4)));

        return HarvestLedger::create([
            'transaction_code' => $txCode,
            'client_tx_id' => $clientTxId,
            'farm_plot_id' => $plot->id,
            'harvest_date' => $harvestDate,
            'weight_dry_kg' => $weightDryKg,
            'moisture_pct' => $moisturePct,
            'quota_before_kg' => $quotaBefore,
            'quota_after_kg' => $quotaAfter,
            'excess_laundered_kg' => $excessLaunderedKg,
            'verdict' => $verdict,
            'audit_explanation' => $explanation,
            'hashed_timestamp' => $timestamp,
            'previous_hash' => $prevHash,
            'record_hash' => $recordHash,
            'captured_offline' => $capturedOffline,
        ]);
    }

    /**
     * Recompute every SHA-256 payload hash from Genesis (0000...0000) and verify both
     * pointer linkage (previous_hash) and canonical payload preimage integrity (record_hash).
     *
     * @return array<string, mixed>
     */
    public function verifyLedgerIntegrity(): array
    {
        $t0 = hrtime(true);
        $records = HarvestLedger::with('farmPlot')->orderBy('id')->get();
        $expectedPrev = self::GENESIS_HASH;
        $brokenLinks = 0;
        $tamperedPayloads = 0;
        $corruptedBlocks = [];

        foreach ($records as $rec) {
            $plotCode = $rec->farmPlot ? $rec->farmPlot->plot_code : 'UNKNOWN';
            $ts = $rec->hashed_timestamp ?: ($rec->created_at ? $rec->created_at->toIso8601String() : '');

            $recomputedPayload = implode('|', [
                $rec->previous_hash,
                $plotCode,
                number_format((float) $rec->weight_dry_kg, 2, '.', ''),
                $rec->verdict,
                $ts,
            ]);
            $recomputedHash = hash('sha256', $recomputedPayload);

            $linkOk = ($rec->previous_hash === $expectedPrev);
            $payloadOk = ($recomputedHash === $rec->record_hash);

            if (! $linkOk) {
                $brokenLinks++;
            }
            if (! $payloadOk) {
                $tamperedPayloads++;
            }

            if (! $linkOk || ! $payloadOk) {
                $expectedWeight = $rec->verdict === 'APPROVED'
                    ? round((float) $rec->quota_before_kg - (float) $rec->quota_after_kg, 2)
                    : (float) $rec->weight_dry_kg;

                $corruptedBlocks[] = [
                    'id' => $rec->id,
                    'transaction_code' => $rec->transaction_code,
                    'plot_code' => $plotCode,
                    'stored_weight_kg' => round((float) $rec->weight_dry_kg, 2),
                    'expected_weight_kg' => $expectedWeight,
                    'stored_hash' => $rec->record_hash,
                    'recomputed_hash' => $recomputedHash,
                    'previous_hash_ok' => $linkOk,
                    'payload_hash_ok' => $payloadOk,
                    'failure_reason' => ! $payloadOk
                        ? sprintf(
                            'SHA-256 PAYLOAD TAMPER DETECTED: Stored weight (%.2f kg) differs from signed preimage (%.2f kg)',
                            $rec->weight_dry_kg,
                            $expectedWeight
                        )
                        : 'SHA-256 CHAIN POINTER BROKEN: previous_hash does not match preceding block hash',
                ];
            }

            // Advance expected previous hash using the recomputed hash so downstream tampering cascades
            $expectedPrev = $recomputedHash;
        }

        $totalFaults = $brokenLinks + $tamperedPayloads;
        $elapsedMs = round((hrtime(true) - $t0) / 1e6, 2);

        return [
            'total_records' => $records->count(),
            'broken_links' => $totalFaults,
            'pointer_mismatches' => $brokenLinks,
            'tampered_payloads' => $tamperedPayloads,
            'chain_intact' => $totalFaults === 0,
            'head_record_hash' => $expectedPrev,
            'genesis_anchor' => self::GENESIS_HASH,
            'corrupted_blocks' => $corruptedBlocks,
            'containers_count' => ExportContainer::count(),
            'verification_latency_ms' => max(0.12, $elapsedMs),
            'verified_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Simulate a covert SQL database tampering attack (+0.01 kg modification on an existing APPROVED block)
     * to demonstrate real-time SHA-256 preimage mismatch detection.
     *
     * @return array<string, mixed>
     */
    public function simulateDatabaseTamperAttack(): array
    {
        $target = HarvestLedger::with('farmPlot')
            ->where('verdict', 'APPROVED')
            ->orderBy('id')
            ->skip(2)
            ->first() ?? HarvestLedger::with('farmPlot')->where('verdict', 'APPROVED')->first();

        if (! $target) {
            return $this->verifyLedgerIntegrity();
        }

        $expectedWeight = round((float) $target->quota_before_kg - (float) $target->quota_after_kg, 2);
        $tamperedWeight = round($expectedWeight + 0.01, 2);

        DB::table('harvest_ledgers')
            ->where('id', $target->id)
            ->update(['weight_dry_kg' => $tamperedWeight]);

        return $this->verifyLedgerIntegrity();
    }

    /**
     * Restore all tampered APPROVED blocks back to their cryptographically signed quota delta.
     *
     * @return array<string, mixed>
     */
    public function restoreTamperedLedger(): array
    {
        $approvedRecords = HarvestLedger::where('verdict', 'APPROVED')->get();
        foreach ($approvedRecords as $rec) {
            $signedWeight = round((float) $rec->quota_before_kg - (float) $rec->quota_after_kg, 2);
            if (abs((float) $rec->weight_dry_kg - $signedWeight) > 0.001) {
                DB::table('harvest_ledgers')
                    ->where('id', $rec->id)
                    ->update(['weight_dry_kg' => $signedWeight]);
            }
        }

        return $this->verifyLedgerIntegrity();
    }
}
