<?php

namespace App\Services;

use App\Models\FarmPlot;
use App\Models\ProtectedZone;
use Illuminate\Support\Facades\DB;

class GeodesicAuditService
{
    /**
     * WGS84 semi-major equatorial radius in meters.
     */
    public const WGS84_RADIUS_M = 6378137.0;

    /**
     * Maximum biologically plausible human walking speed (m/s) on steep agro-forestry terrain.
     */
    public const MAX_WALK_SPEED_MPS = 3.5;

    /**
     * Maximum walking pace (m/s) around a genuine sharp terrace switchback corner (v <= 1.8 m/s with tight HDOP).
     */
    public const SLOW_CORNER_TURN_MAX_SPEED_MPS = 1.8;

    /**
     * Minimum acute interior deflection angle (degrees) before triggering spike rejection on >12m jumps.
     */
    public const MIN_DEFLECTION_ANGLE_DEG = 18.0;

    /**
     * Minimum jump distance (meters) paired with acute angle to classify as L1 canopy multipath reflection.
     */
    public const MIN_SPIKE_JUMP_M = 12.0;

    /**
     * Sanitize a raw field GPS track by stripping tropical canopy multipath spikes,
     * rounding surviving vertices to 6 decimal places (EU Traces-NT spec),
     * and enforcing a closed RFC 7946 polygon ring.
     *
     * @param  array<int, array<string, mixed>>  $rawTrack
     * @return array<string, mixed>
     */
    public function sanitizeGpsTrack(array $rawTrack): array
    {
        $normalized = [];
        foreach ($rawTrack as $idx => $pt) {
            if (isset($pt['lat'], $pt['lng'])) {
                $lat = (float) $pt['lat'];
                $lng = (float) $pt['lng'];
                $ts = isset($pt['timestamp']) ? (int) $pt['timestamp'] : (1760000000 + ($idx * 8));
                $acc = isset($pt['accuracy_m']) ? (float) $pt['accuracy_m'] : 4.5;
            } elseif (isset($pt[0], $pt[1])) {
                $lng = (float) $pt[0];
                $lat = (float) $pt[1];
                $ts = 1760000000 + ($idx * 8);
                $acc = 4.5;
            } else {
                continue;
            }

            $normalized[] = [
                'lat' => $lat,
                'lng' => $lng,
                'timestamp' => $ts,
                'accuracy_m' => $acc,
                'index' => $idx,
            ];
        }

        // Remove duplicate closing point if caller passed an already closed track
        $countNorm = count($normalized);
        if ($countNorm > 3) {
            $first = $normalized[0];
            $last = $normalized[$countNorm - 1];
            if (abs($first['lat'] - $last['lat']) < 1e-7 && abs($first['lng'] - $last['lng']) < 1e-7) {
                array_pop($normalized);
                $countNorm--;
            }
        }

        // Build raw closed ring for raw distorted area calculation
        $rawRing = [];
        foreach ($normalized as $pt) {
            $rawRing[] = [round($pt['lng'], 6), round($pt['lat'], 6)];
        }
        $rawRing = $this->ensureClosedRing($rawRing);
        $rawVertexCount = count($normalized);
        $areaRawHa = $this->computeGeodesicAreaHa($rawRing);

        // Iterative multipath spike stripping
        $surviving = $normalized;
        $spikeTelemetry = [];
        $pass = 0;

        while ($pass < 3 && count($surviving) > 3) {
            $pass++;
            $nextSurviving = [];
            $removedInPass = false;
            $n = count($surviving);

            for ($i = 0; $i < $n; $i++) {
                $prev = count($nextSurviving) > 0
                    ? $nextSurviving[count($nextSurviving) - 1]
                    : $surviving[($i - 1 + $n) % $n];
                $curr = $surviving[$i];
                $next = $surviving[($i + 1) % $n];

                $distM = $this->haversineDistanceM(
                    $prev['lat'],
                    $prev['lng'],
                    $curr['lat'],
                    $curr['lng']
                );
                $deltaT = ($i > 0) ? max(1, abs($curr['timestamp'] - $prev['timestamp'])) : 45;
                $speedMps = $distM / $deltaT;
                $deflectionDeg = $this->computeInteriorDeflectionAngleDeg($prev, $curr, $next);

                $isSpeedSpike = ($i > 0) && ($speedMps > self::MAX_WALK_SPEED_MPS);
                $isSlowLegitimateCorner = ($speedMps <= self::SLOW_CORNER_TURN_MAX_SPEED_MPS)
                    && (($curr['accuracy_m'] ?? 4.5) <= 8.0);
                $isAngleSpike = ($deflectionDeg < self::MIN_DEFLECTION_ANGLE_DEG)
                    && ($distM > self::MIN_SPIKE_JUMP_M)
                    && (! $isSlowLegitimateCorner);

                if (($isSpeedSpike || $isAngleSpike) && (count($surviving) - count($spikeTelemetry) > 3)) {
                    $removedInPass = true;
                    $spikeTelemetry[] = [
                        'vertex_index' => $curr['index'],
                        'lat' => round($curr['lat'], 6),
                        'lng' => round($curr['lng'], 6),
                        'distance_jump_m' => round($distM, 2),
                        'apparent_speed_mps' => round($speedMps, 2),
                        'deflection_angle_deg' => round($deflectionDeg, 2),
                        'rejection_reason' => $isSpeedSpike && $isAngleSpike
                            ? 'CANOPY_MULTIPATH_VELOCITY_AND_ACUTE_DEFLECTION'
                            : ($isSpeedSpike ? 'IMPOSSIBLE_TERRAIN_VELOCITY_SPIKE' : 'ACUTE_CANOPY_REFLECTION_NEEDLE'),
                    ];
                } else {
                    $nextSurviving[] = $curr;
                }
            }

            $surviving = $nextSurviving;
            if (! $removedInPass) {
                break;
            }
        }

        // Round surviving vertices to 6 decimal places per EU Traces-NT technical specification
        $cleanRing = [];
        foreach ($surviving as $pt) {
            $cleanRing[] = [
                round((float) $pt['lng'], 6),
                round((float) $pt['lat'], 6),
            ];
        }

        $cleanRing = $this->ensureCounterClockwiseAndClosed($cleanRing);
        $cleanAreaHa = $this->computeGeodesicAreaHa($cleanRing);
        $cleanVertexCount = max(0, count($cleanRing) - 1);
        $spikesRemoved = count($spikeTelemetry);

        $distortionPct = $cleanAreaHa > 0
            ? round((abs($areaRawHa - $cleanAreaHa) / $cleanAreaHa) * 100.0, 2)
            : 0.0;

        return [
            'raw_polygon_ring' => $rawRing,
            'clean_polygon_ring' => $cleanRing,
            'wkt_polygon' => $this->ringToWkt($cleanRing),
            'raw_vertex_count' => $rawVertexCount,
            'clean_vertex_count' => $cleanVertexCount,
            'spikes_removed' => $spikesRemoved,
            'spike_telemetry' => $spikeTelemetry,
            'area_raw_ha' => round($areaRawHa, 4),
            'area_hectares' => round($cleanAreaHa, 4),
            'area_distortion_error_pct' => $distortionPct,
        ];
    }

    /**
     * Compute exact WGS84 Spherical Excess / Chamberlain-Duquette Geodesic Polygon Area in hectares (ha).
     *
     * Formula:
     * A = (R^2 / 2) * | sum_{i=0}^{n-1} rad(lng_{i+1} - lng_i) * (sin(rad(lat_{i+1})) + sin(rad(lat_i))) | * 10^-4
     *
     * @param  array<int, array<int, float>>  $ring  Array of [lng, lat] pairs
     */
    public function computeGeodesicAreaHa(array $ring): float
    {
        $closed = $this->ensureClosedRing($ring);
        $n = count($closed) - 1;
        if ($n < 3) {
            return 0.0;
        }

        $accumulator = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $lng1 = (float) $closed[$i][0];
            $lat1 = (float) $closed[$i][1];
            $lng2 = (float) $closed[$i + 1][0];
            $lat2 = (float) $closed[$i + 1][1];

            $deltaLngRad = deg2rad($lng2 - $lng1);
            $sinLatSum = sin(deg2rad($lat2)) + sin(deg2rad($lat1));
            $accumulator += $deltaLngRad * $sinLatSum;
        }

        $areaSquareMeters = (pow(self::WGS84_RADIUS_M, 2) / 2.0) * abs($accumulator);

        return round($areaSquareMeters * 1e-4, 4);
    }

    /**
     * Dual-Engine verification helper: computes geodesic area in pure PHP WGS84 Spherical Excess
     * and, if connected to MySQL 8+, cross-verifies via ST_Area(ST_GeomFromText(wkt, 4326)).
     *
     * @param  array<int, array<int, float>>  $ring
     * @return array<string, mixed>
     */
    public function computeDualEngineAreaHa(array $ring): array
    {
        $phpGeodesicHa = $this->computeGeodesicAreaHa($ring);
        $wkt = $this->ringToWkt($ring);
        $driver = DB::connection()->getDriverName();
        $sqlEngineHa = $phpGeodesicHa;

        if ($driver === 'mysql') {
            try {
                $row = DB::selectOne(
                    'SELECT ST_Area(ST_GeomFromText(?, 4326, "axis-order=long-lat")) * 0.0001 AS area_ha',
                    [$wkt]
                );
                if ($row && isset($row->area_ha)) {
                    $sqlEngineHa = round((float) $row->area_ha, 4);
                }
            } catch (\Throwable) {
                $sqlEngineHa = $phpGeodesicHa;
            }
        }

        return [
            'php_wgs84_spherical_excess_ha' => $phpGeodesicHa,
            'sql_spatial_engine_ha' => $sqlEngineHa,
            'active_db_driver' => $driver,
            'delta_pct' => $phpGeodesicHa > 0
                ? round((abs($phpGeodesicHa - $sqlEngineHa) / $phpGeodesicHa) * 100.0, 4)
                : 0.0,
        ];
    }

    /**
     * Audit a subject polygon ring against all ProtectedZones (National Parks & Protected Forests)
     * and neighboring FarmPlots using local tangent plane projection and Sutherland-Hodgman polygon clipping.
     *
     * @param  array<int, array<int, float>>  $subjectRing  Array of [lng, lat]
     * @return array<string, mixed>
     */
    public function auditSpatialConflicts(array $subjectRing, ?int $excludePlotId = null): array
    {
        $t0 = hrtime(true);

        $cleanSubject = $this->ensureCounterClockwiseAndClosed($subjectRing);
        $subjectAreaHa = $this->computeGeodesicAreaHa($cleanSubject);

        if ($subjectAreaHa <= 0.0) {
            return [
                'subject_area_ha' => 0.0,
                'forest_overlap_ha' => 0.0,
                'forest_overlap_pct' => 0.0,
                'neighbor_overlap_ha' => 0.0,
                'neighbor_overlap_pct' => 0.0,
                'conflict_intersection_ring' => null,
                'conflict_area_m2' => 0.0,
                'conflicting_entity_code' => null,
                'conflicting_entity_name' => null,
                'compliance_status' => 'COMPLIANT',
                'audit_latency_ms' => 0.10,
            ];
        }

        $centroid = $this->computeCentroid($cleanSubject);
        $subjectXY = $this->projectRingToLocalMeters($cleanSubject, $centroid['lng'], $centroid['lat']);
        $subjectAreaM2 = $subjectAreaHa * 10000.0;

        $maxForestOverlapPct = 0.0;
        $maxForestOverlapHa = 0.0;
        $forestConflictRing = null;
        $forestConflictCode = null;
        $forestConflictName = null;

        // 1. Clip against Protected Zones (TNBBS & Register 39)
        $protectedZones = ProtectedZone::all();
        foreach ($protectedZones as $zone) {
            $zoneRing = $zone->polygon_ring;
            if (! is_array($zoneRing) || count($zoneRing) < 4) {
                continue;
            }
            if (! $this->boundingBoxesIntersect($cleanSubject, $zoneRing)) {
                continue;
            }

            $clipXY = $this->projectRingToLocalMeters($zoneRing, $centroid['lng'], $centroid['lat']);
            $intersectionXY = $this->sutherlandHodgmanClip($subjectXY, $clipXY);

            if (count($intersectionXY) >= 3) {
                $intersectAreaM2 = $this->computePlanarAreaM2($intersectionXY);
                if ($intersectAreaM2 > 0.5) {
                    $overlapHa = round($intersectAreaM2 * 1e-4, 4);
                    $overlapPct = round(($intersectAreaM2 / $subjectAreaM2) * 100.0, 2);

                    if ($overlapPct > $maxForestOverlapPct) {
                        $maxForestOverlapPct = $overlapPct;
                        $maxForestOverlapHa = $overlapHa;
                        $forestConflictRing = $this->unprojectMetersToRing($intersectionXY, $centroid['lng'], $centroid['lat']);
                        $forestConflictCode = $zone->zone_code;
                        $forestConflictName = $zone->name;
                    }
                }
            }
        }

        // 2. Clip against Neighboring Farm Plots
        $maxNeighborOverlapPct = 0.0;
        $maxNeighborOverlapHa = 0.0;
        $neighborConflictRing = null;
        $neighborConflictCode = null;
        $neighborConflictName = null;

        $neighborQuery = FarmPlot::query();
        if ($excludePlotId !== null) {
            $neighborQuery->where('id', '!=', $excludePlotId);
        }
        $neighbors = $neighborQuery->get(['id', 'plot_code', 'plot_name', 'clean_polygon_ring']);

        foreach ($neighbors as $neighbor) {
            $nRing = $neighbor->clean_polygon_ring;
            if (! is_array($nRing) || count($nRing) < 4) {
                continue;
            }
            if (! $this->boundingBoxesIntersect($cleanSubject, $nRing)) {
                continue;
            }

            $clipXY = $this->projectRingToLocalMeters($nRing, $centroid['lng'], $centroid['lat']);
            $intersectionXY = $this->sutherlandHodgmanClip($subjectXY, $clipXY);

            if (count($intersectionXY) >= 3) {
                $intersectAreaM2 = $this->computePlanarAreaM2($intersectionXY);
                if ($intersectAreaM2 > 0.5) {
                    $overlapHa = round($intersectAreaM2 * 1e-4, 4);
                    $overlapPct = round(($intersectAreaM2 / $subjectAreaM2) * 100.0, 2);

                    if ($overlapPct > $maxNeighborOverlapPct) {
                        $maxNeighborOverlapPct = $overlapPct;
                        $maxNeighborOverlapHa = $overlapHa;
                        $neighborConflictRing = $this->unprojectMetersToRing($intersectionXY, $centroid['lng'], $centroid['lat']);
                        $neighborConflictCode = $neighbor->plot_code;
                        $neighborConflictName = $neighbor->plot_name;
                    }
                }
            }
        }

        // 3. Deterministic EUDR Compliance Status Verdict
        $complianceStatus = 'COMPLIANT';
        $primaryConflictRing = null;
        $conflictingCode = null;
        $conflictingName = null;
        $conflictAreaM2 = 0.0;

        if ($maxForestOverlapPct > 1.0) {
            $complianceStatus = 'REJECTED_DEFORESTATION';
            $primaryConflictRing = $forestConflictRing;
            $conflictingCode = $forestConflictCode;
            $conflictingName = $forestConflictName;
            $conflictAreaM2 = round($maxForestOverlapHa * 10000.0, 1);
        } elseif ($maxNeighborOverlapPct > 2.5) {
            $complianceStatus = 'REJECTED_OVERLAP';
            $primaryConflictRing = $neighborConflictRing;
            $conflictingCode = $neighborConflictCode;
            $conflictingName = $neighborConflictName;
            $conflictAreaM2 = round($maxNeighborOverlapHa * 10000.0, 1);
        } elseif ($maxForestOverlapPct > 0.0 && $maxForestOverlapPct <= 1.0) {
            $complianceStatus = 'WARNING_BUFFER';
            $primaryConflictRing = $forestConflictRing;
            $conflictingCode = $forestConflictCode;
            $conflictingName = $forestConflictName;
            $conflictAreaM2 = round($maxForestOverlapHa * 10000.0, 1);
        }

        $elapsedMs = round((hrtime(true) - $t0) / 1e6, 2);

        return [
            'subject_area_ha' => $subjectAreaHa,
            'forest_overlap_ha' => $maxForestOverlapHa,
            'forest_overlap_pct' => $maxForestOverlapPct,
            'neighbor_overlap_ha' => $maxNeighborOverlapHa,
            'neighbor_overlap_pct' => $maxNeighborOverlapPct,
            'conflict_intersection_ring' => $primaryConflictRing,
            'conflict_area_m2' => $conflictAreaM2,
            'conflicting_entity_code' => $conflictingCode,
            'conflicting_entity_name' => $conflictingName,
            'compliance_status' => $complianceStatus,
            'audit_latency_ms' => max(0.15, $elapsedMs),
        ];
    }

    /**
     * Compute Haversine great-circle distance in meters between two WGS84 coordinates.
     */
    public function haversineDistanceM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2.0) * sin($dLat / 2.0)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2.0) * sin($dLng / 2.0);

        $c = 2.0 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));

        return self::WGS84_RADIUS_M * $c;
    }

    /**
     * Compute interior deflection angle (in degrees, 0..180) at vertex P_curr formed by P_prev -> P_curr <- P_next.
     * An acute needle reflection spike jumps out and returns almost along the same path, yielding theta < 18 deg.
     *
     * @param  array<string, mixed>  $prev
     * @param  array<string, mixed>  $curr
     * @param  array<string, mixed>  $next
     */
    public function computeInteriorDeflectionAngleDeg(array $prev, array $curr, array $next): float
    {
        $cosLat = cos(deg2rad((float) $curr['lat']));
        $ux = deg2rad((float) $prev['lng'] - (float) $curr['lng']) * self::WGS84_RADIUS_M * $cosLat;
        $uy = deg2rad((float) $prev['lat'] - (float) $curr['lat']) * self::WGS84_RADIUS_M;

        $wx = deg2rad((float) $next['lng'] - (float) $curr['lng']) * self::WGS84_RADIUS_M * $cosLat;
        $wy = deg2rad((float) $next['lat'] - (float) $curr['lat']) * self::WGS84_RADIUS_M;

        $normU = hypot($ux, $uy);
        $normW = hypot($wx, $wy);

        if ($normU < 1e-6 || $normW < 1e-6) {
            return 180.0;
        }

        $cosTheta = ($ux * $wx + $uy * $wy) / ($normU * $normW);
        $cosTheta = max(-1.0, min(1.0, $cosTheta));

        return rad2deg(acos($cosTheta));
    }

    /**
     * Sutherland-Hodgman Polygon Clipping Algorithm in 2D tangent meter plane.
     *
     * @param  array<int, array{x: float, y: float}>  $subjectPoly
     * @param  array<int, array{x: float, y: float}>  $clipPoly
     * @return array<int, array{x: float, y: float}>
     */
    public function sutherlandHodgmanClip(array $subjectPoly, array $clipPoly): array
    {
        $subject = $this->ensurePlanarCounterClockwise($subjectPoly);
        $clip = $this->ensurePlanarCounterClockwise($clipPoly);

        if (count($subject) < 3 || count($clip) < 3) {
            return [];
        }

        $outputList = $subject;
        $clipCount = count($clip);

        for ($i = 0; $i < $clipCount; $i++) {
            $edgeStart = $clip[$i];
            $edgeEnd = $clip[($i + 1) % $clipCount];

            $inputList = $outputList;
            $outputList = [];

            if (empty($inputList)) {
                break;
            }

            $s = $inputList[count($inputList) - 1];
            foreach ($inputList as $e) {
                $eInside = $this->isInsideClipEdge($e, $edgeStart, $edgeEnd);
                $sInside = $this->isInsideClipEdge($s, $edgeStart, $edgeEnd);

                if ($eInside) {
                    if (! $sInside) {
                        $outputList[] = $this->computeLineIntersection($s, $e, $edgeStart, $edgeEnd);
                    }
                    $outputList[] = $e;
                } elseif ($sInside) {
                    $outputList[] = $this->computeLineIntersection($s, $e, $edgeStart, $edgeEnd);
                }

                $s = $e;
            }
        }

        return $outputList;
    }

    /**
     * Left-of-edge half-plane test for CCW clip polygon edge.
     *
     * @param  array{x: float, y: float}  $p
     * @param  array{x: float, y: float}  $a
     * @param  array{x: float, y: float}  $b
     */
    private function isInsideClipEdge(array $p, array $a, array $b): bool
    {
        $cross = (($b['x'] - $a['x']) * ($p['y'] - $a['y'])) - (($b['y'] - $a['y']) * ($p['x'] - $a['x']));

        return $cross >= -1e-7;
    }

    /**
     * Compute intersection point of segment S->E with infinite clip line A->B.
     *
     * @param  array{x: float, y: float}  $s
     * @param  array{x: float, y: float}  $e
     * @param  array{x: float, y: float}  $a
     * @param  array{x: float, y: float}  $b
     * @return array{x: float, y: float}
     */
    private function computeLineIntersection(array $s, array $e, array $a, array $b): array
    {
        $dcx = $a['x'] - $b['x'];
        $dcy = $a['y'] - $b['y'];
        $dpx = $s['x'] - $e['x'];
        $dpy = $s['y'] - $e['y'];

        $n1 = ($a['x'] * $b['y']) - ($a['y'] * $b['x']);
        $n2 = ($s['x'] * $e['y']) - ($s['y'] * $e['x']);
        $denom = ($dcx * $dpy) - ($dcy * $dpx);

        if (abs($denom) < 1e-9) {
            return $e;
        }

        return [
            'x' => (($n1 * $dpx) - ($n2 * $dcx)) / $denom,
            'y' => (($n1 * $dpy) - ($n2 * $dcy)) / $denom,
        ];
    }

    /**
     * Convert closed [lng, lat] ring to OpenGIS WKT POLYGON string.
     *
     * @param  array<int, array<int, float>>  $ring
     */
    public function ringToWkt(array $ring): string
    {
        $closed = $this->ensureClosedRing($ring);
        $pairs = array_map(
            fn (array $pt) => number_format((float) $pt[0], 6, '.', '').' '.number_format((float) $pt[1], 6, '.', ''),
            $closed
        );

        return 'POLYGON(('.implode(', ', $pairs).'))';
    }

    /**
     * Ensure ring is closed (first vertex == last vertex).
     *
     * @param  array<int, array<int, float>>  $ring
     * @return array<int, array<int, float>>
     */
    public function ensureClosedRing(array $ring): array
    {
        if (empty($ring)) {
            return [];
        }

        $normalized = [];
        foreach ($ring as $pt) {
            if (isset($pt['lng'], $pt['lat'])) {
                $normalized[] = [round((float) $pt['lng'], 6), round((float) $pt['lat'], 6)];
            } elseif (isset($pt[0], $pt[1])) {
                $normalized[] = [round((float) $pt[0], 6), round((float) $pt[1], 6)];
            }
        }

        if (count($normalized) < 2) {
            return $normalized;
        }

        $first = $normalized[0];
        $last = $normalized[count($normalized) - 1];
        if (abs($first[0] - $last[0]) > 1e-7 || abs($first[1] - $last[1]) > 1e-7) {
            $normalized[] = [$first[0], $first[1]];
        }

        return $normalized;
    }

    /**
     * Enforce RFC 7946 Right-Hand Rule (counter-clockwise exterior ring) and closed ring.
     *
     * @param  array<int, array<int, float>>  $ring
     * @return array<int, array<int, float>>
     */
    public function ensureCounterClockwiseAndClosed(array $ring): array
    {
        $closed = $this->ensureClosedRing($ring);
        $n = count($closed) - 1;
        if ($n < 3) {
            return $closed;
        }

        $signedArea = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $signedArea += ($closed[$i][0] * $closed[$i + 1][1]) - ($closed[$i + 1][0] * $closed[$i][1]);
        }

        if ($signedArea < 0) {
            $open = array_slice($closed, 0, $n);
            $open = array_reverse($open);
            $open[] = $open[0];

            return $open;
        }

        return $closed;
    }

    /**
     * Compute geographic centroid of a [lng, lat] ring.
     *
     * @param  array<int, array<int, float>>  $ring
     * @return array{lng: float, lat: float}
     */
    private function computeCentroid(array $ring): array
    {
        $n = max(1, count($ring) - 1);
        $sumLng = 0.0;
        $sumLat = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sumLng += (float) $ring[$i][0];
            $sumLat += (float) $ring[$i][1];
        }

        return [
            'lng' => $sumLng / $n,
            'lat' => $sumLat / $n,
        ];
    }

    /**
     * Project open vertices of a [lng, lat] ring into local tangent plane meters.
     *
     * @param  array<int, array<int, float>>  $ring
     * @return array<int, array{x: float, y: float}>
     */
    private function projectRingToLocalMeters(array $ring, float $refLng, float $refLat): array
    {
        $closed = $this->ensureClosedRing($ring);
        $n = count($closed) - 1;
        $cosLat = cos(deg2rad($refLat));
        $pts = [];

        for ($i = 0; $i < $n; $i++) {
            $pts[] = [
                'x' => deg2rad((float) $closed[$i][0] - $refLng) * self::WGS84_RADIUS_M * $cosLat,
                'y' => deg2rad((float) $closed[$i][1] - $refLat) * self::WGS84_RADIUS_M,
            ];
        }

        return $pts;
    }

    /**
     * Unproject planar meter vertices back to a closed 6-decimal [lng, lat] ring.
     *
     * @param  array<int, array{x: float, y: float}>  $metersPoly
     * @return array<int, array<int, float>>
     */
    private function unprojectMetersToRing(array $metersPoly, float $refLng, float $refLat): array
    {
        $cosLat = max(1e-6, cos(deg2rad($refLat)));
        $ring = [];

        foreach ($metersPoly as $pt) {
            $lng = $refLng + rad2deg($pt['x'] / (self::WGS84_RADIUS_M * $cosLat));
            $lat = $refLat + rad2deg($pt['y'] / self::WGS84_RADIUS_M);
            $ring[] = [round($lng, 6), round($lat, 6)];
        }

        return $this->ensureCounterClockwiseAndClosed($ring);
    }

    /**
     * Ensure planar vertices are ordered counter-clockwise without duplicate closing vertex.
     *
     * @param  array<int, array{x: float, y: float}>  $poly
     * @return array<int, array{x: float, y: float}>
     */
    private function ensurePlanarCounterClockwise(array $poly): array
    {
        $n = count($poly);
        if ($n < 3) {
            return $poly;
        }

        $signedArea2 = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $j = ($i + 1) % $n;
            $signedArea2 += ($poly[$i]['x'] * $poly[$j]['y']) - ($poly[$j]['x'] * $poly[$i]['y']);
        }

        if ($signedArea2 < 0.0) {
            return array_reverse($poly);
        }

        return $poly;
    }

    /**
     * Compute planar polygon area in square meters using the Shoelace theorem.
     *
     * @param  array<int, array{x: float, y: float}>  $poly
     */
    private function computePlanarAreaM2(array $poly): float
    {
        $n = count($poly);
        if ($n < 3) {
            return 0.0;
        }

        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $j = ($i + 1) % $n;
            $sum += ($poly[$i]['x'] * $poly[$j]['y']) - ($poly[$j]['x'] * $poly[$i]['y']);
        }

        return 0.5 * abs($sum);
    }

    /**
     * Fast axis-aligned bounding box (AABB) pre-check before Sutherland-Hodgman clipping.
     *
     * @param  array<int, array<int, float>>  $ringA
     * @param  array<int, array<int, float>>  $ringB
     */
    private function boundingBoxesIntersect(array $ringA, array $ringB): bool
    {
        $minLngA = INF;
        $maxLngA = -INF;
        $minLatA = INF;
        $maxLatA = -INF;
        foreach ($ringA as $pt) {
            $minLngA = min($minLngA, (float) $pt[0]);
            $maxLngA = max($maxLngA, (float) $pt[0]);
            $minLatA = min($minLatA, (float) $pt[1]);
            $maxLatA = max($maxLatA, (float) $pt[1]);
        }

        $minLngB = INF;
        $maxLngB = -INF;
        $minLatB = INF;
        $maxLatB = -INF;
        foreach ($ringB as $pt) {
            $minLngB = min($minLngB, (float) $pt[0]);
            $maxLngB = max($maxLngB, (float) $pt[0]);
            $minLatB = min($minLatB, (float) $pt[1]);
            $maxLatB = max($maxLatB, (float) $pt[1]);
        }

        return ! ($maxLngA < $minLngB || $maxLngB < $minLngA || $maxLatA < $minLatB || $maxLatB < $minLatA);
    }
}
