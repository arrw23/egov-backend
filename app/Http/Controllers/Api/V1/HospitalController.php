<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\CaseCalculationService;
use App\Domain\CaseStateMachineService;
use App\Http\Controllers\Controller;
use App\Models\CaseDocument;
use App\Models\GuaranteeLetter;
use App\Models\GuaranteeUtilization;
use App\Models\HospitalDocumentRequest;
use App\Models\MedicalCase;
use App\Services\EGov\EGovAIService;
use App\Services\EGov\EGovChainService;
use App\Services\EGov\EGovPayService;
use App\Services\EGov\EMessageService;
use App\Services\EGov\MockEGovIdentityProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HospitalController extends Controller
{
    public function pendingRequests(): JsonResponse
    {
        $staff = Auth::user() ?: (new MockEGovIdentityProvider())->resolveUser('hospital');

        // B4: the queue is hospital-scoped. Previously every staff member saw
        // every hospital's document requests.
        $requests = HospitalDocumentRequest::with(['medicalCase.applicant', 'hospital'])
            ->where('hospital_id', $staff->organization_id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'requests' => $requests,
        ]);
    }

    /**
     * The route parameter is {docReq}; implicit binding matches on the
     * parameter *name*, so this argument must be named $docReq. Named
     * $request, the container injected the HTTP request instead and
     * $docReq->medicalCase was null, which 500'd in generateCaseSummary().
     */
    public function showRequest(HospitalDocumentRequest $docReq): JsonResponse
    {
        $docReq->load(['medicalCase.applicant', 'medicalCase.documents', 'hospital']);

        $aiService = new EGovAIService();
        $aiSummary = $aiService->generateCaseSummary($docReq->medicalCase);

        return response()->json([
            'status' => 'success',
            'request' => $docReq,
            'ai_extraction' => $aiSummary,
        ]);
    }

    /**
     * Certifies the records the hospital was asked for.
     *
     * B3: this used to unconditionally CREATE three brand-new documents with a
     * fabricated content hash on every call, so each click duplicated the
     * case's records, the citizen's already-uploaded (hashed) files were never
     * certified, and nothing was ever anchored. Now:
     *   - an existing, not-yet-certified document of the requested type is
     *     certified in place and anchored,
     *   - a requested type with no document behind it is reported in
     *     `missing[]` instead of being invented,
     *   - citizen-uploaded hashed documents are certified where applicable.
     */
    public function submitDocuments(Request $request, HospitalDocumentRequest $docReq, EGovAIService $aiService, EGovChainService $chain, CaseStateMachineService $stateMachine): JsonResponse
    {
        $case = $docReq->medicalCase;
        $staff = Auth::user() ?: (new MockEGovIdentityProvider())->resolveUser('hospital');

        $certifiedDocs = [];
        $missing = [];

        // The real billed amount, never a hard-coded "(₱150,000)".
        $billedAmount = (float) ($case->verified_bill ?? 0);

        $requestedTypes = array_values(array_unique(array_filter(
            (array) ($docReq->requested_document_types ?? [])
        )));

        foreach ($requestedTypes as $docType) {
            // Explicitly requested type first, then any other document of the
            // same type already on the case (e.g. one the citizen uploaded and
            // hashed). Nothing new is ever created here.
            $document = CaseDocument::where('medical_case_id', $case->id)
                ->where('document_type', $docType)
                ->orderByRaw('CASE WHEN status != ? THEN 0 ELSE 1 END', ['certified'])
                ->orderBy('id')
                ->first();

            if (! $document) {
                $missing[] = $docType;
                continue;
            }

            $certifiedDocs[] = $this->certifyAndAnchorDocument($document, $staff, $aiService, $chain, $billedAmount);
        }

        // Also certify the citizen's already-uploaded hashed documents (PhilSys
        // ID, indigency certificate, ...) — previously the hospital only ever
        // produced its own three records and left these stuck on 'hashed'.
        $citizenDocs = CaseDocument::where('medical_case_id', $case->id)
            ->where('status', 'hashed')
            ->orderBy('id')
            ->get();

        foreach ($citizenDocs as $document) {
            $certifiedDocs[] = $this->certifyAndAnchorDocument($document, $staff, $aiService, $chain, $billedAmount);
        }

        // The request is only 'certified' once nothing it asked for is missing.
        if (empty($missing)) {
            $docReq->status = 'certified';
            $docReq->save();
        }

        if ($stateMachine->canTransition($case->status, CaseStateMachineService::READY_FOR_SUBMISSION)) {
            $stateMachine->transition($case, CaseStateMachineService::READY_FOR_SUBMISSION);
        }

        // B3: once the case is ready for submission, an agency application that
        // the citizen already submitted must move to agency review — it used to
        // sit on 'submitted' while the case advanced without it.
        $application = $case->agencyApplications()
            ->whereIn('status', ['submitted', 'needs_info'])
            ->orderBy('id')
            ->first();

        if ($application
            && $case->status === CaseStateMachineService::READY_FOR_SUBMISSION
            && $stateMachine->canTransition($case->status, CaseStateMachineService::UNDER_AGENCY_REVIEW)) {
            $stateMachine->transition($case, CaseStateMachineService::UNDER_AGENCY_REVIEW);

            if ($application->status === 'submitted') {
                $application->status = 'under_review';
                $application->save();
            }
        }

        $chain->recordEvent(
            $case,
            $staff,
            'DOCUMENTS_CERTIFIED',
            sprintf(
                'Hospital staff %s certified %d document(s) for case %s.',
                $staff->name,
                count($certifiedDocs),
                $case->case_number
            ),
            [
                'request_id' => $docReq->id,
                'document_ids' => array_map(fn (CaseDocument $d) => $d->id, $certifiedDocs),
                'missing' => $missing,
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => empty($missing)
                ? 'Medical documents successfully certified by hospital staff.'
                : 'Some requested document types have no uploaded record and were reported as missing.',
            'documents' => $certifiedDocs,
            'missing' => $missing,
            'case_status' => $case->status,
        ]);
    }

    /**
     * Marks one existing document certified and stores the real Besu anchor
     * result (transaction hash, block number, full sha256) in extracted_json.
     */
    private function certifyAndAnchorDocument(CaseDocument $document, $staff, EGovAIService $aiService, EGovChainService $chain, float $billedAmount = 0.0): CaseDocument
    {
        $certifiedAt = now()->toIso8601String();

        // Reuse the hash the document was uploaded with; only documents that
        // genuinely have no digest get one derived from their own content.
        $fullSha256 = $document->extracted_json['full_sha256']
            ?? ($document->sha256_hash ? hash('sha256', (string) $document->sha256_hash) : hash('sha256', $document->id . '|' . $document->title));

        $anchor = $chain->anchorRecordOnBesu(
            'DOC-' . $document->id,
            $fullSha256,
            'HOSPITAL_DOCUMENT_CERTIFICATION'
        );

        $txHash = $anchor['result']['transactionHash'] ?? null;
        $blockNumber = $anchor['result']['blockNumber'] ?? null;

        $meta = $document->extracted_json ?? [];
        $meta['blockchain_tx_hash'] = $txHash;
        $meta['blockchain_block_number'] = $blockNumber;
        $meta['full_sha256'] = $fullSha256;
        $meta['chain_anchored'] = (bool) ($anchor['anchored'] ?? false);
        $meta['ledger_simulated'] = (bool) ($anchor['simulated'] ?? false);
        $meta['certified_by'] = $staff->name;
        $meta['certified_by_user_id'] = $staff->id;
        $meta['certified_at'] = $certifiedAt;

        // Only overwrite the SOA title when it carries the old hard-coded
        // "(₱150,000)" literal; the real billed amount comes from the case.
        if ($document->document_type === 'statement_of_account'
            && str_contains((string) $document->title, '150,000')) {
            $document->title = 'Certified Statement of Account (₱' . number_format($billedAmount, 2) . ')';
        }

        // Keep the document's own AI classification rather than inventing one.
        if (empty($meta['classified_type'])) {
            $meta = array_merge($aiService->classifyAndExtract($document->title, $document->document_type), $meta);
        }

        $document->status = 'certified';
        $document->verified_by_user_id = $staff->id;
        $document->verification_reference = $document->verification_reference
            ?: 'HSP-CERT-' . strtoupper(substr(md5((string) $document->id . $certifiedAt), 0, 8));
        $document->extracted_json = $meta;
        $document->save();

        return $document->fresh();
    }

    public function certifyDocument(CaseDocument $document, EGovChainService $chain): JsonResponse
    {
        $staff = Auth::user() ?: (new MockEGovIdentityProvider())->resolveUser('hospital');

        $document->status = 'certified';
        $document->verified_by_user_id = $staff->id;
        $document->verification_reference = 'HSP-CERT-' . strtoupper(substr(md5(microtime()), 0, 8));
        $document->save();

        $chain->recordEvent(
            $document->medicalCase,
            $staff,
            'DOCUMENT_CERTIFIED',
            "Document {$document->title} certified by {$staff->name}",
            ['document_id' => $document->id]
        );

        try {
            app(EGovChainService::class)->anchorDocumentCertification($document, $staff);
        } catch (\Exception $e) {
            // Do not block execution
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Document certified successfully.',
            'document' => $document,
        ]);
    }

    public function uploadHospitalDocument(Request $request, MedicalCase $case, EGovAIService $aiService, EGovChainService $chain, CaseStateMachineService $stateMachine): JsonResponse
    {
        $request->validate([
            'document_type' => 'required|string',
            'title' => 'required|string',
            'file' => 'nullable|file|max:10240',
            'doc_request_id' => 'nullable|integer',
        ]);

        $staff = Auth::user() ?: (new MockEGovIdentityProvider())->resolveUser('hospital');
        $docType = $request->input('document_type');
        $title = $request->input('title');

        if ($request->hasFile('file') && $request->file('file')->isValid()) {
            $file = $request->file('file');
            $fileName = time() . '_hsp_' . Str::slug($title) . '.' . $file->getClientOriginalExtension();
            $storagePath = $file->storeAs("hospital/cases/{$case->id}", $fileName, 'public');
            $fileSize = $file->getSize();
            $fullSha256 = hash_file('sha256', $file->getRealPath());
            $hash = 'DOC-HASH-' . strtoupper(substr($fullSha256, 0, 16));
        } else {
            $content = "HOSPITAL_CERTIFIED_" . time() . '_' . $docType . '_' . $title;
            $storagePath = "hospital/cases/{$case->id}/{$docType}.pdf";
            $fileSize = 2048 * rand(100, 500);
            $fullSha256 = hash('sha256', $content);
            $hash = $chain->generateDocumentHash($content);
        }

        $aiData = $aiService->classifyAndExtract($title, $docType);

        // Anchor certification onto Hyperledger Besu Zero-Fee eGovChain
        $besuRes = $chain->anchorRecordOnBesu('HSP-DOC-' . $case->id . '-' . time(), $fullSha256, 'HOSPITAL_DOCUMENT_CERTIFICATION');
        $txHash = $besuRes['result']['transactionHash'] ?? ('0x' . hash('sha256', $hash));
        $blockNumber = $besuRes['result']['blockNumber'] ?? '0x1c37b1';

        $extractedInfo = array_merge($aiData, [
            'blockchain_tx_hash' => $txHash,
            'blockchain_block_number' => $blockNumber,
            'blockchain_consensus' => 'IBFT 2.0 Proof of Authority (Government Nodes)',
            'full_sha256' => $fullSha256,
            'certified_by' => $staff->name,
            'anchored_at' => now()->toIso8601String(),
        ]);

        $doc = CaseDocument::create([
            'medical_case_id' => $case->id,
            'document_type' => $docType,
            'title' => $title,
            'storage_path' => $storagePath,
            'file_size' => $fileSize,
            'status' => 'certified',
            'sha256_hash' => $hash,
            'verification_reference' => 'HSP-REF-' . strtoupper(substr(md5($hash), 0, 8)),
            'verified_by_user_id' => $staff->id,
            'extracted_json' => $extractedInfo,
        ]);

        if ($request->filled('doc_request_id')) {
            $docReq = HospitalDocumentRequest::find($request->input('doc_request_id'));
            if ($docReq) {
                $docReq->status = 'certified';
                $docReq->save();
            }
        }

        if ($stateMachine->canTransition($case->status, CaseStateMachineService::READY_FOR_SUBMISSION)) {
            $stateMachine->transition($case, CaseStateMachineService::READY_FOR_SUBMISSION);
        }

        $chain->recordEvent(
            $case,
            $staff,
            'DOCUMENTS_CERTIFIED',
            "Hospital staff {$staff->name} uploaded & certified {$title} (Anchored to eGovChain)",
            ['document_id' => $doc->id, 'besu_tx_hash' => $txHash]
        );

        try {
            app(EMessageService::class)->send(
                $case->applicant,
                'Official Record Certified',
                "{$staff->name} certified official record '{$title}'. Verified on eGovChain.",
                'success',
                'CaseDocument',
                $doc->id
            );
        } catch (\Exception $e) {}

        return response()->json([
            'status' => 'success',
            'message' => 'Hospital document uploaded, certified, and anchored to eGovChain blockchain.',
            'document' => $doc,
        ]);
    }

    public function verifyDocumentBlockchain(CaseDocument $document, EGovChainService $chain): JsonResponse
    {
        $besuCheck = $chain->verifyRecordOnBesu($document->sha256_hash);

        $meta = $document->extracted_json ?? [];
        $txHash = $meta['blockchain_tx_hash'] ?? ('0x' . hash('sha256', $document->sha256_hash));
        $blockNumber = $meta['blockchain_block_number'] ?? '0x1c37b1';
        $fullSha256 = $meta['full_sha256'] ?? hash('sha256', $document->sha256_hash);

        return response()->json([
            'status' => 'success',
            'document' => [
                'id' => $document->id,
                'title' => $document->title,
                'document_type' => $document->document_type,
                'status' => $document->status,
                'sha256_hash' => $document->sha256_hash,
                'full_sha256' => $fullSha256,
                'verification_reference' => $document->verification_reference,
                'created_at' => $document->created_at->toIso8601String(),
            ],
            'blockchain' => [
                'network' => $chain->ledgerLabel(),
                'consensus' => $chain->isSimulated()
                    ? 'none (simulated)'
                    : 'IBFT 2.0 Proof of Authority (Government Nodes)',
                'contract_address' => $chain->contractAddress(),
                'chain_id' => $chain->chainId(),
                'simulated' => $chain->isSimulated(),
                'transaction_hash' => $txHash,
                'block_number' => $blockNumber,
                'gas_used' => '0x0 (Zero Fee)',
                'verification_status' => $chain->isSimulated()
                    ? 'SIMULATED_NOT_ON_CHAIN'
                    : ($besuCheck['result']['state'] ?? 'UNKNOWN'),
                'ledger_result' => $besuCheck['result'] ?? [],
            ],
        ]);
    }


    public function validateGuarantee(Request $request, CaseCalculationService $calcService): JsonResponse
    {
        $request->validate([
            'gl_number' => 'required|string',
        ]);

        $glNumber = $request->input('gl_number');
        $gl = GuaranteeLetter::with(['medicalCase.applicant', 'agencyApplication.agencyProgram.agency', 'utilizations'])
            ->where('gl_number', $glNumber)
            ->first();

        if (!$gl) {
            return response()->json([
                'status' => 'error',
                'message' => 'Guarantee letter reference not found or invalid.',
            ], 404);
        }

        $guaranteeFinancials = $calcService->calculateGuarantee($gl);

        return response()->json([
            'status' => 'success',
            'badge' => 'Tamper-evident record verified on eGovChain',
            'guarantee' => [
                'id' => $gl->id,
                'gl_number' => $gl->gl_number,
                'patient_name' => $gl->patient_name,
                'applicant_name' => $gl->applicant_name,
                'hospital_name' => $gl->hospital_name,
                'issuing_agency' => $gl->agencyApplication->agencyProgram->agency->name ?? 'DSWD NCR',
                'covered_service' => $gl->covered_service,
                'issue_date' => $gl->issue_date->format('Y-m-d'),
                'expiration_date' => $gl->expiration_date->format('Y-m-d'),
                'status' => $gl->status,
                'approved_amount' => $guaranteeFinancials['approved_amount'],
                'utilized_amount' => $guaranteeFinancials['utilized_amount'],
                'remaining_value' => $guaranteeFinancials['remaining_value'],
                'digital_signatory' => [
                    'name' => $gl->digital_signatory_name,
                    'role' => $gl->digital_signatory_role,
                ],
                'chain_reference' => $gl->chain_reference,
            ],
        ]);
    }

    public function recordUtilization(Request $request, GuaranteeLetter $guarantee, CaseCalculationService $calcService, CaseStateMachineService $stateMachine, EMessageService $eMessage, EGovChainService $chain): JsonResponse
    {
        $request->validate([
            'utilized_amount' => 'required|numeric|min:1',
            'billing_reference' => 'required|string',
        ]);

        $utilizedAmount = (float) $request->input('utilized_amount');
        $billingRef = $request->input('billing_reference');

        $staff = Auth::user() ?: (new MockEGovIdentityProvider())->resolveUser('hospital');

        // B5: one transaction, and the GL row is locked so two concurrent
        // requests cannot both settle against the same remaining balance.
        $result = DB::transaction(function () use ($guarantee, $utilizedAmount, $billingRef, $staff, $calcService, $stateMachine, $chain) {
            /** @var GuaranteeLetter $locked */
            $locked = GuaranteeLetter::whereKey($guarantee->id)->lockForUpdate()->firstOrFail();
            $case = $locked->medicalCase;

            $financials = $calcService->calculateGuarantee($locked);
            $remaining = (float) $financials['remaining_value'];

            // Overdraw / expired / already-settled are refused outright.
            if ($utilizedAmount > $remaining) {
                return ['error' => 422, 'message' => 'Utilization amount exceeds the remaining guarantee balance of ₱' . number_format($remaining, 2) . '.'];
            }

            if ($locked->expiration_date && $locked->expiration_date->lt(today())) {
                return ['error' => 422, 'message' => 'This guarantee letter expired on ' . $locked->expiration_date->format('Y-m-d') . '.'];
            }

            if (! in_array($locked->status, ['valid', 'partially_utilized'], true)) {
                return ['error' => 422, 'message' => "This guarantee letter is '{$locked->status}' and can no longer be utilized."];
            }

            // The settling hospital must be the one the GL was issued to.
            if ($staff->organization_id && $case->provider_id && (int) $staff->organization_id !== (int) $case->provider_id) {
                return ['error' => 403, 'message' => 'This guarantee letter was issued to a different hospital.'];
            }

            $utilization = GuaranteeUtilization::create([
                'guarantee_letter_id' => $locked->id,
                'hospital_id' => $case->provider_id,
                'utilized_amount' => $utilizedAmount,
                'utilization_date' => now()->format('Y-m-d'),
                'billing_reference' => $billingRef,
                'status' => 'confirmed',
            ]);

            $settlement = app(EGovPayService::class)->initiateDirectSettlement(
                $locked->gl_number,
                $utilizedAmount,
                $locked->hospital_name
            );

            $updated = $calcService->calculateGuarantee($locked);

            if ((float) $updated['remaining_value'] <= 0) {
                $locked->status = 'fully_utilized';
                if ($stateMachine->canTransition($case->status, CaseStateMachineService::FULLY_UTILIZED)) {
                    $stateMachine->transition($case, CaseStateMachineService::FULLY_UTILIZED);
                }
            } else {
                $locked->status = 'partially_utilized';
                if ($stateMachine->canTransition($case->status, CaseStateMachineService::PARTIALLY_UTILIZED)) {
                    $stateMachine->transition($case, CaseStateMachineService::PARTIALLY_UTILIZED);
                }
            }
            $locked->save();

            try {
                $chain->anchorGuaranteeUtilization($utilization, $staff);
            } catch (\Throwable $e) {
                // Anchoring must not block the settlement.
            }

            $chain->recordEvent(
                $case,
                $staff,
                'GUARANTEE_UTILIZED',
                "Recorded ₱" . number_format($utilizedAmount, 2) . " guarantee utilization against billing ref {$billingRef}",
                ['utilization_id' => $utilization->id, 'billing_reference' => $billingRef]
            );

            return [
                'utilization' => $utilization,
                'guarantee' => $updated,
                'settlement' => $settlement,
                'guarantee_status' => $locked->status,
                'case' => $case,
                'applicant' => $case->applicant,
                'gl_number' => $locked->gl_number,
                'hospital_name' => $locked->hospital_name,
            ];
        });

        if (isset($result['error'])) {
            return response()->json([
                'status' => 'error',
                'message' => $result['message'],
            ], $result['error']);
        }

        // B5: exactly ONE applicant notification (it used to be sent twice).
        $eMessage->send(
            $result['applicant'],
            'Guarantee Letter Utilized',
            "₱" . number_format($utilizedAmount, 2) . " guarantee utilization recorded by {$result['hospital_name']}.",
            'success',
            'GuaranteeLetter',
            $guarantee->id
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Guarantee utilization recorded successfully.',
            'utilization' => $result['utilization'],
            'guarantee' => $result['guarantee'],
            'settlement' => $result['settlement'],
            'guarantee_status' => $result['guarantee_status'],
            'case_status' => $result['case']->status,
        ]);
    }
}
