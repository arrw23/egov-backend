<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\CaseCalculationService;
use App\Domain\CaseStateMachineService;
use App\Http\Controllers\Controller;
use App\Models\AgencyApplication;
use App\Models\AgencyProgram;
use App\Models\CaseDocument;
use App\Models\HospitalDocumentRequest;
use App\Models\MedicalCase;
use App\Models\Organization;
use App\Services\EGov\EGovAIService;
use App\Services\EGov\EGovChainService;
use App\Services\EGov\EMessageService;
use App\Services\EGov\EReportService;
use App\Services\EGov\MockEGovIdentityProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ApplicantCaseController extends Controller
{
    public function index(): JsonResponse
    {
        $user = Auth::user() ?: (new MockEGovIdentityProvider())->resolveUser('applicant');

        $cases = MedicalCase::with(['provider', 'documents', 'hospitalRequests', 'agencyApplications.agencyProgram.agency', 'guaranteeLetters'])
            ->where('applicant_id', $user->id)
            ->get();

        $calcService = new CaseCalculationService();

        $mapped = $cases->map(function (MedicalCase $case) use ($calcService) {
            $financials = $calcService->calculate($case);
            return [
                'id' => $case->id,
                'case_number' => $case->case_number,
                'patient_name' => $case->patient_name,
                'relationship' => $case->relationship,
                'provider' => $case->provider ? [
                    'id' => $case->provider->id,
                    'name' => $case->provider->name,
                    'code' => $case->provider->code,
                ] : null,
                'condition_category' => $case->condition_category,
                'status' => $case->status,
                'financials' => $financials,
                'created_at' => $case->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'status' => 'success',
            'cases' => $mapped,
        ]);
    }

    public function store(Request $request, CaseStateMachineService $stateMachine, EGovChainService $chain): JsonResponse
    {
        $request->validate([
            'patient_name' => 'required|string',
            'relationship' => 'required|string',
            'provider_id' => 'required|exists:organizations,id',
            'condition_category' => 'required|string',
            'estimated_bill' => 'required|numeric|min:0',
            'treatment_date' => 'nullable|date',
        ]);

        $user = Auth::user() ?: (new MockEGovIdentityProvider())->resolveUser('applicant');

        $caseNumber = 'MGL-2026-' . rand(100000, 999999);

        $case = MedicalCase::create([
            'case_number' => $caseNumber,
            'applicant_id' => $user->id,
            'patient_name' => $request->input('patient_name'),
            'relationship' => $request->input('relationship'),
            'provider_id' => $request->input('provider_id'),
            'condition_category' => $request->input('condition_category'),
            'verified_bill' => $request->input('estimated_bill'),
            'treatment_date' => $request->input('treatment_date', now()->format('Y-m-d')),
            'status' => CaseStateMachineService::DRAFT,
        ]);

        $chain->recordEvent(
            $case,
            $user,
            'CASE_CREATED',
            "Medical assistance case {$caseNumber} created for {$case->patient_name}",
            ['case_number' => $caseNumber]
        );

        try {
            app(EMessageService::class)->send(
                $user,
                'Case Created',
                "Your medical assistance case {$caseNumber} has been successfully created.",
                'info',
                'MedicalCase',
                $case->id
            );
            app(EReportService::class)->submitAuditReport('CASE_CREATED', ['case_id' => $case->id], $user);
        } catch (\Exception $e) {
            // Ignore error
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Medical assistance case created successfully.',
            'case' => $case->load(['provider']),
        ]);
    }

    public function show(MedicalCase $case, CaseCalculationService $calcService, EGovAIService $aiService): JsonResponse
    {
        $case->load([
            'provider',
            'documents',
            'hospitalRequests',
            'agencyApplications.agencyProgram.agency',
            'agencyApplications.guaranteeLetter',
            'guaranteeLetters.utilizations',
            'auditEvents',
        ]);

        $financials = $calcService->calculate($case);
        $aiSummary = $aiService->generateCaseSummary($case);

        return response()->json([
            'status' => 'success',
            'case' => [
                'id' => $case->id,
                'case_number' => $case->case_number,
                'patient_name' => $case->patient_name,
                'relationship' => $case->relationship,
                'provider' => $case->provider,
                'condition_category' => $case->condition_category,
                'verified_bill' => (float) $case->verified_bill,
                'status' => $case->status,
                'financials' => $financials,
                'documents' => $case->documents,
                'hospital_requests' => $case->hospitalRequests,
                'agency_applications' => $case->agencyApplications,
                'guarantee_letters' => $case->guaranteeLetters,
                'audit_events' => $case->auditEvents,
                'ai_summary' => $aiSummary,
            ],
        ]);
    }

    public function uploadDocument(Request $request, MedicalCase $case, EGovAIService $aiService, EGovChainService $chain): JsonResponse
    {
        $request->validate([
            'document_type' => 'required|string',
            'title' => 'required|string',
        ]);

        $docType = $request->input('document_type');
        $title = $request->input('title');
        $content = "SIMULATED_CONTENT_" . time() . '_' . $docType;

        $hash = $chain->generateDocumentHash($content);
        $aiResult = $aiService->classifyAndExtract($title, $docType);

        $doc = CaseDocument::create([
            'medical_case_id' => $case->id,
            'document_type' => $docType,
            'title' => $title,
            'storage_path' => "cases/{$case->id}/{$docType}.pdf",
            'file_size' => 1024 * rand(500, 2500),
            'status' => 'verified',
            'sha256_hash' => $hash,
            'verification_reference' => 'VER-DOC-' . strtoupper(substr(md5($hash), 0, 8)),
            'extracted_json' => $aiResult,
        ]);

        $user = Auth::user() ?: (new MockEGovIdentityProvider())->resolveUser('applicant');
        $chain->recordEvent(
            $case,
            $user,
            'DOCUMENT_UPLOADED',
            "Uploaded applicant document: {$title}",
            ['document_id' => $doc->id, 'hash' => $hash]
        );

        try {
            app(EReportService::class)->submitAuditReport('DOCUMENT_UPLOADED', ['document_id' => $doc->id], $user);
        } catch (\Exception $e) {
            // Ignore error
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Document uploaded, classified by eGov AI, and recorded on eGovChain timeline.',
            'document' => $doc,
        ]);
    }

    public function requestHospitalDocuments(Request $request, MedicalCase $case, CaseStateMachineService $stateMachine, EMessageService $eMessage, EGovChainService $chain): JsonResponse
    {
        $hospitalReq = HospitalDocumentRequest::create([
            'medical_case_id' => $case->id,
            'hospital_id' => $case->provider_id,
            'requested_document_types' => ['medical_abstract', 'statement_of_account', 'treatment_order'],
            'status' => 'pending',
            'notes' => 'Direct provider certification request initiated by citizen.',
        ]);

        if ($stateMachine->canTransition($case->status, CaseStateMachineService::WAITING_FOR_HOSPITAL_DOCUMENTS)) {
            $stateMachine->transition($case, CaseStateMachineService::WAITING_FOR_HOSPITAL_DOCUMENTS);
        }

        $user = Auth::user() ?: (new MockEGovIdentityProvider())->resolveUser('applicant');

        // Notify Hospital Staff
        $hospitalStaff = (new MockEGovIdentityProvider())->resolveUser('hospital');
        try {
            $eMessage->send(
                $hospitalStaff,
                'New Hospital Record Request',
                "Applicant {$user->name} requested certified records for case {$case->case_number}.",
                'info',
                'MedicalCase',
                $case->id
            );
        } catch (\Exception $e) {
            // Ignore error
        }

        $chain->recordEvent(
            $case,
            $user,
            'HOSPITAL_REQUEST_SENT',
            "Requested official certified medical records from {$case->provider->name}",
            ['request_id' => $hospitalReq->id]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Hospital document request sent successfully.',
            'request' => $hospitalReq,
        ]);
    }

    public function providers(): JsonResponse
    {
        $providers = Organization::where('type', 'hospital')->get();
        return response()->json([
            'status' => 'success',
            'providers' => $providers,
        ]);
    }

    public function agencyPrograms(): JsonResponse
    {
        $programs = AgencyProgram::with('agency')->get();
        return response()->json([
            'status' => 'success',
            'programs' => $programs,
        ]);
    }

    public function submitAgencyApplication(Request $request, MedicalCase $case, CaseStateMachineService $stateMachine, EMessageService $eMessage, EGovChainService $chain): JsonResponse
    {
        $request->validate([
            'agency_program_id' => 'required|exists:agency_programs,id',
            'requested_amount' => 'required|numeric|min:1',
            'consent_sharing' => 'required|accepted',
        ]);

        $app = AgencyApplication::create([
            'medical_case_id' => $case->id,
            'agency_program_id' => $request->input('agency_program_id'),
            'requested_amount' => $request->input('requested_amount'),
            'approved_amount' => 0.00,
            'status' => 'submitted',
            'validity_days' => 30,
        ]);

        if ($stateMachine->canTransition($case->status, CaseStateMachineService::UNDER_AGENCY_REVIEW)) {
            $stateMachine->transition($case, CaseStateMachineService::UNDER_AGENCY_REVIEW);
        }

        $user = Auth::user() ?: (new MockEGovIdentityProvider())->resolveUser('applicant');
        $evaluator = (new MockEGovIdentityProvider())->resolveUser('agency');

        try {
            $eMessage->send(
                $evaluator,
                'New Assistance Application Received',
                "New medical assistance request {$case->case_number} submitted for review.",
                'info',
                'AgencyApplication',
                $app->id
            );
        } catch (\Exception $e) {
            // Ignore error
        }

        $chain->recordEvent(
            $case,
            $user,
            'APPLICATION_SUBMITTED',
            "Submitted ₱" . number_format($app->requested_amount, 2) . " assistance request to agency program.",
            ['application_id' => $app->id]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Application submitted successfully to agency.',
            'application' => $app->load('agencyProgram.agency'),
        ]);
    }

    public function timeline(MedicalCase $case): JsonResponse
    {
        $events = $case->auditEvents()->orderBy('created_at', 'asc')->get();
        return response()->json([
            'status' => 'success',
            'timeline' => $events,
        ]);
    }
}
