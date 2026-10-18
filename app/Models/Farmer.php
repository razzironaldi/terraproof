<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Farmer extends Model
{
    protected $fillable = [
        'farmer_code',
        'full_name',
        'cooperative_name',
        'village',
        'district',
        'regency',
        'national_id_sha256',
        'phone_masked',
        'registered_at',
    ];

    protected $casts = [
        'registered_at' => 'datetime',
    ];

    public function farmPlots(): HasMany
    {
        return $this->hasMany(FarmPlot::class);
    }
}
