<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\MedicalCase;
use App\Models\User;
use App\Services\EGov\CompassBudgetService;
use App\Services\EGov\EGovChainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditChainAndBudgetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * B9: each chain_hash covers only its own row, so an edit left the log
     * looking valid.
     */
    public function test_audit_events_are_hash_chained(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $case = MedicalCase::firstOrFail();
        $actor = User::firstOrFail();
        $chain = app(EGovChainService::class);

        $first = $chain->recordEvent($case, $actor, 'EVENT_ONE', 'First');
        $second = $chain->recordEvent($case, $actor, 'EVENT_TWO', 'Second');

        // Each event stores a full 64-char digest, not a 16-char prefix.
        $this->assertSame(64, strlen($first->chain_hash));
        $this->assertSame(64, strlen($first->payload_sha256));
        $this->assertSame(64, strlen($second->chain_hash));

        // The second event must be linked to the first.
        $this->assertSame(
            hash('sha256', $first->chain_hash . $second->payload_sha256),
            $second->chain_hash,
            'A later event must chain to its predecessor.'
        );

        $this->assertNotSame($first->chain_hash, $second->chain_hash);
    }

    public function test_chain_verification_passes_for_untampered_events(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $case = MedicalCase::firstOrFail();
        $actor = User::firstOrFail();
        $chain = app(EGovChainService::class);

        $chain->recordEvent($case, $actor, 'EVENT_ONE', 'First');
        $chain->recordEvent($case, $actor, 'EVENT_TWO', 'Second');

        $result = $chain->verifyTimeline($case);

        $this->assertTrue($result['verified']);
        // The seeder also writes chain-linked events for this case, so assert
        // that both new events were included rather than a fixed total.
        $this->assertGreaterThanOrEqual(2, $result['checked']);
    }

    public function test_chain_verification_detects_a_tampered_event(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $case = MedicalCase::firstOrFail();
        $actor = User::firstOrFail();
        $chain = app(EGovChainService::class);

        $chain->recordEvent($case, $actor, 'EVENT_ONE', 'First');
        $second = $chain->recordEvent($case, $actor, 'EVENT_TWO', 'Second');

        // Tamper with the stored row without recomputing the chain.
        $second->payload_sha256 = hash('sha256', 'forged payload');
        $second->save();

        $result = $chain->verifyTimeline($case);

        $this->assertFalse($result['verified'], 'A tampered event must break the chain.');
        $this->assertSame($second->id, $result['broken_at_event_id']);
    }

    public function test_timeline_verify_endpoint_reports_a_broken_chain(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->actingAsRole('applicant');

        $case = MedicalCase::firstOrFail();
        $actor = User::firstOrFail();
        $chain = app(EGovChainService::class);

        $event = $chain->recordEvent($case, $actor, 'EVENT_ONE', 'First');

        $this->getJson("/api/v1/cases/{$case->id}/timeline/verify")
            ->assertStatus(200)
            ->assertJsonPath('verification.verified', true);

        $event->payload_sha256 = hash('sha256', 'forged');
        $event->save();

        $this->getJson("/api/v1/cases/{$case->id}/timeline/verify")
            ->assertStatus(409)
            ->assertJsonPath('verification.verified', false);
    }

    /**
     * A chain containing events that cannot be checked is not a verified
     * chain: reporting verified=true alongside unverifiable event ids would be
     * the same kind of unfounded claim this work is removing.
     */
    public function test_chain_with_unverifiable_events_is_not_reported_as_verified(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $case = MedicalCase::firstOrFail();
        $actor = User::firstOrFail();
        $chain = app(EGovChainService::class);

        $chained = $chain->recordEvent($case, $actor, 'EVENT_ONE', 'First');

        // Simulate a row written before chaining existed.
        $legacy = AuditEvent::create([
            'medical_case_id' => $case->id,
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'action' => 'LEGACY_EVENT',
            'description' => 'Written before chaining existed',
            'chain_hash' => 'EGC-ABCDEF012345',
            'payload_sha256' => null,
        ]);

        $result = $chain->verifyTimeline($case);

        $this->assertContains($legacy->id, $result['unverifiable_event_ids']);
        $this->assertFalse(
            $result['verified'],
            'A chain with unverifiable links must not be reported as verified.'
        );
        $this->assertSame('events_predate_chaining', $result['reason']);
        $this->assertGreaterThanOrEqual(1, $result['checked']);
        $this->assertNotEmpty($chained->chain_hash);
    }

    /**
     * B12: where('status','active') never matched, so utilized was always ₱0.
     */
    public function test_compass_budget_counts_live_guarantee_letters(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $budget = app(CompassBudgetService::class)->getBudgetStatus('DSWD-AICS');

        // The seeder issues one 'valid' ₱50,000 GL.
        $this->assertSame(50000.0, (float) $budget['utilized_amount']);
        $this->assertGreaterThan(0, $budget['utilized_amount']);
    }

    /**
     * B14: the PhilSys ID and birth date were hard-coded for every citizen.
     */
    public function test_identity_verification_stores_the_returned_philsys_id(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->actingAsRole('applicant');

        $applicant = User::where('egov_sub', 'MVPCBEUVCGPZR')->firstOrFail();

        $before = \App\Models\ApplicantProfile::where('user_id', $applicant->id)->firstOrFail();
        $this->assertSame('PSN-8192-3049-1829', $before->philsys_id, 'precondition: seeded placeholder id');

        $response = $this->postJson('/api/v1/identity/verify', [
            'consent' => true,
            'first_name' => 'JOSIE',
            'last_name' => 'DELA CRUZ',
            'birth_date' => '1990-01-01',
        ]);

        $response->assertStatus(200);

        $after = \App\Models\ApplicantProfile::where('user_id', $applicant->id)->firstOrFail();

        $this->assertNotSame(
            'PSN-8192-3049-1829',
            $after->philsys_id,
            'The hard-coded PhilSys id must no longer be written for every citizen.'
        );
        $this->assertNotNull($after->philsys_id);
        $this->assertSame('verified', $after->status);
    }

    public function test_identity_verification_requires_consent(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->actingAsRole('applicant');

        $this->postJson('/api/v1/identity/verify', ['consent' => false])
            ->assertStatus(422);
    }
}
