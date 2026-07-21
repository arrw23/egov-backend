<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\CaseCalculationService;
use App\Domain\CaseStateMachineService;
use App\Http\Controllers\Controller;
use App\Models\AgencyApplication;
use App\Models\GuaranteeLetter;
use App\Models\MedicalCase;
use App\Services\EGov\EGovAIService;
use App\Services\EGov\EGovChainService;
use App\Services\EGov\EMessageService;
use App\Services\EGov\MockEGovIdentityProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AgencyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $statusFilter = $request->query('status');

        $query = AgencyApplication::with([
            'medicalCase.applicant',
            'medicalCase.provider',
            'medicalCase.documents',
            'agencyProgram.agency',
            'guaranteeLetter',
        ])->orderBy('created_at', 'desc');

        if ($statusFilter) {
            $query->where('status', $statusFilter);
        }

        $applications = $query->get();
        $calcService = new CaseCalculationService();

        $mapped = $applications->map(function (AgencyApplication $app) use ($calcService) {
            $financials = $calcService->calculate($app->medicalCase);
            return [
                'id' => $app->id,
                'medical_case_id' => $app->medical_case_id,
                'case_number' => $app->medicalCase->case_number,
                'patient_name' => $app->medicalCase->patient_name,
                'applicant_name' => $app->medicalCase->applicant->name,
                'hospital_name' => $app->medicalCase->provider->name ?? 'Hospital Provider',
                'program_name' => $app->agencyProgram->name ?? 'AICS Assistance',
                'requested_amount' => (float) $app->requested_amount,
                'approved_amount' => (float) $app->approved_amount,
                'verified_bill' => (float) $app->medicalCase->verified_bill,
                'status' => $app->status,
                'financials' => $financials,
                'created_at' => $app->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'status' => 'success',
            'applications' => $mapped,
        ]);
    }

    public function show(AgencyApplication $application, CaseCalculationService $calcService, EGovAIService $aiService): JsonResponse
    {
        $application->load([
            'medicalCase.applicant.applicantProfile',
            'medicalCase.provider',
            'medicalCase.documents',
            'medicalCase.auditEvents',
            'agencyProgram.agency',
            'guaranteeLetter',
        ]);

        $financials = $calcService->calculate($application->medicalCase);
        $aiSummary = $aiService->generateCaseSummary($application->medicalCase);

        return response()->json([
            'status' => 'success',
            'application' => [
                'id' => $application->id,
                'medical_case' => $application->medicalCase,
                'agency_program' => $application->agencyProgram,
                'requested_amount' => (float) $application->requested_amount,
                'approved_amount' => (float) $application->approved_amount,
                'status' => $application->status,
                'decision_reason' => $application->decision_reason,
                'remarks' => $application->remarks,
                'financials' => $financials,
                'ai_summary' => $aiSummary,
                'guarantee_letter' => $application->guaranteeLetter,
            ],
        ]);
    }

    public function generateSummary(AgencyApplication $application, EGovAIService $aiService): JsonResponse
    {
        $aiSummary = $aiService->generateCaseSummary($application->medicalCase);
        return response()->json([
            'status' => 'success',
            'summary' => $aiSummary,
        ]);
    }

    public function decision(Request $request, AgencyApplication $application, CaseStateMachineService $stateMachine, CaseCalculationService $calcService, EMessageService $eMessage, EGovChainService $chain): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:approve,partially_approve,deny,needs_info',
            'approved_amount' => 'required_if:action,approve,partially_approve|numeric|min:0',
            'reason' => 'required|string',
            'validity_days' => 'nullable|integer|min:1|max:365',
            'remarks' => 'nullable|string',
        ]);

        $evaluator = Auth::user() ?: (new MockEGovIdentityProvider())->resolveUser('agency');
        $action = $request->input('action');
        $reason = $request->input('reason');
        $remarks = $request->input('remarks', '');
        $validityDays = (int) $request->input('validity_days', 30);
        $approvedAmount = (float) $request->input('approved_amount', 0);

        $case = $application->medicalCase;

        if ($action === 'approve' || $action === 'partially_approve') {
            $appStatus = ($action === 'approve') ? 'approved' : 'partially_approved';
            $caseNextState = ($action === 'approve') ? CaseStateMachineService::APPROVED : CaseStateMachineService::PARTIALLY_APPROVED;

            $application->update([
                'approved_amount' => $approvedAmount,
                'status' => $appStatus,
                'decision_reason' => $reason,
                'remarks' => $remarks,
                'validity_days' => $validityDays,
                'evaluator_id' => $evaluator->id,
            ]);

            if ($stateMachine->canTransition($case->status, $caseNextState)) {
                $stateMachine->transition($case, $caseNextState);
            }

            // Create Guarantee Letter
            $glNumber = 'GL-DSWD-2026-' . rand(10000, 99999);
            $issueDate = now();
            $expDate = now()->addDays($validityDays);
            $chainRef = 'EGC-' . strtoupper(substr(md5($glNumber . time()), 0, 12));

            $qrPayload = json_encode([
                'gl_number' => $glNumber,
                'patient' => $case->patient_name,
                'amount' => $approvedAmount,
                'provider' => $case->provider->name ?? 'Hospital',
                'valid_until' => $expDate->format('Y-m-d'),
                'chain_ref' => $chainRef,
            ]);

            $gl = GuaranteeLetter::create([
                'gl_number' => $glNumber,
                'agency_application_id' => $application->id,
                'medical_case_id' => $case->id,
                'patient_name' => $case->patient_name,
                'applicant_name' => $case->applicant->name,
                'hospital_name' => $case->provider->name ?? 'Hospital Provider',
                'approved_amount' => $approvedAmount,
                'covered_service' => $case->condition_category . ' and related confinement',
                'issue_date' => $issueDate,
                'expiration_date' => $expDate,
                'digital_signatory_name' => 'ELENA P. ROBLES',
                'digital_signatory_role' => 'Regional Director · DSWD NCR',
                'qr_payload' => $qrPayload,
                'chain_reference' => $chainRef,
                'status' => 'valid',
            ]);

            if ($stateMachine->canTransition($case->status, CaseStateMachineService::GUARANTEE_LETTER_ISSUED)) {
                $stateMachine->transition($case, CaseStateMachineService::GUARANTEE_LETTER_ISSUED);
            }

            // Notify Applicant & Hospital
            $eMessage->send(
                $case->applicant,
                'Guarantee Letter Issued!',
                "Your medical assistance of ₱" . number_format($approvedAmount, 2) . " has been approved. Guarantee Letter {$glNumber} is ready.",
                'success',
                'GuaranteeLetter',
                $gl->id
            );

            $hospitalStaff = (new MockEGovIdentityProvider())->resolveUser('hospital');
            $eMessage->send(
                $hospitalStaff,
                'Guarantee Letter Issued for Patient',
                "Guarantee Letter {$glNumber} (₱" . number_format($approvedAmount, 2) . ") issued for patient {$case->patient_name}.",
                'info',
                'GuaranteeLetter',
                $gl->id
            );

            $chain->recordEvent(
                $case,
                $evaluator,
                'GUARANTEE_ISSUED',
                "Agency evaluator {$evaluator->name} approved ₱" . number_format($approvedAmount, 2) . " and issued Guarantee Letter {$glNumber}.",
                ['gl_number' => $glNumber, 'chain_reference' => $chainRef]
            );

            return response()->json([
                'status' => 'success',
                'message' => "Guarantee Letter {$glNumber} successfully generated and issued.",
                'application' => $application,
                'guarantee_letter' => $gl,
            ]);

        } elseif ($action === 'deny') {
            $application->update([
                'status' => 'denied',
                'decision_reason' => $reason,
                'remarks' => $remarks,
                'evaluator_id' => $evaluator->id,
            ]);

            if ($stateMachine->canTransition($case->status, CaseStateMachineService::DENIED)) {
                $stateMachine->transition($case, CaseStateMachineService::DENIED);
            }

            $eMessage->send(
                $case->applicant,
                'Application Decision Notice',
                "Your application {$case->case_number} was reviewed. Decision: Denied ({$reason}).",
                'warning',
                'AgencyApplication',
                $application->id
            );

            $chain->recordEvent(
                $case,
                $evaluator,
                'APPLICATION_DENIED',
                "Application denied by evaluator: {$reason}",
                ['application_id' => $application->id]
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Application decision recorded as Denied.',
                'application' => $application,
            ]);

        } else { // needs_info
            $application->update([
                'status' => 'needs_info',
                'decision_reason' => $reason,
                'remarks' => $remarks,
                'evaluator_id' => $evaluator->id,
            ]);

            if ($stateMachine->canTransition($case->status, CaseStateMachineService::NEEDS_INFORMATION)) {
                $stateMachine->transition($case, CaseStateMachineService::NEEDS_INFORMATION);
            }

            $eMessage->send(
                $case->applicant,
                'Additional Information Required',
                "Agency evaluator requested updates for case {$case->case_number}: {$reason}",
                'info',
                'AgencyApplication',
                $application->id
            );

            $chain->recordEvent(
                $case,
                $evaluator,
                'INFORMATION_REQUESTED',
                "Evaluator requested additional information: {$reason}",
                ['application_id' => $application->id]
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Additional information requested from applicant.',
                'application' => $application,
            ]);
        }
    }

    public function showGuarantee(GuaranteeLetter $guarantee, CaseCalculationService $calcService): JsonResponse
    {
        $guarantee->load([
            'medicalCase.applicant',
            'medicalCase.provider',
            'agencyApplication.agencyProgram.agency',
            'utilizations',
        ]);

        $financials = $calcService->calculateGuarantee($guarantee);

        return response()->json([
            'status' => 'success',
            'guarantee' => [
                'id' => $guarantee->id,
                'gl_number' => $guarantee->gl_number,
                'patient_name' => $guarantee->patient_name,
                'applicant_name' => $guarantee->applicant_name,
                'hospital_name' => $guarantee->hospital_name,
                'issuing_agency' => $guarantee->agencyApplication->agencyProgram->agency->name ?? 'DSWD NCR',
                'program_name' => $guarantee->agencyApplication->agencyProgram->name ?? 'AICS Program',
                'covered_service' => $guarantee->covered_service,
                'issue_date' => $guarantee->issue_date->format('Y-m-d'),
                'expiration_date' => $guarantee->expiration_date->format('Y-m-d'),
                'approved_amount' => (float) $guarantee->approved_amount,
                'utilized_amount' => $financials['utilized_amount'],
                'remaining_value' => $financials['remaining_value'],
                'digital_signatory_name' => $guarantee->digital_signatory_name,
                'digital_signatory_role' => $guarantee->digital_signatory_role,
                'qr_payload' => $guarantee->qr_payload,
                'chain_reference' => $guarantee->chain_reference,
                'status' => $guarantee->status,
                'utilizations' => $guarantee->utilizations,
            ],
        ]);
    }
}
