<?php

namespace Tests\Feature;

use App\Models\AgencyApplication;
use App\Models\AgencyProgram;
use App\Models\GuaranteeLetter;
use App\Models\HospitalDocumentRequest;
use App\Models\MedicalCase;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicalGuaranteeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_mock_auth_and_profile_fetching(): void
    {
        $response = $this->getJson('/api/v1/me');
        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('user.role', 'applicant');
    }

    public function test_philsys_identity_verification(): void
    {
        $response = $this->postJson('/api/v1/identity/verify', [
            'consent' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('profile.status', 'Verified');
    }

    public function test_full_assistance_journey_applicant_to_utilization(): void
    {
        // 1. Fetch Pitch Case (MGL-2026-001284)
        $case = MedicalCase::where('case_number', 'MGL-2026-001284')->first();
        $this->assertNotNull($case);
        $this->assertEquals(150000.00, (float) $case->verified_bill);

        // 2. Fetch Guarantee Letter GL-DSWD-2026-04821
        $gl = GuaranteeLetter::where('gl_number', 'GL-DSWD-2026-04821')->first();
        $this->assertNotNull($gl);
        $this->assertEquals(50000.00, (float) $gl->approved_amount);

        // 3. Hospital validates guarantee letter via QR / GL Number
        $valRes = $this->postJson('/api/v1/guarantees/validate', [
            'gl_number' => 'GL-DSWD-2026-04821',
        ]);

        $valRes->assertStatus(200)
            ->assertJsonPath('guarantee.gl_number', 'GL-DSWD-2026-04821')
            ->assertJsonPath('guarantee.approved_amount', 50000)
            ->assertJsonPath('guarantee.remaining_value', 50000);

        // 4. Hospital records ₱50,000 utilization
        $utilRes = $this->postJson("/api/v1/guarantees/{$gl->id}/utilizations", [
            'utilized_amount' => 50000,
            'billing_reference' => 'INV-2026-9901',
        ]);

        $utilRes->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('guarantee_status', 'fully_utilized');

        // 5. Re-check calculations on MedicalCase
        $caseRes = $this->getJson("/api/v1/cases/{$case->id}");
        $caseRes->assertStatus(200)
            ->assertJsonPath('case.financials.verified_bill', 150000)
            ->assertJsonPath('case.financials.total_approved_assistance', 50000)
            ->assertJsonPath('case.financials.total_utilized', 50000)
            ->assertJsonPath('case.financials.remaining_uncovered_balance', 100000);
    }
}
