<?php

namespace Tests\Feature;

use App\Models\AgencyApplication;
use App\Models\AgencyProgram;
use App\Models\CaseDocument;
use App\Models\GuaranteeLetter;
use App\Models\HospitalDocumentRequest;
use App\Models\MedicalCase;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for B3, B4, B5, B6 and B7 on the hospital and agency
 * portals, plus the seeded demo rows added by F2.
 */
class HospitalAndAgencyFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        // Routes are behind auth:sanctum + role middleware now. Most tests here
        // exercise the hospital portal; agency tests switch role explicitly.
        $this->actingAsRole('hospital_staff');
    }

    private function mgh(): Organization
    {
        return Organization::where('code', 'MGH-MANILA')->firstOrFail();
    }

    private function hospitalStaff(): User
    {
        return User::where('egov_sub', 'egov-sub-hospital-ana-002')->firstOrFail();
    }

    // ---------------------------------------------------------------------
    // B4 — hospital queue is scoped to the staff member's hospital
    // ---------------------------------------------------------------------

    public function test_hospital_requests_are_filtered_to_the_staff_members_hospital(): void
    {
        $mgh = $this->mgh();
        $pgh = Organization::where('code', 'PGH-MANILA')->firstOrFail();

        $mghRequestIds = HospitalDocumentRequest::where('hospital_id', $mgh->id)->pluck('id')->all();
        $pghRequestIds = HospitalDocumentRequest::where('hospital_id', $pgh->id)->pluck('id')->all();

        $this->assertNotEmpty($mghRequestIds, 'The MGH queue must have seeded requests.');
        $this->assertNotEmpty($pghRequestIds, 'Another hospital must have a request to prove the filter.');

        $response = $this->getJson('/api/v1/hospital/requests');
        $response->assertStatus(200)->assertJsonPath('status', 'success');

        $returnedIds = array_column($response->json('requests'), 'id');

        $this->assertNotEmpty($returnedIds);
        $this->assertEmpty(array_diff($returnedIds, $mghRequestIds), 'Only MGH requests may be returned.');
        $this->assertEmpty(array_intersect($returnedIds, $pghRequestIds), "Another hospital's queue leaked in.");

        foreach ($response->json('requests') as $request) {
            $this->assertSame($mgh->id, $request['hospital_id']);
        }
    }

    // ---------------------------------------------------------------------
    // B3 — submitDocuments certifies what exists, never invents, never duplicates
    // ---------------------------------------------------------------------

    public function test_submit_documents_certifies_existing_documents_and_reports_missing_types(): void
    {
        $case = MedicalCase::where('case_number', 'MGL-2026-001279')->firstOrFail();
        $docReq = HospitalDocumentRequest::where('medical_case_id', $case->id)->firstOrFail();
        $staff = $this->hospitalStaff();

        $before = $case->documents()->count();

        $response = $this->postJson("/api/v1/hospital/requests/{$docReq->id}/documents");
        $response->assertStatus(200)->assertJsonPath('status', 'success');

        // statement_of_account was never uploaded: it is reported, not invented.
        $this->assertContains('statement_of_account', $response->json('missing'));
        $this->assertNotContains('medical_abstract', $response->json('missing'));
        $this->assertSame(0, $case->documents()->where('document_type', 'statement_of_account')->count());

        // The already-uploaded medical abstract is certified in place and anchored.
        $abstract = $case->documents()->where('document_type', 'medical_abstract')->firstOrFail();
        $this->assertSame('certified', $abstract->status);
        $this->assertSame($staff->id, $abstract->verified_by_user_id);
        $this->assertNotEmpty($abstract->extracted_json['blockchain_tx_hash'] ?? null);
        $this->assertNotEmpty($abstract->extracted_json['blockchain_block_number'] ?? null);
        $this->assertNotEmpty($abstract->extracted_json['full_sha256'] ?? null);

        // No documents were created.
        $this->assertSame($before, $case->documents()->count());
    }

    public function test_submit_documents_is_idempotent_and_does_not_duplicate_on_a_second_call(): void
    {
        $case = MedicalCase::where('case_number', 'MGL-2026-001279')->firstOrFail();
        $docReq = HospitalDocumentRequest::where('medical_case_id', $case->id)->firstOrFail();

        $this->postJson("/api/v1/hospital/requests/{$docReq->id}/documents")->assertStatus(200);

        $afterFirst = $case->documents()->count();

        $second = $this->postJson("/api/v1/hospital/requests/{$docReq->id}/documents");
        $second->assertStatus(200);

        $this->assertSame(
            $afterFirst,
            $case->documents()->count(),
            'A second submitDocuments call must not create duplicate documents.'
        );
        $this->assertSame(
            1,
            $case->documents()->where('document_type', 'medical_abstract')->count(),
            'The medical abstract must not be duplicated.'
        );
        $this->assertContains('statement_of_account', $second->json('missing'));
    }

    public function test_submit_documents_certifies_citizen_uploaded_hashed_documents(): void
    {
        $case = MedicalCase::where('case_number', 'MGL-2026-001279')->firstOrFail();
        $docReq = HospitalDocumentRequest::where('medical_case_id', $case->id)->firstOrFail();
        $staff = $this->hospitalStaff();

        $uploaded = CaseDocument::create([
            'medical_case_id' => $case->id,
            'document_type' => 'indigency',
            'title' => 'Barangay Certificate of Indigency',
            'storage_path' => "cases/{$case->id}/indigency.pdf",
            'file_size' => 512000,
            'status' => 'hashed',
            'sha256_hash' => 'DOC-HASH-CITIZEN-0001',
            'extracted_json' => ['full_sha256' => str_repeat('a', 64)],
        ]);

        $response = $this->postJson("/api/v1/hospital/requests/{$docReq->id}/documents");
        $response->assertStatus(200);

        $uploaded->refresh();
        $this->assertSame('certified', $uploaded->status, 'Citizen-uploaded hashed documents must be certified.');
        $this->assertSame($staff->id, $uploaded->verified_by_user_id);
        $this->assertSame(str_repeat('a', 64), $uploaded->extracted_json['full_sha256']);
        $this->assertNotEmpty($uploaded->extracted_json['blockchain_tx_hash'] ?? null);
    }

    public function test_submit_documents_reports_no_missing_when_every_requested_type_exists(): void
    {
        // Juan's request is fully backed by seeded documents.
        $case = MedicalCase::where('case_number', 'MGL-2026-001284')->firstOrFail();
        $docReq = HospitalDocumentRequest::where('medical_case_id', $case->id)->firstOrFail();
        $before = $case->documents()->count();

        $response = $this->postJson("/api/v1/hospital/requests/{$docReq->id}/documents");

        $response->assertStatus(200);
        $this->assertSame([], $response->json('missing'));
        $this->assertSame($before, $case->documents()->count());
        $this->assertSame($docReq->id, $docReq->fresh()->id);
    }

    public function test_submit_documents_advances_a_submitted_agency_application_to_under_review(): void
    {
        $case = MedicalCase::where('case_number', 'MGL-2026-001265')->firstOrFail();
        $this->assertSame('READY_FOR_SUBMISSION', $case->status);

        $application = AgencyApplication::create([
            'medical_case_id' => $case->id,
            'agency_program_id' => AgencyProgram::firstOrFail()->id,
            'requested_amount' => 50000,
            'approved_amount' => 0,
            'status' => 'submitted',
            'validity_days' => 30,
        ]);

        $docReq = HospitalDocumentRequest::where('medical_case_id', $case->id)->firstOrFail();

        $this->postJson("/api/v1/hospital/requests/{$docReq->id}/documents")->assertStatus(200);

        $this->assertSame('UNDER_AGENCY_REVIEW', $case->fresh()->status);
        $this->assertSame('under_review', $application->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // B5 — utilization cannot overdraw, double-settle, or double-notify
    // ---------------------------------------------------------------------

    public function test_utilization_overdraw_returns_422_and_records_nothing(): void
    {
        $gl = GuaranteeLetter::where('gl_number', 'GL-DSWD-2026-04821')->firstOrFail();

        $response = $this->postJson("/api/v1/guarantees/{$gl->id}/utilizations", [
            'utilized_amount' => 60000,
            'billing_reference' => 'INV-2026-OVERDRAW',
        ]);

        $response->assertStatus(422)->assertJsonPath('status', 'error');

        $this->assertDatabaseCount('guarantee_utilizations', 0);
        $this->assertSame('valid', $gl->fresh()->status);
    }

    public function test_utilization_against_an_expired_guarantee_returns_422(): void
    {
        $gl = GuaranteeLetter::where('gl_number', 'GL-DSWD-2026-04821')->firstOrFail();
        $gl->expiration_date = now()->subDay();
        $gl->save();

        $this->postJson("/api/v1/guarantees/{$gl->id}/utilizations", [
            'utilized_amount' => 1000,
            'billing_reference' => 'INV-2026-EXPIRED',
        ])->assertStatus(422);

        $this->assertDatabaseCount('guarantee_utilizations', 0);
    }

    public function test_utilization_returns_the_settlement_result_and_notifies_once(): void
    {
        $gl = GuaranteeLetter::where('gl_number', 'GL-DSWD-2026-04821')->firstOrFail();
        $applicant = $gl->medicalCase->applicant;
        $notificationsBefore = Notification::where('user_id', $applicant->id)->count();

        $response = $this->postJson("/api/v1/guarantees/{$gl->id}/utilizations", [
            'utilized_amount' => 20000,
            'billing_reference' => 'INV-2026-0001',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('guarantee_status', 'partially_utilized')
            ->assertJsonPath('guarantee.remaining_value', 30000);

        // The direct settlement result is no longer thrown away.
        $this->assertNotEmpty($response->json('settlement'));
        $this->assertSame($gl->gl_number, $response->json('settlement.gl_number'));

        // Exactly ONE applicant notification (previously two were sent).
        $this->assertSame(
            $notificationsBefore + 1,
            Notification::where('user_id', $applicant->id)->count()
        );
    }

    // ---------------------------------------------------------------------
    // B6 / B7 — agency decision guards, GL numbering, signatory, SMS result
    // ---------------------------------------------------------------------

    public function test_repeat_approve_returns_the_same_gl_number_and_creates_only_one_letter(): void
    {
        $this->actingAsRole('agency_evaluator');
        $application = AgencyApplication::whereHas(
            'medicalCase',
            fn ($q) => $q->where('case_number', 'MGL-2026-001304')
        )->firstOrFail();

        $this->assertSame('submitted', $application->status);

        $applicant = $application->medicalCase->applicant;
        $notificationsBefore = Notification::where('user_id', $applicant->id)->count();

        $payload = [
            'action' => 'approve',
            'approved_amount' => 90000,
            'reason' => 'Approved under DSWD AICS.',
        ];

        $first = $this->postJson("/api/v1/agency/applications/{$application->id}/decision", $payload);
        $first->assertStatus(200)->assertJsonPath('status', 'success');

        $glNumber = $first->json('guarantee_letter.gl_number');

        // Continues the seeded sequence (GL-DSWD-2026-04821 -> 04822).
        $this->assertSame('GL-DSWD-2026-04822', $glNumber);
        $this->assertSame(1, GuaranteeLetter::where('agency_application_id', $application->id)->count());

        // Signatory comes from the organization, not a hard-coded name.
        $this->assertSame('ELENA P. ROBLES', $first->json('guarantee_letter.digital_signatory_name'));

        // B7: the real SMS dispatch result is returned for the applicant.
        $this->assertSame('+639171000106', $first->json('sms.number'));
        $this->assertSame(201, $first->json('sms.status'));
        $this->assertNotEmpty($first->json('sms.message'));

        // Exactly one applicant notification for the approval.
        $this->assertSame(
            $notificationsBefore + 1,
            Notification::where('user_id', $applicant->id)->count()
        );

        $second = $this->postJson("/api/v1/agency/applications/{$application->id}/decision", $payload);
        $second->assertStatus(200);

        $this->assertSame($glNumber, $second->json('guarantee_letter.gl_number'));
        $this->assertSame(
            1,
            GuaranteeLetter::where('agency_application_id', $application->id)->count(),
            'A repeat approve must not mint a second guarantee letter.'
        );

        // The repeated call is a pure read: no extra notification, no SMS.
        $this->assertSame(
            $notificationsBefore + 1,
            Notification::where('user_id', $applicant->id)->count()
        );
        $this->assertNull($second->json('sms'));
    }

    public function test_approving_an_application_in_a_non_approvable_status_returns_409(): void
    {
        $this->actingAsRole('agency_evaluator');
        $case = MedicalCase::where('case_number', 'MGL-2026-001303')->firstOrFail();

        $application = AgencyApplication::create([
            'medical_case_id' => $case->id,
            'agency_program_id' => AgencyProgram::firstOrFail()->id,
            'requested_amount' => 50000,
            'approved_amount' => 0,
            'status' => 'denied',
            'decision_reason' => 'Already decided.',
            'validity_days' => 30,
        ]);

        $response = $this->postJson("/api/v1/agency/applications/{$application->id}/decision", [
            'action' => 'approve',
            'approved_amount' => 50000,
            'reason' => 'Trying again.',
        ]);

        $response->assertStatus(409)->assertJsonPath('status', 'error');
        $this->assertSame(0, GuaranteeLetter::where('agency_application_id', $application->id)->count());
        $this->assertSame('UNDER_AGENCY_REVIEW', $case->fresh()->status);
    }

    public function test_approving_when_the_case_cannot_reach_approval_returns_409(): void
    {
        $this->actingAsRole('agency_evaluator');
        $provider = $this->mgh();
        $applicant = User::where('egov_sub', 'egov-sub-applicant-carlos-106')->firstOrFail();

        $case = MedicalCase::create([
            'case_number' => 'MGL-2026-001399',
            'applicant_id' => $applicant->id,
            'patient_name' => 'Carlos H. Mendoza',
            'relationship' => 'Self',
            'provider_id' => $provider->id,
            'condition_category' => 'Lung cancer radiation therapy',
            'verified_bill' => 260000,
            'status' => 'DRAFT',
        ]);

        $application = AgencyApplication::create([
            'medical_case_id' => $case->id,
            'agency_program_id' => AgencyProgram::firstOrFail()->id,
            'requested_amount' => 50000,
            'approved_amount' => 0,
            'status' => 'submitted',
            'validity_days' => 30,
        ]);

        $response = $this->postJson("/api/v1/agency/applications/{$application->id}/decision", [
            'action' => 'approve',
            'approved_amount' => 50000,
            'reason' => 'Approve out of order.',
        ]);

        $response->assertStatus(409);
        $this->assertSame(0, GuaranteeLetter::where('agency_application_id', $application->id)->count());
        $this->assertSame('DRAFT', $case->fresh()->status);
        $this->assertSame('submitted', $application->fresh()->status);
    }

    public function test_approved_amount_above_the_requested_or_uncovered_amount_returns_422(): void
    {
        $this->actingAsRole('agency_evaluator');
        $application = AgencyApplication::whereHas(
            'medicalCase',
            fn ($q) => $q->where('case_number', 'MGL-2026-001303')
        )->firstOrFail();

        // requested_amount is 65,000 for this application.
        $response = $this->postJson("/api/v1/agency/applications/{$application->id}/decision", [
            'action' => 'approve',
            'approved_amount' => 90000,
            'reason' => 'Over the ceiling.',
        ]);

        $response->assertStatus(422)->assertJsonPath('status', 'error');
        $this->assertSame(0, GuaranteeLetter::where('agency_application_id', $application->id)->count());
        $this->assertSame('submitted', $application->fresh()->status);
    }

    public function test_agency_signatory_and_short_code_are_seeded_on_organizations(): void
    {
        $dswd = Organization::where('code', 'DSWD-NCR')->firstOrFail();

        $this->assertSame('DSWD', $dswd->short_code);
        $this->assertSame('ELENA P. ROBLES', $dswd->signatory_name);
        $this->assertSame('Regional Director · DSWD NCR', $dswd->signatory_role);
    }

    // ---------------------------------------------------------------------
    // F2 — the frontend's hard-coded rows now exist as records
    // ---------------------------------------------------------------------

    public function test_demo_portal_rows_are_seeded(): void
    {
        $this->assertDatabaseHas('medical_cases', ['case_number' => 'MGL-2026-001279', 'patient_name' => 'Liza P. Mendoza']);
        $this->assertDatabaseHas('medical_cases', ['case_number' => 'MGL-2026-001265', 'patient_name' => 'Roberto A. Garcia']);
        $this->assertDatabaseHas('medical_cases', ['case_number' => 'MGL-2026-001301', 'patient_name' => 'Teresa M. Ramos']);
        $this->assertDatabaseHas('medical_cases', ['case_number' => 'MGL-2026-001302', 'patient_name' => 'Eduardo V. Gomez']);
        $this->assertDatabaseHas('medical_cases', ['case_number' => 'MGL-2026-001303', 'patient_name' => 'Gabriel P. Mercado']);
        $this->assertDatabaseHas('medical_cases', ['case_number' => 'MGL-2026-001304', 'patient_name' => 'Carlos H. Mendoza']);

        foreach (['NKTI-QC', 'STJUDE-MANILA', 'LCP-QC'] as $code) {
            $this->assertDatabaseHas('organizations', ['code' => $code]);
        }

        $this->assertDatabaseHas('hospital_document_requests', [
            'status' => 'processing',
            'notes' => 'Maternity records under preparation by the medical records unit.',
        ]);
        $this->assertDatabaseHas('hospital_document_requests', [
            'status' => 'certified',
            'notes' => 'Cardiology records certified and anchored by the medical records unit.',
        ]);
    }

    /**
     * The seeded default applicant must keep leading GET /api/v1/cases now
     * that portfolio cases exist for other applicants.
     */
    public function test_default_applicant_still_leads_the_cases_endpoint(): void
    {
        $this->actingAsRole('applicant');
        $response = $this->getJson('/api/v1/cases');
        $response->assertStatus(200);

        $cases = $response->json('cases');
        $this->assertSame('MGL-2026-001284', $cases[0]['case_number']);
        $this->assertCount(1, $cases, 'Portfolio cases must not belong to the default demo applicant.');
    }

    /**
     * The seeded timeline is written through EGovChainService::recordEvent(),
     * so it must verify as an intact hash chain.
     */
    public function test_seeded_audit_timeline_verifies(): void
    {
        $case = MedicalCase::where('case_number', 'MGL-2026-001284')->firstOrFail();

        $response = $this->getJson("/api/v1/cases/{$case->id}/timeline/verify");

        $response->assertStatus(200)
            ->assertJsonPath('verification.verified', true);

        // At least the six seeded events must be verified. The exact count is
        // not asserted: other tests append chain-linked events to this same
        // seeded case, so it varies with execution order.
        $this->assertGreaterThanOrEqual(6, $response->json('verification.checked'));
        $this->assertSame([], $response->json('verification.unverifiable_event_ids'));
    }

    public function test_juan_has_the_social_case_study_document(): void
    {
        $case = MedicalCase::where('case_number', 'MGL-2026-001284')->firstOrFail();

        $this->assertDatabaseHas('case_documents', [
            'medical_case_id' => $case->id,
            'document_type' => 'social_case_study',
        ]);

        // The pre-issued guarantee letter must survive re-seeding untouched.
        $this->assertDatabaseHas('guarantee_letters', [
            'gl_number' => 'GL-DSWD-2026-04821',
            'status' => 'valid',
        ]);
        $this->assertSame(
            1,
            GuaranteeLetter::where('agency_application_id', $case->agencyApplications()->firstOrFail()->id)->count()
        );
    }
}
