<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C6: the agency inbox needed a per-document requirement rollup and a
 * completeness score from the backend, so the UI does not have to invent them.
 */
class AgencyInboxRequirementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAsRole('agency_evaluator');
    }

    public function test_inbox_includes_requirements_and_completeness(): void
    {
        $response = $this->getJson('/api/v1/agency/applications');

        $response->assertStatus(200);

        $applications = $response->json('applications');
        $this->assertNotEmpty($applications);

        foreach ($applications as $app) {
            $this->assertArrayHasKey('requirements', $app);
            $this->assertArrayHasKey('completeness', $app);
            $this->assertArrayHasKey('relationship', $app);

            $this->assertIsInt($app['completeness']);
            $this->assertGreaterThanOrEqual(0, $app['completeness']);
            $this->assertLessThanOrEqual(100, $app['completeness']);

            foreach ($app['requirements'] as $req) {
                $this->assertArrayHasKey('type', $req);
                $this->assertArrayHasKey('title', $req);
                $this->assertArrayHasKey('status', $req);
            }
        }
    }

    public function test_the_seeded_complete_case_scores_100(): void
    {
        $applications = $this->getJson('/api/v1/agency/applications')->json('applications');

        // MGL-2026-001284 (Juan) has every required document plus the social
        // case study, so its completeness must be a real 100, not a fallback.
        $juan = collect($applications)->firstWhere('case_number', 'MGL-2026-001284');

        $this->assertNotNull($juan, 'The seeded Juan case must appear in the agency inbox.');
        $this->assertSame(100, $juan['completeness']);

        $missing = collect($juan['requirements'])->where('status', 'missing')->count();
        $this->assertSame(0, $missing);
    }

    public function test_documents_are_included_for_the_requirement_chips(): void
    {
        $applications = $this->getJson('/api/v1/agency/applications')->json('applications');
        $juan = collect($applications)->firstWhere('case_number', 'MGL-2026-001284');

        $this->assertNotEmpty($juan['documents']);
        $this->assertArrayHasKey('document_type', $juan['documents'][0]);
        $this->assertArrayHasKey('status', $juan['documents'][0]);
    }
}
