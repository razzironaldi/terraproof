<?php

namespace Tests\Feature;

use Database\Seeders\LampungAgroClusterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Verify root dashboard loads cleanly with seeded West Lampung EUDR telemetry.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->seed(LampungAgroClusterSeeder::class);
        $response = $this->get('/');
        $response->assertStatus(200);
    }
}
