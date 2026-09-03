<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\EGov\CompassBudgetService;
use App\Services\EGov\EGovAIService;
use App\Services\EGov\EGovChainService;
use App\Services\EGov\EGovPayService;
use App\Services\EGov\EGovSSOService;
use App\Services\EGov\EMessageService;
use App\Services\EGov\EReportService;
use App\Services\EGov\EVerifyService;
use App\Services\EGov\FaceLivenessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EGovIntegrationController extends Controller
{
    // --- 1. eGov SSO ---
    public function ssoToken(Request $request, EGovSSOService $sso): JsonResponse
    {
        $code = $request->input('exchange_code', '');
        $scope = $request->input('scope', 'SSO_AUTHENTICATION');
        // Partner credentials are server-side configuration; never accept them from clients.
        $partnerCode = config('services.egov.sso.partner_code');
        $partnerSecret = config('services.egov.sso.partner_secret');

        $res = $sso->exchangeToken($code, $scope, $partnerCode, $partnerSecret);
        return response()->json($res['data'], $res['status']);
    }

    public function ssoAuthentication(Request $request, EGovSSOService $sso): JsonResponse
    {
        $token = $request->bearerToken() ?: $request->header('Authorization', '');
        $res = $sso->fetchSsoProfile($token);
        return response()->json($res, $res['status'] ?? 200);
    }

    // --- 2. eVerify ---
    public function eVerifyAuth(Request $request, EVerifyService $eVerify): JsonResponse
    {
        // eVerify credentials are server-side configuration; never accept them from clients.
        $clientId = config('services.egov.everify.client_id');
        $clientSecret = config('services.egov.everify.client_secret');

        $res = $eVerify->authenticate($clientId, $clientSecret);
        return response()->json($res['data'], $res['status']);
    }

    public function eVerifyQuery(Request $request, EVerifyService $eVerify): JsonResponse
    {
        $res = $eVerify->verifyDemographics($request->all(), $request->bearerToken() ?: '');
        return response()->json($res, $res['status']);
    }

    public function eVerifyQrCheck(Request $request, EVerifyService $eVerify): JsonResponse
    {
        $qrValue = $request->input('value', '');
        $res = $eVerify->checkQr($qrValue, $request->bearerToken() ?: '');
        if ($res['status'] !== 200) {
            return response()->json($res['data'], $res['status']);
        }
        return response()->json([
            'data' => $res['data'],
            'meta' => $res['meta'],
        ], $res['status']);
    }

    public function eVerifyQrVerify(Request $request, EVerifyService $eVerify): JsonResponse
    {
        $qrValue = $request->input('value', '');
        $sessionId = $request->input('face_liveness_session_id', '');
        $res = $eVerify->verifyQr($qrValue, $sessionId, $request->bearerToken() ?: '');
        return response()->json($res, $res['status']);
    }

    // --- 3. Face Liveness ---
    public function createLivenessSession(Request $request, FaceLivenessService $liveness): JsonResponse
    {
        $action = $request->input('action', 'redirect');
        $callbackUrl = $request->input('callback_url', 'https://your-app.com/callback');
        $delay = (int) $request->input('delay', 3000);

        $res = $liveness->createSession($action, $callbackUrl, $delay);
        return response()->json($res['data'], $res['status']);
    }

    public function getLivenessResult(string $sessionToken, FaceLivenessService $liveness): JsonResponse
    {
        $res = $liveness->getResult($sessionToken);
        return response()->json($res['data'], $res['status']);
    }

    // --- 4. eGov AI ---
    public function aiToken(Request $request, EGovAIService $ai): JsonResponse
    {
        $code = $request->input('access_code') ?: config('services.egov.ai.access_code', '');
        $res = $ai->generateToken($code);
        return response()->json($res['data'], $res['status']);
    }

    public function aiAssistant(Request $request, EGovAIService $ai): JsonResponse
    {
        $prompt = $request->input('prompt', 'how can i get my digital tin id here in egov');
        $category = $request->input('category', 'PH');
        $res = $ai->aiAssistant($prompt, $category);
        return response()->json($res['data'], $res['status']);
    }

    public function speechMaker(Request $request, EGovAIService $ai): JsonResponse
    {
        $prompt = $request->input('prompt', 'Speech about digital government');
        $category = $request->input('category', 'PH');
        $res = $ai->speechMaker($prompt, $category);
        return response()->json($res['data'], $res['status']);
    }

    public function tourism(Request $request, EGovAIService $ai): JsonResponse
    {
        $prompt = $request->input('prompt', 'Provide travel itinerary for Boracay');
        $category = $request->input('category', 'PH');
        $res = $ai->tourism($prompt, $category);
        return response()->json($res['data'], $res['status']);
    }

    public function lawsAndRegulations(Request $request, EGovAIService $ai): JsonResponse
    {
        $prompt = $request->input('prompt', 'Can you explain your purpose?');
        $category = $request->input('category', 'PH');
        $res = $ai->lawsAndRegulations($prompt, $category);
        return response()->json($res['data'], $res['status']);
    }

    public function translator(Request $request, EGovAIService $ai): JsonResponse
    {
        $prompt = $request->input('prompt', 'How should education adapt to AI?');
        $source = $request->input('source_lang', 'en');
        $target = $request->input('target_lang', 'fil');
        $res = $ai->translator($prompt, $source, $target);
        return response()->json($res['data'], $res['status']);
    }

    public function documentExtractor(Request $request, EGovAIService $ai): JsonResponse
    {
        $file = $request->file('file');
        $res = $ai->documentExtractor($file);
        return response()->json($res['data'], $res['status']);
    }

    public function aiCredits(Request $request, EGovAIService $ai): JsonResponse
    {
        $authHeader = $request->header('Authorization', '');
        $token = str_starts_with($authHeader, 'Bearer ') ? substr($authHeader, 7) : null;
        $res = $ai->credits($token);
        return response()->json($res['data'], $res['status']);
    }


    // --- 5. eGovChain (Hyperledger Besu JSON-RPC) ---
    public function besuJsonRpc(Request $request, EGovChainService $chain): JsonResponse
    {
        $payload = $request->all();
        $response = $chain->handleJsonRpc($payload);
        return response()->json($response);
    }

    public function anchorRecord(Request $request, EGovChainService $chain): JsonResponse
    {
        $recordId = $request->input('record_id', 'GL-DSWD-2026-04821');
        $hash = $request->input('hash', '0xd8f2910c5d12a8f9104b2819c5b201f8');
        $res = $chain->anchorRecordOnBesu($recordId, $hash);
        return response()->json($res);
    }

    // --- 6. eGovPay ---
    public function payCreateTransaction(Request $request, EGovPayService $pay): JsonResponse
    {
        $payload = $request->all();
        $res = $pay->createTransaction($payload);
        return response()->json($res['data'], $res['status']);
    }

    public function payGetTransaction(string $uuid, EGovPayService $pay): JsonResponse
    {
        $res = $pay->getTransaction($uuid);
        return response()->json($res['data'], $res['status']);
    }

    public function payVoidTransaction(string $uuid, EGovPayService $pay): JsonResponse
    {
        $res = $pay->voidTransaction($uuid);
        return response()->json($res['data'], $res['status']);
    }

    public function paySettle(Request $request, EGovPayService $pay): JsonResponse
    {
        $glNumber = $request->input('gl_number', 'GL-DSWD-2026-04821');
        $amount = (float) $request->input('amount', 50000.00);
        $payee = $request->input('payee_organization', 'Manila General Hospital');

        $res = $pay->initiateDirectSettlement($glNumber, $amount, $payee);
        return response()->json($res);
    }


    // --- 7. eMessage ---
    public function pushSms(Request $request, EMessageService $msg): JsonResponse
    {
        $number = $request->input('number', '');
        $message = $request->input('message', '');

        if (empty($number) || empty($message)) {
            return response()->json([
                'error' => 'unprocessable_entity',
                'message' => 'The given data was invalid.',
                'error_description' => 'The given data was invalid.',
                'errors' => [
                    'number' => empty($number) ? ['validation.required'] : [],
                    'message' => empty($message) ? ['validation.required'] : [],
                ],
            ], 422);
        }

        $res = $msg->pushSms($number, $message);
        return response()->json($res['data'], $res['status']);
    }

    public function sendMessage(Request $request, EMessageService $msg): JsonResponse
    {
        $user = Auth::user() ?: \App\Models\User::first();
        $title = $request->input('title', 'eGov Notice');
        $message = $request->input('message', 'Your Guarantee Letter GL-DSWD-2026-04821 has been issued.');

        $notif = $msg->send($user, $title, $message, 'info');
        return response()->json(['status' => 'success', 'notification' => $notif]);
    }

    // --- 8. eReport ---
    public function ereportToken(Request $request, EReportService $report): JsonResponse
    {
        $code = $request->input('access_code');
        $res = $report->generateToken($code);
        return response()->json($res['data'], $res['status']);
    }

    public function ereportReportTypes(Request $request, EReportService $report): JsonResponse
    {
        $token = $this->extractBearerToken($request);
        $res = $report->getReportTypes($token);
        return response()->json($res['data'], $res['status']);
    }

    public function ereportRegions(Request $request, EReportService $report): JsonResponse
    {
        $token = $this->extractBearerToken($request);
        $res = $report->getRegions($token);
        return response()->json($res['data'], $res['status']);
    }

    public function ereportProvinces(Request $request, EReportService $report): JsonResponse
    {
        $token = $this->extractBearerToken($request);
        $regionCode = (string) $request->query('region_code', '040000000');
        $res = $report->getProvinces($regionCode, $token);
        return response()->json($res['data'], $res['status']);
    }

    public function ereportMunicipalities(Request $request, EReportService $report): JsonResponse
    {
        $token = $this->extractBearerToken($request);
        $provinceCode = (string) $request->query('province_code', '042100000');
        $res = $report->getMunicipalities($provinceCode, $token);
        return response()->json($res['data'], $res['status']);
    }

    public function ereportBarangays(Request $request, EReportService $report): JsonResponse
    {
        $token = $this->extractBearerToken($request);
        $municipalityCode = (string) $request->query('municipality_code', '042111000');
        $res = $report->getBarangays($municipalityCode, $token);
        return response()->json($res['data'], $res['status']);
    }

    public function ereportSubmitComplaint(Request $request, EReportService $report): JsonResponse
    {
        $token = $this->extractBearerToken($request);
        $payload = $request->all();
        $res = $report->submitComplaint($payload, $token);
        return response()->json($res['data'], $res['status']);
    }

    public function ereportVerifyRequest(Request $request, EReportService $report): JsonResponse
    {
        $token = $this->extractBearerToken($request);
        $email = $request->input('email', 'josie@yopmail.com');
        $res = $report->requestOtp($email, $token);
        return response()->json($res['data'], $res['status']);
    }

    public function ereportVerifyConfirm(Request $request, EReportService $report): JsonResponse
    {
        $token = $this->extractBearerToken($request);
        $email = $request->input('email', 'josie@yopmail.com');
        $otp = $request->input('otp', '000000');
        $res = $report->confirmOtp($email, $otp, $token);
        return response()->json($res['data'], $res['status']);
    }

    public function ereportReports(Request $request, EReportService $report): JsonResponse
    {
        $viewToken = (string) ($request->header('X-EReport-View-Token') ?: $request->query('view_token', ''));
        $params = $request->query();
        $res = $report->getReports($viewToken, $params);
        return response()->json($res['data'], $res['status']);
    }

    public function ereportViewReport(Request $request, string $caseNumber, EReportService $report): JsonResponse
    {
        $viewToken = (string) ($request->header('X-EReport-View-Token') ?: $request->query('view_token', ''));
        $res = $report->getReportByCaseNumber($caseNumber, $viewToken);
        return response()->json($res['data'], $res['status']);
    }

    public function submitReport(Request $request, EReportService $report): JsonResponse
    {
        $action = $request->input('action', 'CITIZEN_COMPLAINT_FILED');
        $payload = $request->all();
        $res = $report->submitAuditReport($action, $payload);
        return response()->json($res);
    }


    // --- 9. Compass Budget ---
    public function compassBudget(Request $request, CompassBudgetService $compass): JsonResponse
    {
        $code = $request->query('program_code', 'DSWD-AICS');
        $res = $compass->getBudgetStatus($code);
        return response()->json($res);
    }

    public function compassSaaodb(Request $request, CompassBudgetService $compass): JsonResponse
    {
        $res = $compass->getSaaodbRecords($request->query());
        return response()->json($res['data'], $res['status']);
    }

    public function compassSaaodbDashboard(Request $request, CompassBudgetService $compass): JsonResponse
    {
        $year = (int) $request->query('reportYear', 2026);
        $scope = (string) $request->query('sheetScope', 'summary');
        $res = $compass->getSaaodbDashboard($year, $scope);
        return response()->json($res['data'], $res['status']);
    }

    public function compassSaaodbEntities(Request $request, CompassBudgetService $compass): JsonResponse
    {
        $res = $compass->getSaaodbEntities($request->query());
        return response()->json($res['data'], $res['status']);
    }

    public function compassNca(Request $request, CompassBudgetService $compass): JsonResponse
    {
        $res = $compass->getNcaRecords($request->query());
        return response()->json($res['data'], $res['status']);
    }

    public function compassSaro(Request $request, CompassBudgetService $compass): JsonResponse
    {
        $res = $compass->getSaroRecords($request->query());
        return response()->json($res['data'], $res['status']);
    }

    public function compassLgsf(Request $request, CompassBudgetService $compass): JsonResponse
    {
        $res = $compass->getLgsfRecords($request->query());
        return response()->json($res['data'], $res['status']);
    }

    public function compassLgsfDashboard(Request $request, CompassBudgetService $compass): JsonResponse
    {
        $res = $compass->getLgsfDashboard($request->query());
        return response()->json($res['data'], $res['status']);
    }

    private function extractBearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization', '');
        if (preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
            return trim($matches[1]);
        }
        return $request->query('token') ?: null;
    }
}
