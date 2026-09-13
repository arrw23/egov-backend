<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B16: the seeded applicant differed from the identity the app signs in as,
 * so GET /cases returned [] and the dashboard fell back to hard-coded values.
 */
class SeededApplicantTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_applicant_matches_the_signed_in_identity(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', [
            'egov_sub' => 'MVPCBEUVCGPZR',
            'role' => 'applicant',
        ]);
    }

    public function test_cases_endpoint_returns_the_seeded_case_for_the_default_applicant(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAsRole('applicant');

        $response = $this->getJson('/api/v1/cases');

        $response->assertStatus(200);

        $cases = $response->json('cases');
        $this->assertNotEmpty(
            $cases,
            'GET /api/v1/cases must return the seeded case for the default applicant.'
        );
        $this->assertSame('MGL-2026-001284', $cases[0]['case_number']);
    }

    public function test_notification_reference_points_at_the_real_guarantee_letter(): void
    {
        $this->seed(DatabaseSeeder::class);

        $gl = \App\Models\GuaranteeLetter::firstOrFail();
        $notification = \App\Models\Notification::where('reference_type', 'GuaranteeLetter')->firstOrFail();

        $this->assertSame(
            $gl->id,
            $notification->reference_id,
            'The seeded notification must reference the real GL id, not a literal 1.'
        );
    }
}
