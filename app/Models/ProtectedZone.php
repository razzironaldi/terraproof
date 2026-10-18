<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProtectedZone extends Model
{
    protected $fillable = [
        'zone_code',
        'name',
        'zone_type',
        'polygon_ring',
        'wkt_polygon',
        'area_hectares',
        'managing_authority',
    ];

    protected $casts = [
        'polygon_ring' => 'array',
        'area_hectares' => 'float',
    ];
}
