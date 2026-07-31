<?php

use App\Http\Controllers\Api\V1\AgencyController;
use App\Http\Controllers\Api\V1\ApplicantCaseController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EGovIntegrationController;
use App\Http\Controllers\Api\V1\HospitalController;
use App\Http\Controllers\Api\V1\IdentityController;
use App\Http\Controllers\Api\V1\NotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| eGov Official Catalog & Partner Integration Endpoints
| (Automatically prefixed with /api by Laravel)
|--------------------------------------------------------------------------
*/

// 1. eGov SSO -> /api/token & /api/partner/sso_authentication
Route::post('/token', [EGovIntegrationController::class, 'ssoToken']);
Route::post('/partner/sso_authentication', [EGovIntegrationController::class, 'ssoAuthentication']);

// 2. eVerify -> /api/auth, /api/query, /api/query/qr/check, /api/query/qr
Route::post('/auth', [EGovIntegrationController::class, 'eVerifyAuth']);
Route::post('/query', [EGovIntegrationController::class, 'eVerifyQuery']);
Route::post('/query/qr/check', [EGovIntegrationController::class, 'eVerifyQrCheck']);
Route::post('/query/qr', [EGovIntegrationController::class, 'eVerifyQrVerify']);

// 3. Face Liveness -> /api/v1/liveness/session & /api/v1/liveness/result/{sessionToken}
Route::post('/v1/liveness/session', [EGovIntegrationController::class, 'createLivenessSession']);
Route::get('/v1/liveness/result/{sessionToken}', [EGovIntegrationController::class, 'getLivenessResult']);

// 4. eGov AI Integration Endpoints -> /api/v1/egov/integration/*
Route::post('/v1/egov/integration/token', [EGovIntegrationController::class, 'aiToken']);
Route::post('/v1/egov/integration/ai_assistant/generate', [EGovIntegrationController::class, 'aiAssistant']);
Route::post('/v1/egov/integration/speech_maker/generate', [EGovIntegrationController::class, 'speechMaker']);
Route::post('/v1/egov/integration/tourism/generate', [EGovIntegrationController::class, 'tourism']);
Route::post('/v1/egov/integration/laws_and_regulations/generate', [EGovIntegrationController::class, 'lawsAndRegulations']);
Route::post('/v1/egov/integration/translator/generate', [EGovIntegrationController::class, 'translator']);
Route::post('/v1/egov/integration/document_extractor/generate', [EGovIntegrationController::class, 'documentExtractor']);


/*
|--------------------------------------------------------------------------
| GabayMed Application REST API (v1 Prefixed) -> /api/v1/*
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->group(function () {
    // Auth & Identity
    Route::get('/auth/egov/redirect', [AuthController::class, 'redirect']);
    Route::get('/auth/egov/callback', [AuthController::class, 'callback']);
    Route::post('/auth/mock/login', [AuthController::class, 'mockLogin']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/identity/consents', [IdentityController::class, 'recordConsent']);
    Route::post('/identity/verify', [IdentityController::class, 'verify']);

    // eGov Catalog Endpoints (v1 namespace proxies)
    Route::post('/egov/sso/token', [EGovIntegrationController::class, 'ssoToken']);
    Route::post('/egov/sso/profile', [EGovIntegrationController::class, 'ssoAuthentication']);
    Route::post('/everify/auth', [EGovIntegrationController::class, 'eVerifyAuth']);
    Route::post('/everify/query', [EGovIntegrationController::class, 'eVerifyQuery']);
    Route::post('/everify/qr/check', [EGovIntegrationController::class, 'eVerifyQrCheck']);
    Route::post('/everify/qr/verify', [EGovIntegrationController::class, 'eVerifyQrVerify']);

    // eGovChain Hyperledger Besu Blockchain JSON-RPC
    Route::post('/egovchain/rpc', [EGovIntegrationController::class, 'besuJsonRpc']);
    Route::post('/egovchain/anchor', [EGovIntegrationController::class, 'anchorRecord']);

    // eGovPay, eMessage, eReport, Compass
    Route::post('/pay/settle', [EGovIntegrationController::class, 'paySettle']);
    Route::post('/emessage/send', [EGovIntegrationController::class, 'sendMessage']);
    Route::post('/ereport/submit', [EGovIntegrationController::class, 'submitReport']);
    Route::get('/compass/budget', [EGovIntegrationController::class, 'compassBudget']);

    // Applicant Cases & Selection
    Route::get('/cases', [ApplicantCaseController::class, 'index']);
    Route::post('/cases', [ApplicantCaseController::class, 'store']);
    Route::get('/cases/{case}', [ApplicantCaseController::class, 'show']);
    Route::post('/cases/{case}/documents', [ApplicantCaseController::class, 'uploadDocument']);
    Route::post('/cases/{case}/hospital-request', [ApplicantCaseController::class, 'requestHospitalDocuments']);
    Route::get('/providers', [ApplicantCaseController::class, 'providers']);
    Route::get('/agency-programs', [ApplicantCaseController::class, 'agencyPrograms']);
    Route::post('/cases/{case}/agency-applications', [ApplicantCaseController::class, 'submitAgencyApplication']);
    Route::get('/cases/{case}/timeline', [ApplicantCaseController::class, 'timeline']);

    // Hospital Portal
    Route::get('/hospital/requests', [HospitalController::class, 'pendingRequests']);
    Route::get('/hospital/requests/{docReq}', [HospitalController::class, 'showRequest']);
    Route::post('/hospital/requests/{docReq}/documents', [HospitalController::class, 'submitDocuments']);
    Route::post('/hospital/cases/{case}/documents', [HospitalController::class, 'uploadHospitalDocument']);
    Route::post('/documents/{document}/certify', [HospitalController::class, 'certifyDocument']);
    Route::get('/documents/{document}/verify-blockchain', [HospitalController::class, 'verifyDocumentBlockchain']);
    Route::post('/guarantees/validate', [HospitalController::class, 'validateGuarantee']);
    Route::post('/guarantees/{guarantee}/utilizations', [HospitalController::class, 'recordUtilization']);

    // Agency Portal
    Route::get('/agency/applications', [AgencyController::class, 'index']);
    Route::get('/agency/applications/{application}', [AgencyController::class, 'show']);
    Route::post('/agency/applications/{application}/summary', [AgencyController::class, 'generateSummary']);
    Route::post('/agency/applications/{application}/decision', [AgencyController::class, 'decision']);
    Route::get('/guarantees/{guarantee}', [AgencyController::class, 'showGuarantee']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
});
