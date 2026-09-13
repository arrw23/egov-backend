<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\CaseCalculationService;
use App\Domain\CaseStateMachineService;
use App\Http\Controllers\Controller;
use App\Models\AgencyApplication;
use App\Models\GuaranteeLetter;
use App\Models\MedicalCase;
use App\Models\User;
use App\Services\EGov\EGovAIService;
use App\Services\EGov\EGovChainService;
use App\Services\EGov\EMessageService;
use App\Services\EGov\EReportService;
use App\Services\EGov\MockEGovIdentityProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

        $isApproval = in_array($action, ['approve', 'partially_approve'], true);

        // B6: idempotent re-approval. The relationship is hasOne, so an
        // application that already has a guarantee letter returns that letter
        // instead of minting another one. This check precedes the status gate
        // so a repeated approve is a no-op rather than an error.
        if ($isApproval) {
            $existingGl = GuaranteeLetter::where('agency_application_id', $application->id)->first();

            if ($existingGl) {
                return response()->json([
                    'status' => 'success',
                    'message' => "Guarantee Letter {$existingGl->gl_number} was already issued for this application.",
                    'application' => $application,
                    'guarantee_letter' => $existingGl,
                    'sms' => null,
                ]);
            }
        }

        // B6: a decision is only meaningful on an application that is actually
        // awaiting one. Previously an approved or denied application could be
        // decided again (minting another GL every time).
        if (! in_array($application->status, ['submitted', 'needs_info'], true)) {
            return response()->json([
                'status' => 'error',
                'message' => "This application is '{$application->status}' and can no longer be decided.",
            ], 409);
        }

        if ($isApproval) {
            $appStatus = ($action === 'approve') ? 'approved' : 'partially_approved';
            $caseNextState = ($action === 'approve') ? CaseStateMachineService::APPROVED : CaseStateMachineService::PARTIALLY_APPROVED;

            // B6: an approval whose case cannot legally leave
            // UNDER_AGENCY_REVIEW is refused loudly instead of issuing a GL and
            // leaving the case status stale.
            if (! $stateMachine->canTransition($case->status, $caseNextState)) {
                return response()->json([
                    'status' => 'error',
                    'message' => "The case is '{$case->status}' and cannot move to '{$caseNextState}'.",
                ], 409);
            }

            $program = $application->agencyProgram;
            $uncovered = (float) $calcService->calculate($case)['remaining_uncovered_balance'];
            $ceiling = min(
                (float) $application->requested_amount,
                (float) ($program->max_assistance_amount ?? PHP_FLOAT_MAX),
                $uncovered
            );

            if ($approvedAmount > $ceiling) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Approved amount ₱' . number_format($approvedAmount, 2)
                        . ' exceeds the approvable amount of ₱' . number_format($ceiling, 2) . '.',
                    'max_approvable' => round($ceiling, 2),
                ], 422);
            }

            $result = DB::transaction(function () use ($application, $case, $approvedAmount, $appStatus, $caseNextState, $reason, $remarks, $validityDays, $evaluator, $stateMachine) {
                // B6: the relationship is hasOne, so an application that already
                // has a guarantee letter must return it, never mint a second one.
                $existing = GuaranteeLetter::where('agency_application_id', $application->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return ['existing' => true, 'gl' => $existing];
                }

                $application->update([
                    'approved_amount' => $approvedAmount,
                    'status' => $appStatus,
                    'decision_reason' => $reason,
                    'remarks' => $remarks,
                    'validity_days' => $validityDays,
                    'evaluator_id' => $evaluator->id,
                ]);

                $stateMachine->transition($case, $caseNextState);

                $agency = $application->agencyProgram?->agency;
                $shortCode = $agency?->short_code ?: ($agency?->code ?: 'AGENCY');

                // Sequence is computed inside the transaction so two evaluators
                // approving at once cannot collide on the unique gl_number.
                $glNumber = self::nextGuaranteeNumber($shortCode);
                $issueDate = now();
                $expDate = now()->addDays($validityDays);
                $chainRef = 'EGC-' . strtoupper(substr(md5($glNumber . microtime()), 0, 12));

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
                    // B6: the signatory comes from the issuing organization.
                    'digital_signatory_name' => $agency?->signatory_name ?: 'Authorized Signatory',
                    'digital_signatory_role' => $agency?->signatory_role
                        ?: ('Authorized Representative · ' . ($agency?->name ?? 'Issuing Agency')),
                    'qr_payload' => $qrPayload,
                    'chain_reference' => $chainRef,
                    'status' => 'valid',
                ]);

                if ($stateMachine->canTransition($case->status, CaseStateMachineService::GUARANTEE_LETTER_ISSUED)) {
                    $stateMachine->transition($case, CaseStateMachineService::GUARANTEE_LETTER_ISSUED);
                }

                // B6: notify the case's ACTUAL provider staff, not a mocked
                // 'hospital' identity that may belong to another hospital.
                $providerStaff = User::where('organization_id', $case->provider_id)
                    ->where('role', 'hospital_staff')
                    ->orderBy('id')
                    ->first();

                return [
                    'existing' => false,
                    'gl' => $gl,
                    'chain_reference' => $chainRef,
                    'provider_staff' => $providerStaff,
                ];
            });

            /** @var GuaranteeLetter $gl */
            $gl = $result['gl'];

            // Re-approving returns the letter that already exists — no second
            // GL, no second round of notifications.
            if ($result['existing']) {
                return response()->json([
                    'status' => 'success',
                    'message' => "Guarantee Letter {$gl->gl_number} was already issued for this application.",
                    'application' => $application->fresh(),
                    'guarantee_letter' => $gl,
                    'sms' => null,
                ]);
            }

            $glNumber = $gl->gl_number;

            try {
                $chain->anchorGuaranteeLetter($gl, $evaluator);
            } catch (\Exception $e) {
                // Anchoring must not block issuance.
            }

            $chain->recordEvent(
                $case,
                $evaluator,
                'GUARANTEE_ISSUED',
                "Agency evaluator {$evaluator->name} approved ₱" . number_format($approvedAmount, 2) . " and issued Guarantee Letter {$glNumber}.",
                ['gl_number' => $glNumber, 'chain_reference' => $result['chain_reference']]
            );

            // B7: ONE notification for the applicant, and it also pushes the
            // SMS (send(..., true)) — with the real dispatch result returned so
            // the UI no longer fires its own SMS.
            $smsResult = $this->notifyApplicantOfGuarantee(
                $eMessage,
                $case->applicant,
                $glNumber,
                $approvedAmount,
                $gl->id
            );

            if (! empty($result['provider_staff'])) {
                $eMessage->send(
                    $result['provider_staff'],
                    'Guarantee Letter Issued for Patient',
                    "Guarantee Letter {$glNumber} (₱" . number_format($approvedAmount, 2) . ") issued for patient {$case->patient_name}.",
                    'info',
                    'GuaranteeLetter',
                    $gl->id
                );
            }

            try {
                app(EReportService::class)->submitAuditReport('GUARANTEE_ISSUED', ['gl_number' => $glNumber], $evaluator);
            } catch (\Exception $e) {
                // Reporting must not block issuance.
            }

            return response()->json([
                'status' => 'success',
                'message' => "Guarantee Letter {$glNumber} successfully generated and issued.",
                'application' => $application->fresh(),
                'guarantee_letter' => $gl,
                'sms' => $smsResult,
            ]);

        } elseif ($action === 'deny') {
            // B6: the same transition guard as approval, instead of silently
            // skipping the state machine and leaving the case status stale.
            if (! $stateMachine->canTransition($case->status, CaseStateMachineService::DENIED)) {
                return response()->json([
                    'status' => 'error',
                    'message' => "The case is '{$case->status}' and cannot move to '" . CaseStateMachineService::DENIED . "'.",
                ], 409);
            }

            $application->update([
                'status' => 'denied',
                'decision_reason' => $reason,
                'remarks' => $remarks,
                'evaluator_id' => $evaluator->id,
            ]);

            $stateMachine->transition($case, CaseStateMachineService::DENIED);

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
            if (! $stateMachine->canTransition($case->status, CaseStateMachineService::NEEDS_INFORMATION)) {
                return response()->json([
                    'status' => 'error',
                    'message' => "The case is '{$case->status}' and cannot move to '" . CaseStateMachineService::NEEDS_INFORMATION . "'.",
                ], 409);
            }

            $application->update([
                'status' => 'needs_info',
                'decision_reason' => $reason,
                'remarks' => $remarks,
                'evaluator_id' => $evaluator->id,
            ]);

            $stateMachine->transition($case, CaseStateMachineService::NEEDS_INFORMATION);

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

    /**
     * B7: notify the applicant exactly once and, when they have a mobile
     * number, push the SMS through eMessage with $sms = true. The gateway
     * result is captured so the UI can render the real dispatch outcome
     * instead of sending an SMS of its own.
     *
     * EMessageService::send() performs the push internally and returns only
     * the Notification, so a thin subclass records the pushSms() result while
     * still sending a single SMS.
     */
    private function notifyApplicantOfGuarantee(EMessageService $eMessage, ?User $applicant, string $glNumber, float $approvedAmount, int $glId): ?array
    {
        if (! $applicant) {
            return null;
        }

        $title = 'Guarantee Letter Issued!';
        $message = "Your medical assistance of ₱" . number_format($approvedAmount, 2)
            . " has been approved. Guarantee Letter {$glNumber} is ready.";

        // sendWithReceipt() sends exactly one SMS (only when the user has a
        // mobile) and hands back the real provider receipt.
        $result = $eMessage->sendWithReceipt($applicant, $title, $message, 'success', 'GuaranteeLetter', $glId, true);

        return $result['sms'];
    }

    /**
     * B6: GL numbers are 'GL-<agency short code>-<year>-<5-digit sequence>',
     * where the sequence is (max existing sequence for that agency and year) + 1.
     *
     * Must be called inside the issuing transaction: the LIKE query locks the
     * agency's existing letters so concurrent approvals cannot pick the same
     * sequence and collide on the unique gl_number index.
     */
    private static function nextGuaranteeNumber(string $shortCode): string
    {
        $prefix = sprintf('GL-%s-%d-', $shortCode, now()->year);

        $existing = GuaranteeLetter::where('gl_number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->pluck('gl_number');

        $maxSequence = $existing
            ->map(fn (string $number) => (int) substr($number, strlen($prefix)))
            ->max() ?? 0;

        $next = $maxSequence + 1;

        // Defensive: skip any number that is somehow already taken.
        while (GuaranteeLetter::where('gl_number', $prefix . sprintf('%05d', $next))->exists()) {
            $next++;
        }

        return $prefix . sprintf('%05d', $next);
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
