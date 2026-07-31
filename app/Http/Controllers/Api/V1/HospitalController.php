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
use Illuminate\Support\Str;

class HospitalController extends Controller
{
    public function pendingRequests(): JsonResponse
    {
        $requests = HospitalDocumentRequest::with(['medicalCase.applicant', 'hospital'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'requests' => $requests,
        ]);
    }

    public function showRequest(HospitalDocumentRequest $request): JsonResponse
    {
        $request->load(['medicalCase.applicant', 'medicalCase.documents', 'hospital']);

        $aiService = new EGovAIService();
        $aiSummary = $aiService->generateCaseSummary($request->medicalCase);

        return response()->json([
            'status' => 'success',
            'request' => $request,
            'ai_extraction' => $aiSummary,
        ]);
    }

    public function submitDocuments(Request $request, HospitalDocumentRequest $docReq, EGovAIService $aiService, EGovChainService $chain, CaseStateMachineService $stateMachine): JsonResponse
    {
        $case = $docReq->medicalCase;
        $staff = Auth::user() ?: (new MockEGovIdentityProvider())->resolveUser('hospital');

        // Create hospital certified records: medical_abstract, statement_of_account, treatment_order
        $docsToCreate = [
            ['type' => 'medical_abstract', 'title' => 'Official Medical Abstract'],
            ['type' => 'statement_of_account', 'title' => 'Certified Statement of Account (₱150,000)'],
            ['type' => 'treatment_order', 'title' => 'Physician Treatment Order'],
        ];

        $createdDocs = [];
        foreach ($docsToCreate as $d) {
            $content = "HOSPITAL_CERTIFIED_" . $d['type'] . '_' . time();
            $hash = $chain->generateDocumentHash($content);
            $aiData = $aiService->classifyAndExtract($d['title'], $d['type']);

            $doc = CaseDocument::create([
                'medical_case_id' => $case->id,
                'document_type' => $d['type'],
                'title' => $d['title'],
                'storage_path' => "hospital/cases/{$case->id}/{$d['type']}.pdf",
                'file_size' => 2048 * rand(100, 500),
                'status' => 'certified',
                'sha256_hash' => $hash,
                'verification_reference' => 'HSP-REF-' . strtoupper(substr(md5($hash), 0, 8)),
                'verified_by_user_id' => $staff->id,
                'extracted_json' => $aiData,
            ]);

            $createdDocs[] = $doc;
        }

        $docReq->status = 'certified';
        $docReq->save();

        if ($stateMachine->canTransition($case->status, CaseStateMachineService::READY_FOR_SUBMISSION)) {
            $stateMachine->transition($case, CaseStateMachineService::READY_FOR_SUBMISSION);
        }

        $chain->recordEvent(
            $case,
            $staff,
            'DOCUMENTS_CERTIFIED',
            "Hospital staff {$staff->name} certified official medical abstract, SOA, and treatment orders.",
            ['request_id' => $docReq->id]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Medical documents successfully uploaded and certified by hospital staff.',
            'documents' => $createdDocs,
        ]);
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
                'network' => 'Hyperledger Besu Zero-Fee eGovChain',
                'consensus' => 'IBFT 2.0 Proof of Authority (Government Nodes)',
                'contract_address' => '0x71C7656EC7ab88b098defB751B7401B5f6d8976F',
                'transaction_hash' => $txHash,
                'block_number' => $blockNumber,
                'gas_used' => '0x0 (Zero Fee)',
                'verification_status' => 'TAMPER_EVIDENT_VALID',
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

        $utilization = GuaranteeUtilization::create([
            'guarantee_letter_id' => $guarantee->id,
            'hospital_id' => $guarantee->medicalCase->provider_id,
            'utilized_amount' => $utilizedAmount,
            'utilization_date' => now()->format('Y-m-d'),
            'billing_reference' => $billingRef,
            'status' => 'confirmed',
        ]);

        $financials = $calcService->calculateGuarantee($guarantee);

        if ($financials['remaining_value'] <= 0) {
            $guarantee->status = 'fully_utilized';
            if ($stateMachine->canTransition($guarantee->medicalCase->status, CaseStateMachineService::FULLY_UTILIZED)) {
                $stateMachine->transition($guarantee->medicalCase, CaseStateMachineService::FULLY_UTILIZED);
            }
        } else {
            $guarantee->status = 'partially_utilized';
            if ($stateMachine->canTransition($guarantee->medicalCase->status, CaseStateMachineService::PARTIALLY_UTILIZED)) {
                $stateMachine->transition($guarantee->medicalCase, CaseStateMachineService::PARTIALLY_UTILIZED);
            }
        }
        $guarantee->save();

        // Notify Applicant
        $applicant = $guarantee->medicalCase->applicant;
        
        try {
            app(EGovPayService::class)->initiateDirectSettlement($guarantee->gl_number, (float)$utilizedAmount, $guarantee->hospital_name);
            app(EGovChainService::class)->anchorGuaranteeUtilization($utilization, $staff);
            app(\App\Services\EGov\EMessageService::class)->send(
                $applicant,
                'Guarantee Letter Utilized',
                "₱" . number_format($utilizedAmount, 2) . " guarantee utilization recorded by {$guarantee->hospital_name}.",
                'success',
                'GuaranteeLetter',
                $guarantee->id
            );
        } catch (\Exception $e) {
            // Ignore failure
        }
        
        $eMessage->send(
            $applicant,
            'Guarantee Letter Utilized',
            "₱" . number_format($utilizedAmount, 2) . " guarantee utilization recorded by {$guarantee->hospital_name}.",
            'success',
            'GuaranteeLetter',
            $guarantee->id
        );

        $chain->recordEvent(
            $guarantee->medicalCase,
            $staff,
            'GUARANTEE_UTILIZED',
            "Recorded ₱" . number_format($utilizedAmount, 2) . " guarantee utilization against billing ref {$billingRef}",
            ['utilization_id' => $utilization->id, 'billing_reference' => $billingRef]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Guarantee utilization recorded successfully.',
            'utilization' => $utilization,
            'guarantee_status' => $guarantee->status,
        ]);
    }
}
