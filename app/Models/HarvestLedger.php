<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HarvestLedger extends Model
{
    protected $fillable = [
        'transaction_code',
        'client_tx_id',
        'farm_plot_id',
        'harvest_date',
        'weight_dry_kg',
        'moisture_pct',
        'quota_before_kg',
        'quota_after_kg',
        'excess_laundered_kg',
        'verdict',
        'audit_explanation',
        'hashed_timestamp',
        'previous_hash',
        'record_hash',
        'captured_offline',
    ];

    protected $casts = [
        'harvest_date' => 'date:Y-m-d',
        'weight_dry_kg' => 'float',
        'moisture_pct' => 'float',
        'quota_before_kg' => 'float',
        'quota_after_kg' => 'float',
        'excess_laundered_kg' => 'float',
        'captured_offline' => 'boolean',
    ];

    public function farmPlot(): BelongsTo
    {
        return $this->belongsTo(FarmPlot::class);
    }
}
