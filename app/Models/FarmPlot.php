<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FarmPlot extends Model
{
    protected $fillable = [
        'farmer_id',
        'plot_code',
        'plot_name',
        'commodity',
        'raw_gps_track',
        'clean_polygon_ring',
        'wkt_polygon',
        'raw_vertex_count',
        'clean_vertex_count',
        'spikes_removed',
        'area_raw_ha',
        'area_hectares',
        'elevation_m',
        'tree_density_per_ha',
        'tree_age_years',
        'cropping_system',
        'annual_yield_ceiling_kg',
        'harvested_ytd_kg',
        'remaining_quota_kg',
        'forest_overlap_pct',
        'neighbor_overlap_pct',
        'conflict_intersection_ring',
        'compliance_status',
        'audit_latency_ms',
        'last_audited_at',
    ];

    protected $casts = [
        'raw_gps_track' => 'array',
        'clean_polygon_ring' => 'array',
        'conflict_intersection_ring' => 'array',
        'raw_vertex_count' => 'integer',
        'clean_vertex_count' => 'integer',
        'spikes_removed' => 'integer',
        'area_raw_ha' => 'float',
        'area_hectares' => 'float',
        'elevation_m' => 'integer',
        'tree_density_per_ha' => 'integer',
        'tree_age_years' => 'integer',
        'annual_yield_ceiling_kg' => 'float',
        'harvested_ytd_kg' => 'float',
        'remaining_quota_kg' => 'float',
        'forest_overlap_pct' => 'float',
        'neighbor_overlap_pct' => 'float',
        'audit_latency_ms' => 'float',
        'last_audited_at' => 'datetime',
    ];

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }

    public function harvestLedgers(): HasMany
    {
        return $this->hasMany(HarvestLedger::class);
    }
}
