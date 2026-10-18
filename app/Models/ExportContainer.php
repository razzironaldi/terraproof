<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExportContainer extends Model
{
    protected $fillable = [
        'container_code',
        'buyer_entity',
        'destination_port',
        'commodity',
        'total_net_weight_kg',
        'total_plots_count',
        'total_farmers_count',
        'merkle_root_hash',
        'rfc7946_geojson',
        'dds_passport_payload',
        'compiled_at',
    ];

    protected $casts = [
        'total_net_weight_kg' => 'float',
        'total_plots_count' => 'integer',
        'total_farmers_count' => 'integer',
        'dds_passport_payload' => 'array',
        'compiled_at' => 'datetime',
    ];
}
