<?php

namespace Database\Seeders;

use App\Models\AgencyApplication;
use App\Models\AgencyProgram;
use App\Models\ApplicantProfile;
use App\Models\AuditEvent;
use App\Models\CaseDocument;
use App\Models\GuaranteeLetter;
use App\Models\HospitalDocumentRequest;
use App\Models\MedicalCase;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Organizations
        $mgh = Organization::create([
            'name' => 'Manila General Hospital',
            'code' => 'MGH-MANILA',
            'type' => 'hospital',
            'address' => 'Taft Avenue, Ermita, Manila',
            'contact_email' => 'records@manilageneral.ph',
        ]);

        $pgh = Organization::create([
            'name' => 'Philippine General Hospital',
            'code' => 'PGH-MANILA',
            'type' => 'hospital',
            'address' => 'Taft Avenue, Ermita, Manila',
            'contact_email' => 'medicalrecords@pgh.gov.ph',
        ]);

        $dswd = Organization::create([
            'name' => 'Department of Social Welfare and Development NCR',
            'code' => 'DSWD-NCR',
            'type' => 'agency',
            'address' => '389 San Rafael St, Sampaloc, Manila',
            'contact_email' => 'aics.ncr@dswd.gov.ph',
        ]);

        $pcso = Organization::create([
            'name' => 'Philippine Charity Sweepstakes Office',
            'code' => 'PCSO-MAIN',
            'type' => 'agency',
            'address' => 'Mandaluyong City, Metro Manila',
            'contact_email' => 'assistance@pcso.gov.ph',
        ]);

        // 2. Agency Programs
        $aicsProgram = AgencyProgram::create([
            'agency_id' => $dswd->id,
            'name' => 'Assistance to Individuals in Crisis Situations (AICS)',
            'code' => 'DSWD-AICS-MED',
            'description' => 'Direct financial and medical guarantee assistance for indigent patients requiring confinement or surgical procedures.',
            'max_assistance_amount' => 100000.00,
            'criteria_summary' => 'Requires barangay indigency certificate, hospital billing estimate, and official medical abstract.',
        ]);

        AgencyProgram::create([
            'agency_id' => $pcso->id,
            'name' => 'Medical Assistance Program (MAP)',
            'code' => 'PCSO-MAP-2026',
            'description' => 'Medical guarantee letter support for hospital bills, medicine, and surgical operations.',
            'max_assistance_amount' => 150000.00,
            'criteria_summary' => 'Requires clinical abstract, statement of account, and PhilSys verified identity.',
        ]);

        // 3. Users
        $applicantUser = User::create([
            'egov_sub' => 'egov-sub-applicant-maria-001',
            'name' => 'Maria Lourdes Santos',
            'email' => 'maria.santos@example.ph',
            'role' => 'applicant',
            'verified_identity' => true,
            'avatar_url' => 'https://ui-avatars.com/api/?name=Maria+Santos',
        ]);

        $hospitalStaff = User::create([
            'egov_sub' => 'egov-sub-hospital-ana-002',
            'name' => 'Dr. Ana Reyes',
            'email' => 'ana.reyes@manilageneral.ph',
            'role' => 'hospital_staff',
            'organization_id' => $mgh->id,
            'verified_identity' => true,
            'avatar_url' => 'https://ui-avatars.com/api/?name=Ana+Reyes',
        ]);

        $agencyEvaluator = User::create([
            'egov_sub' => 'egov-sub-agency-miguel-003',
            'name' => 'Miguel dela Cruz',
            'email' => 'miguel.delacruz@dswd.gov.ph',
            'role' => 'agency_evaluator',
            'organization_id' => $dswd->id,
            'verified_identity' => true,
            'avatar_url' => 'https://ui-avatars.com/api/?name=Miguel+dela+Cruz',
        ]);

        // 4. Applicant Profile
        ApplicantProfile::create([
            'user_id' => $applicantUser->id,
            'philsys_id' => 'PSN-8192-3049-1829',
            'full_name' => 'Maria Lourdes Santos',
            'birth_date' => '1989-09-18',
            'consent_given' => true,
            'consent_timestamp' => now()->subDays(5),
            'verification_reference' => 'EVR-8F2A-19C0-2026',
            'status' => 'verified',
        ]);

        // 5. Seed Pitch Medical Case
        $medicalCase = MedicalCase::create([
            'case_number' => 'MGL-2026-001284',
            'applicant_id' => $applicantUser->id,
            'patient_name' => 'Juan D. Santos',
            'relationship' => 'Sibling',
            'provider_id' => $mgh->id,
            'condition_category' => 'Laparoscopic appendectomy',
            'verified_bill' => 150000.00,
            'treatment_date' => '2026-07-18',
            'status' => 'GUARANTEE_LETTER_ISSUED',
        ]);

        // 6. Case Documents
        $docs = [
            [
                'type' => 'id',
                'title' => 'PhilSys Digital ID',
                'status' => 'verified',
                'hash' => 'DOC-HASH-9A0F1E2D3C4B',
                'ref' => 'EVR-8F2A-19C0-2026',
            ],
            [
                'type' => 'indigency',
                'title' => 'Barangay Certificate of Indigency',
                'status' => 'verified',
                'hash' => 'DOC-HASH-8B7C6D5E4F3A',
                'ref' => 'BRGY-MANILA-2026-99',
            ],
            [
                'type' => 'medical_abstract',
                'title' => 'Official Medical Abstract',
                'status' => 'certified',
                'hash' => 'DOC-HASH-7F6E5D4C3B2A',
                'ref' => 'HSP-CERT-8812',
            ],
            [
                'type' => 'statement_of_account',
                'title' => 'Certified Statement of Account (₱150,000)',
                'status' => 'certified',
                'hash' => 'DOC-HASH-6E5D4C3B2A1F',
                'ref' => 'SOA-MGH-2026-441',
            ],
            [
                'type' => 'treatment_order',
                'title' => 'Physician Treatment Order',
                'status' => 'certified',
                'hash' => 'DOC-HASH-5D4C3B2A1F0E',
                'ref' => 'ORD-MGH-2026-102',
            ],
        ];

        foreach ($docs as $d) {
            CaseDocument::create([
                'medical_case_id' => $medicalCase->id,
                'document_type' => $d['type'],
                'title' => $d['title'],
                'storage_path' => "cases/{$medicalCase->id}/{$d['type']}.pdf",
                'file_size' => rand(1500000, 3000000),
                'status' => $d['status'],
                'sha256_hash' => $d['hash'],
                'verification_reference' => $d['ref'],
                'verified_by_user_id' => in_array($d['type'], ['medical_abstract', 'statement_of_account', 'treatment_order']) ? $hospitalStaff->id : null,
                'extracted_json' => [
                    'patient' => 'Juan D. Santos',
                    'diagnosis' => 'Acute appendicitis',
                    'treatment' => 'Laparoscopic appendectomy',
                    'total_bill' => 150000.00,
                    'disclaimer' => 'AI-generated summary — subject to evaluator review.',
                ],
            ]);
        }

        // 7. Hospital Document Request
        HospitalDocumentRequest::create([
            'medical_case_id' => $medicalCase->id,
            'hospital_id' => $mgh->id,
            'requested_document_types' => ['medical_abstract', 'statement_of_account', 'treatment_order'],
            'status' => 'certified',
            'notes' => 'Medical records certified directly by Dr. Ana Reyes.',
        ]);

        // 8. Agency Application
        $app = AgencyApplication::create([
            'medical_case_id' => $medicalCase->id,
            'agency_program_id' => $aicsProgram->id,
            'requested_amount' => 50000.00,
            'approved_amount' => 50000.00,
            'status' => 'approved',
            'decision_reason' => 'Eligible medical assistance under DSWD AICS Program.',
            'remarks' => 'Full requested amount approved for laparoscopic appendectomy procedure.',
            'validity_days' => 30,
            'evaluator_id' => $agencyEvaluator->id,
        ]);

        // 9. Guarantee Letter
        GuaranteeLetter::create([
            'gl_number' => 'GL-DSWD-2026-04821',
            'agency_application_id' => $app->id,
            'medical_case_id' => $medicalCase->id,
            'patient_name' => 'JUAN DELA CRUZ SANTOS',
            'applicant_name' => 'MARIA LOURDES SANTOS',
            'hospital_name' => 'Manila General Hospital',
            'approved_amount' => 50000.00,
            'covered_service' => 'Laparoscopic appendectomy and related confinement',
            'issue_date' => now()->subDays(1),
            'expiration_date' => now()->addDays(29),
            'digital_signatory_name' => 'ELENA P. ROBLES',
            'digital_signatory_role' => 'Regional Director · DSWD NCR',
            'qr_payload' => json_encode([
                'gl_number' => 'GL-DSWD-2026-04821',
                'patient' => 'JUAN DELA CRUZ SANTOS',
                'amount' => 50000.00,
                'agency' => 'DSWD NCR',
                'provider' => 'Manila General Hospital',
                'valid_until' => now()->addDays(29)->format('Y-m-d'),
                'chain_ref' => 'EGC-7F3A-91D2-B840',
            ]),
            'chain_reference' => 'EGC-7F3A-91D2-B840',
            'status' => 'valid',
        ]);

        // 10. Audit Timeline Events & Notifications
        $events = [
            ['action' => 'IDENTITY_VERIFIED', 'desc' => 'Identity verified with PhilSys eVerify', 'actor' => $applicantUser],
            ['action' => 'HOSPITAL_REQUEST_SENT', 'desc' => 'Requested official medical records from Manila General Hospital', 'actor' => $applicantUser],
            ['action' => 'DOCUMENTS_CERTIFIED', 'desc' => 'Official medical abstract and billing statement certified by Dr. Ana Reyes', 'actor' => $hospitalStaff],
            ['action' => 'APPLICATION_SUBMITTED', 'desc' => 'Submitted ₱50,000 assistance request to DSWD AICS Program', 'actor' => $applicantUser],
            ['action' => 'APPLICATION_APPROVED', 'desc' => 'Assistance approved for ₱50,000 by Miguel dela Cruz', 'actor' => $agencyEvaluator],
            ['action' => 'GUARANTEE_ISSUED', 'desc' => 'Digital Guarantee Letter GL-DSWD-2026-04821 issued by DSWD NCR', 'actor' => $agencyEvaluator],
        ];

        foreach ($events as $e) {
            AuditEvent::create([
                'medical_case_id' => $medicalCase->id,
                'actor_id' => $e['actor']->id,
                'actor_name' => $e['actor']->name,
                'action' => $e['action'],
                'description' => $e['desc'],
                'chain_hash' => 'EGC-' . strtoupper(substr(hash('sha256', $e['action'] . microtime()), 0, 12)),
            ]);
        }

        Notification::create([
            'user_id' => $applicantUser->id,
            'title' => 'Guarantee Letter Issued',
            'message' => 'Your ₱50,000 medical assistance guarantee letter GL-DSWD-2026-04821 has been issued by DSWD NCR.',
            'type' => 'success',
            'reference_type' => 'GuaranteeLetter',
            'reference_id' => 1,
        ]);
    }
}
