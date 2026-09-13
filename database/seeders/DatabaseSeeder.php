<?php

namespace Database\Seeders;

use App\Models\AgencyApplication;
use App\Models\AgencyProgram;
use App\Models\ApplicantProfile;
use App\Models\CaseDocument;
use App\Models\GuaranteeLetter;
use App\Models\HospitalDocumentRequest;
use App\Models\MedicalCase;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\User;
use App\Services\EGov\EGovChainService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Organizations
        // short_code drives the guarantee-letter prefix; the signatory fields
        // replace the names that used to be hard-coded in AgencyController.
        $mgh = Organization::create([
            'name' => 'Manila General Hospital',
            'code' => 'MGH-MANILA',
            'short_code' => 'MGH',
            'type' => 'hospital',
            'address' => 'Taft Avenue, Ermita, Manila',
            'contact_email' => 'records@manilageneral.ph',
        ]);

        $pgh = Organization::create([
            'name' => 'Philippine General Hospital',
            'code' => 'PGH-MANILA',
            'short_code' => 'PGH',
            'type' => 'hospital',
            'address' => 'Taft Avenue, Ermita, Manila',
            'contact_email' => 'medicalrecords@pgh.gov.ph',
        ]);

        $nkti = Organization::create([
            'name' => 'National Kidney and Transplant Institute',
            'code' => 'NKTI-QC',
            'short_code' => 'NKTI',
            'type' => 'hospital',
            'address' => 'East Avenue, Diliman, Quezon City',
            'contact_email' => 'medicalrecords@nkti.gov.ph',
        ]);

        $stJude = Organization::create([
            'name' => 'St. Jude Hospital',
            'code' => 'STJUDE-MANILA',
            'short_code' => 'STJUDE',
            'type' => 'hospital',
            'address' => 'D. Tuazon Street, Quezon City',
            'contact_email' => 'records@stjudehospital.ph',
        ]);

        $lungCenter = Organization::create([
            'name' => 'Lung Center of the Philippines',
            'code' => 'LCP-QC',
            'short_code' => 'LCP',
            'type' => 'hospital',
            'address' => 'Quezon Avenue, Diliman, Quezon City',
            'contact_email' => 'medicalrecords@lcp.gov.ph',
        ]);

        $dswd = Organization::create([
            'name' => 'Department of Social Welfare and Development NCR',
            'code' => 'DSWD-NCR',
            'short_code' => 'DSWD',
            'type' => 'agency',
            'address' => '389 San Rafael St, Sampaloc, Manila',
            'contact_email' => 'aics.ncr@dswd.gov.ph',
            'signatory_name' => 'ELENA P. ROBLES',
            'signatory_role' => 'Regional Director · DSWD NCR',
        ]);

        $pcso = Organization::create([
            'name' => 'Philippine Charity Sweepstakes Office',
            'code' => 'PCSO-MAIN',
            'short_code' => 'PCSO',
            'type' => 'agency',
            'address' => 'Mandaluyong City, Metro Manila',
            'contact_email' => 'assistance@pcso.gov.ph',
            'signatory_name' => 'MARCOS P. AQUINO',
            'signatory_role' => 'Medical Assistance Program Head · PCSO',
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
        // The applicant must be the identity the app actually signs in as:
        // MockEGovIdentityProvider resolves 'applicant' to egov_sub
        // MVPCBEUVCGPZR. Seeding a different subject made GET /cases return []
        // for the default applicant, so the dashboard fell back to hard-coded
        // values.
        // NOTE: this row is created FIRST so that the default applicant's cases
        // are the oldest and keep leading GET /api/v1/cases.
        $applicantUser = User::create([
            'egov_sub' => 'MVPCBEUVCGPZR',
            'name' => 'JOSIE SANTOS DELA CRUZ',
            'email' => 'josie@yopmail.com',
            'mobile' => '+639090000000',
            'role' => 'applicant',
            'verified_identity' => true,
            'avatar_url' => 'https://ui-avatars.com/api/?name=Josie+Dela+Cruz',
        ]);

        $hospitalStaff = User::create([
            'egov_sub' => 'egov-sub-hospital-ana-002',
            'name' => 'Dr. Ana Reyes',
            'email' => 'ana.reyes@manilageneral.ph',
            'mobile' => '+639171000001',
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

        // Staff for the other hospitals, so the hospital queue can be shown to
        // be scoped to the signed-in staff member's organization.
        $pghStaff = User::create([
            'egov_sub' => 'egov-sub-hospital-lorna-004',
            'name' => 'Dr. Lorna Bautista',
            'email' => 'lorna.bautista@pgh.gov.ph',
            'role' => 'hospital_staff',
            'organization_id' => $pgh->id,
            'verified_identity' => true,
            'avatar_url' => 'https://ui-avatars.com/api/?name=Lorna+Bautista',
        ]);

        $nktiStaff = User::create([
            'egov_sub' => 'egov-sub-hospital-ramon-005',
            'name' => 'Dr. Ramon Villanueva',
            'email' => 'ramon.villanueva@nkti.gov.ph',
            'role' => 'hospital_staff',
            'organization_id' => $nkti->id,
            'verified_identity' => true,
            'avatar_url' => 'https://ui-avatars.com/api/?name=Ramon+Villanueva',
        ]);

        $stJudeStaff = User::create([
            'egov_sub' => 'egov-sub-hospital-grace-006',
            'name' => 'Dr. Grace Lim',
            'email' => 'grace.lim@stjudehospital.ph',
            'role' => 'hospital_staff',
            'organization_id' => $stJude->id,
            'verified_identity' => true,
            'avatar_url' => 'https://ui-avatars.com/api/?name=Grace+Lim',
        ]);

        $lungCenterStaff = User::create([
            'egov_sub' => 'egov-sub-hospital-vincent-007',
            'name' => 'Dr. Vincent Ocampo',
            'email' => 'vincent.ocampo@lcp.gov.ph',
            'role' => 'hospital_staff',
            'organization_id' => $lungCenter->id,
            'verified_identity' => true,
            'avatar_url' => 'https://ui-avatars.com/api/?name=Vincent+Ocampo',
        ]);

        // 4. Applicant Profile
        ApplicantProfile::create([
            'user_id' => $applicantUser->id,
            'philsys_id' => 'PSN-8192-3049-1829',
            'full_name' => 'JOSIE SANTOS DELA CRUZ',
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
        // F2: all five requirements including the social case study, so Juan's
        // completeness score is 100%.
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
                'title' => 'Certified Statement of Account (₱150,000.00)',
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
            [
                'type' => 'social_case_study',
                'title' => 'Medical Social Case Study Report',
                'status' => 'certified',
                'hash' => 'DOC-HASH-4C3B2A1F0E9D',
                'ref' => 'SCS-MGH-2026-318',
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
                'verified_by_user_id' => in_array($d['type'], ['medical_abstract', 'statement_of_account', 'treatment_order', 'social_case_study']) ? $hospitalStaff->id : null,
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
        // Pre-issued by product decision. Its number is the highest DSWD
        // sequence for the year, so a later approval (nextGuaranteeNumber)
        // continues from 04822 and this letter is never re-issued.
        $guaranteeLetter = GuaranteeLetter::create([
            'gl_number' => 'GL-DSWD-2026-04821',
            'agency_application_id' => $app->id,
            'medical_case_id' => $medicalCase->id,
            'patient_name' => 'JUAN DELA CRUZ SANTOS',
            'applicant_name' => 'JOSIE SANTOS DELA CRUZ',
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
        // Every seeded event goes through EGovChainService::recordEvent() so the
        // demo timeline is a real hash chain carrying payload digests, and
        // GET /api/v1/cases/{case}/timeline/verify does not report the seeded
        // rows as unverifiable.
        $events = [
            ['action' => 'IDENTITY_VERIFIED', 'desc' => 'Identity verified with PhilSys eVerify', 'actor' => $applicantUser],
            ['action' => 'HOSPITAL_REQUEST_SENT', 'desc' => 'Requested official medical records from Manila General Hospital', 'actor' => $applicantUser],
            ['action' => 'DOCUMENTS_CERTIFIED', 'desc' => 'Official medical abstract and billing statement certified by Dr. Ana Reyes', 'actor' => $hospitalStaff],
            ['action' => 'APPLICATION_SUBMITTED', 'desc' => 'Submitted ₱50,000 assistance request to DSWD AICS Program', 'actor' => $applicantUser],
            ['action' => 'APPLICATION_APPROVED', 'desc' => 'Assistance approved for ₱50,000 by Miguel dela Cruz', 'actor' => $agencyEvaluator],
            ['action' => 'GUARANTEE_ISSUED', 'desc' => 'Digital Guarantee Letter GL-DSWD-2026-04821 issued by DSWD NCR', 'actor' => $agencyEvaluator],
        ];

        $chain = app(EGovChainService::class);

        foreach ($events as $e) {
            $chain->recordEvent(
                $medicalCase,
                $e['actor'],
                $e['action'],
                $e['desc'],
                ['seeded' => true, 'gl_number' => $guaranteeLetter->gl_number]
            );
        }

        Notification::create([
            'user_id' => $applicantUser->id,
            'title' => 'Guarantee Letter Issued',
            'message' => 'Your ₱50,000 medical assistance guarantee letter GL-DSWD-2026-04821 has been issued by DSWD NCR.',
            'type' => 'success',
            'reference_type' => 'GuaranteeLetter',
            'reference_id' => $guaranteeLetter->id,
        ]);

        // 11. F2: the rows the portals used to render from hard-coded frontend
        // arrays, now real records.
        $this->seedPortfolioRows(
            $mgh,
            $pgh,
            $nkti,
            $stJude,
            $lungCenter,
            $aicsProgram,
            $agencyEvaluator
        );
    }

    /**
     * F2: hospital queues and agency application rows for the demo portals.
     */
    private function seedPortfolioRows(
        Organization $mgh,
        Organization $pgh,
        Organization $nkti,
        Organization $stJude,
        Organization $lungCenter,
        AgencyProgram $aicsProgram,
        User $agencyEvaluator
    ): void {
        // --- Hospital queue at Manila General Hospital -----------------------
        $lizaApplicant = $this->applicantUser('egov-sub-applicant-liza-101', 'LIZA P. MENDOZA', 'liza.mendoza@yopmail.com', '+639171000101');

        $lizaCase = MedicalCase::create([
            'case_number' => 'MGL-2026-001279',
            'applicant_id' => $lizaApplicant->id,
            'patient_name' => 'Liza P. Mendoza',
            'relationship' => 'Mother',
            'provider_id' => $mgh->id,
            'condition_category' => 'Cesarean section delivery',
            'verified_bill' => 120000.00,
            'treatment_date' => '2026-08-02',
            'status' => 'WAITING_FOR_HOSPITAL_DOCUMENTS',
        ]);

        HospitalDocumentRequest::create([
            'medical_case_id' => $lizaCase->id,
            'hospital_id' => $mgh->id,
            'requested_document_types' => ['medical_abstract', 'statement_of_account'],
            'status' => 'processing',
            'notes' => 'Maternity records under preparation by the medical records unit.',
        ]);

        CaseDocument::create([
            'medical_case_id' => $lizaCase->id,
            'document_type' => 'medical_abstract',
            'title' => 'Official Medical Abstract',
            'storage_path' => "cases/{$lizaCase->id}/medical_abstract.pdf",
            'file_size' => 1843200,
            'status' => 'uploaded',
            'sha256_hash' => 'DOC-HASH-3B2A1F0E9D8C',
            'verification_reference' => 'HSH-DOC-3B2A1F0E',
            'extracted_json' => [
                'patient' => 'Liza P. Mendoza',
                'diagnosis' => 'Full-term pregnancy, cephalopelvic disproportion',
                'disclaimer' => 'AI-generated extraction — subject to authorized staff review.',
            ],
        ]);

        $robertoApplicant = $this->applicantUser('egov-sub-applicant-roberto-102', 'ROBERTO A. GARCIA', 'roberto.garcia@yopmail.com', '+639171000102');

        $robertoCase = MedicalCase::create([
            'case_number' => 'MGL-2026-001265',
            'applicant_id' => $robertoApplicant->id,
            'patient_name' => 'Roberto A. Garcia',
            'relationship' => 'Father',
            'provider_id' => $mgh->id,
            'condition_category' => 'Coronary artery bypass graft',
            'verified_bill' => 480000.00,
            'treatment_date' => '2026-07-29',
            'status' => 'READY_FOR_SUBMISSION',
        ]);

        HospitalDocumentRequest::create([
            'medical_case_id' => $robertoCase->id,
            'hospital_id' => $mgh->id,
            'requested_document_types' => ['medical_abstract', 'statement_of_account', 'treatment_order'],
            'status' => 'certified',
            'notes' => 'Cardiology records certified and anchored by the medical records unit.',
        ]);

        foreach ([
            ['medical_abstract', 'Official Medical Abstract', 'DOC-HASH-2A1F0E9D8C7B', 'HSP-CERT-8821'],
            ['statement_of_account', 'Certified Statement of Account (₱480,000.00)', 'DOC-HASH-1F0E9D8C7B6A', 'SOA-MGH-2026-455'],
            ['treatment_order', 'Physician Treatment Order', 'DOC-HASH-0E9D8C7B6A5F', 'ORD-MGH-2026-118'],
        ] as $d) {
            CaseDocument::create([
                'medical_case_id' => $robertoCase->id,
                'document_type' => $d[0],
                'title' => $d[1],
                'storage_path' => "cases/{$robertoCase->id}/{$d[0]}.pdf",
                'file_size' => 2204800,
                'status' => 'certified',
                'sha256_hash' => $d[2],
                'verification_reference' => $d[3],
                'extracted_json' => [
                    'patient' => 'Roberto A. Garcia',
                    'diagnosis' => 'Triple vessel coronary artery disease',
                    'total_bill' => 480000.00,
                    'disclaimer' => 'AI-generated summary — subject to evaluator review.',
                ],
            ]);
        }

        // --- Agency inbox rows ----------------------------------------------
        $this->seedAgencyApplication(
            $this->applicantUser('egov-sub-applicant-teresa-103', 'TERESA M. RAMOS', 'teresa.ramos@yopmail.com', '+639171000103'),
            $pgh,
            'MGL-2026-001301',
            'Teresa M. Ramos',
            'Self',
            'Cervical cancer chemotherapy',
            210000.00,
            $aicsProgram,
            $agencyEvaluator,
            'submitted',
            80000.00,
            ['id', 'indigency', 'medical_abstract', 'statement_of_account'],
            'Awaiting evaluator review — complete documentary requirements.'
        );

        $this->seedAgencyApplication(
            $this->applicantUser('egov-sub-applicant-eduardo-105', 'EDUARDO V. GOMEZ', 'eduardo.gomez@yopmail.com', '+639171000105'),
            $nkti,
            'MGL-2026-001302',
            'Eduardo V. Gomez',
            'Self',
            'Hemodialysis maintenance',
            96000.00,
            $aicsProgram,
            $agencyEvaluator,
            'needs_info',
            40000.00,
            ['id', 'medical_abstract'],
            'Missing barangay indigency certificate and statement of account.'
        );

        $this->seedAgencyApplication(
            $this->applicantUser('egov-sub-applicant-rosa-104', 'ROSA P. MERCADO', 'rosa.mercado@yopmail.com', '+639171000104'),
            $stJude,
            'MGL-2026-001303',
            'Gabriel P. Mercado',
            'Son of Rosa P. Mercado',
            'Congenital heart defect repair',
            175000.00,
            $aicsProgram,
            $agencyEvaluator,
            'submitted',
            65000.00,
            ['id', 'indigency', 'medical_abstract', 'statement_of_account'],
            'Pediatric cardiac surgery — for immediate evaluation.'
        );

        $this->seedAgencyApplication(
            $this->applicantUser('egov-sub-applicant-carlos-106', 'CARLOS H. MENDOZA', 'carlos.mendoza@yopmail.com', '+639171000106'),
            $lungCenter,
            'MGL-2026-001304',
            'Carlos H. Mendoza',
            'Father',
            'Lung cancer radiation therapy',
            260000.00,
            $aicsProgram,
            $agencyEvaluator,
            'submitted',
            90000.00,
            ['medical_abstract', 'statement_of_account'],
            'Oncology package — awaiting certificate of indigency.'
        );
    }

    /**
     * An applicant identity for one portfolio case. Deliberately NOT the demo
     * applicant, so GET /api/v1/cases still leads with Juan D. Santos.
     */
    private function applicantUser(string $sub, string $name, string $email, ?string $mobile = null): User
    {
        return User::create([
            'egov_sub' => $sub,
            'name' => $name,
            'email' => $email,
            'mobile' => $mobile,
            'role' => 'applicant',
            'verified_identity' => true,
            'avatar_url' => 'https://ui-avatars.com/api/?name=' . urlencode($name),
        ]);
    }

    /**
     * One agency application with its case, provider queue entry and the
     * document set that matches its status.
     */
    private function seedAgencyApplication(
        User $applicant,
        Organization $hospital,
        string $caseNumber,
        string $patientName,
        string $relationship,
        string $condition,
        float $verifiedBill,
        AgencyProgram $program,
        User $evaluator,
        string $status,
        float $requestedAmount,
        array $documentTypes,
        string $notes
    ): AgencyApplication {
        $case = MedicalCase::create([
            'case_number' => $caseNumber,
            'applicant_id' => $applicant->id,
            'patient_name' => $patientName,
            'relationship' => $relationship,
            'provider_id' => $hospital->id,
            'condition_category' => $condition,
            'verified_bill' => $verifiedBill,
            'treatment_date' => now()->subDays(14)->format('Y-m-d'),
            'status' => 'UNDER_AGENCY_REVIEW',
        ]);

        $documents = [
            'id' => ['PhilSys Digital ID', 'verified', 'DOC-HASH-9A0F1E2D3C4B', 'EVR-8F2A-19C0-2026'],
            'indigency' => ['Barangay Certificate of Indigency', 'verified', 'DOC-HASH-8B7C6D5E4F3A', 'BRGY-MANILA-2026-99'],
            'medical_abstract' => ['Official Medical Abstract', 'certified', 'DOC-HASH-7F6E5D4C3B2A', 'HSP-CERT-8812'],
            'statement_of_account' => ['Certified Statement of Account', 'certified', 'DOC-HASH-6E5D4C3B2A1F', 'SOA-2026-441'],
            'treatment_order' => ['Physician Treatment Order', 'certified', 'DOC-HASH-5D4C3B2A1F0E', 'ORD-2026-102'],
        ];

        foreach ($documentTypes as $type) {
            [$title, $docStatus, $hash, $ref] = $documents[$type];

            CaseDocument::create([
                'medical_case_id' => $case->id,
                'document_type' => $type,
                'title' => $title,
                'storage_path' => "cases/{$case->id}/{$type}.pdf",
                'file_size' => 1600000,
                'status' => $docStatus,
                'sha256_hash' => $hash,
                'verification_reference' => $ref,
                'extracted_json' => [
                    'patient' => $patientName,
                    'diagnosis' => $condition,
                    'disclaimer' => 'AI-generated extraction — subject to authorized staff review.',
                ],
            ]);
        }

        HospitalDocumentRequest::create([
            'medical_case_id' => $case->id,
            'hospital_id' => $hospital->id,
            'requested_document_types' => ['medical_abstract', 'statement_of_account'],
            'status' => 'certified',
            'notes' => $notes,
        ]);

        return AgencyApplication::create([
            'medical_case_id' => $case->id,
            'agency_program_id' => $program->id,
            'requested_amount' => $requestedAmount,
            'approved_amount' => 0.00,
            'status' => $status,
            'decision_reason' => $status === 'needs_info'
                ? 'Additional documentary requirements needed before evaluation.'
                : null,
            'remarks' => $notes,
            'validity_days' => 30,
            'evaluator_id' => $status === 'needs_info' ? $evaluator->id : null,
        ]);
    }
}
