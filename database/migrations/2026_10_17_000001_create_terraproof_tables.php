<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for TerraProof EUDR Geodesic & Mass-Balance Compliance Engine.
     */
    public function up(): void
    {
        Schema::create('protected_zones', function (Blueprint $table) {
            $table->id();
            $table->string('zone_code', 48)->unique();
            $table->string('name');
            $table->enum('zone_type', ['NATIONAL_PARK', 'PROTECTED_FOREST']);
            $table->json('polygon_ring');
            $table->text('wkt_polygon');
            $table->decimal('area_hectares', 12, 4);
            $table->string('managing_authority');
            $table->timestamps();
        });

        Schema::create('farmers', function (Blueprint $table) {
            $table->id();
            $table->string('farmer_code', 32)->unique();
            $table->string('full_name');
            $table->string('cooperative_name');
            $table->string('village');
            $table->string('district');
            $table->string('regency');
            $table->char('national_id_sha256', 64);
            $table->string('phone_masked', 32);
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('farm_plots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->string('plot_code', 32)->unique();
            $table->string('plot_name');
            $table->enum('commodity', ['ROBUSTA_COFFEE', 'COCOA', 'BLACK_PEPPER']);
            $table->json('raw_gps_track');
            $table->json('clean_polygon_ring');
            $table->text('wkt_polygon');
            $table->unsignedInteger('raw_vertex_count');
            $table->unsignedInteger('clean_vertex_count');
            $table->unsignedInteger('spikes_removed')->default(0);
            $table->decimal('area_raw_ha', 10, 4);
            $table->decimal('area_hectares', 10, 4);
            $table->unsignedInteger('elevation_m');
            $table->unsignedInteger('tree_density_per_ha');
            $table->unsignedInteger('tree_age_years');
            $table->string('cropping_system', 32)->default('MONOCULTURE');
            $table->decimal('annual_yield_ceiling_kg', 10, 2);
            $table->decimal('harvested_ytd_kg', 10, 2)->default(0);
            $table->decimal('remaining_quota_kg', 10, 2);
            $table->decimal('forest_overlap_pct', 5, 2)->default(0);
            $table->decimal('neighbor_overlap_pct', 5, 2)->default(0);
            $table->json('conflict_intersection_ring')->nullable();
            $table->enum('compliance_status', [
                'COMPLIANT',
                'WARNING_BUFFER',
                'REJECTED_DEFORESTATION',
                'REJECTED_OVERLAP',
            ]);
            $table->decimal('audit_latency_ms', 6, 2)->default(0);
            $table->timestamp('last_audited_at')->nullable();
            $table->timestamps();
        });

        Schema::create('harvest_ledgers', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_code', 40)->unique();
            $table->string('client_tx_id', 64)->nullable()->unique();
            $table->foreignId('farm_plot_id')->constrained('farm_plots')->cascadeOnDelete();
            $table->date('harvest_date');
            $table->decimal('weight_dry_kg', 10, 2);
            $table->decimal('moisture_pct', 4, 1);
            $table->decimal('quota_before_kg', 10, 2);
            $table->decimal('quota_after_kg', 10, 2);
            $table->decimal('excess_laundered_kg', 10, 2)->default(0);
            $table->enum('verdict', [
                'APPROVED',
                'BLOCKED_LAUNDERING',
                'BLOCKED_NON_COMPLIANT_PLOT',
            ]);
            $table->text('audit_explanation');
            $table->string('hashed_timestamp', 40);
            $table->char('previous_hash', 64);
            $table->char('record_hash', 64);
            $table->boolean('captured_offline')->default(false);
            $table->timestamps();
        });

        Schema::create('export_containers', function (Blueprint $table) {
            $table->id();
            $table->string('container_code', 40)->unique();
            $table->string('buyer_entity');
            $table->string('destination_port');
            $table->string('commodity', 32);
            $table->decimal('total_net_weight_kg', 12, 2);
            $table->unsignedInteger('total_plots_count');
            $table->unsignedInteger('total_farmers_count');
            $table->char('merkle_root_hash', 64);
            $table->longText('rfc7946_geojson');
            $table->json('dds_passport_payload');
            $table->timestamp('compiled_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('export_containers');
        Schema::dropIfExists('harvest_ledgers');
        Schema::dropIfExists('farm_plots');
        Schema::dropIfExists('farmers');
        Schema::dropIfExists('protected_zones');
    }
};
