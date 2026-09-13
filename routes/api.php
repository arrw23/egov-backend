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
Route::get('/v1/egov/integration/credits', [EGovIntegrationController::class, 'aiCredits']);

// 5. eMessage SMS Push -> /messaging/v1/sms/push
Route::post('/messaging/v1/sms/push', [EGovIntegrationController::class, 'pushSms']);


/*
|--------------------------------------------------------------------------
| GabayMed Application REST API (v1 Prefixed) -> /api/v1/*
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->group(function () {
    /*
    |----------------------------------------------------------------------
    | Public (no authentication)
    |----------------------------------------------------------------------
    | Public, non-secret configuration plus the sign-in endpoints. The login
    | page needs these before a user has a token.
    */
    Route::get('/egov/public-config', [EGovIntegrationController::class, 'publicConfig']);
    Route::get('/auth/egov/redirect', [AuthController::class, 'redirect']);
    Route::get('/auth/egov/callback', [AuthController::class, 'callback']);
    Route::post('/auth/egov/exchange', [AuthController::class, 'exchange']);
    Route::post('/auth/mock/login', [AuthController::class, 'mockLogin']);

    /*
    |----------------------------------------------------------------------
    | Authenticated (any role)
    |----------------------------------------------------------------------
    | Laravel's api group is stateless, so Auth::login() alone was forgotten
    | on the next request and every controller fell back to a hard-coded
    | user. Sanctum tokens make the caller real.
    */
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::post('/identity/consents', [IdentityController::class, 'recordConsent']);
        Route::post('/identity/verify', [IdentityController::class, 'verify']);

        // Reference data used by the application wizard.
        Route::get('/providers', [ApplicantCaseController::class, 'providers']);
        Route::get('/agency-programs', [ApplicantCaseController::class, 'agencyPrograms']);

        // Any party to a case may read its audit timeline, and any party may
        // validate a guarantee letter presented to them.
        Route::get('/cases/{case}/timeline', [ApplicantCaseController::class, 'timeline']);
        Route::get('/cases/{case}/timeline/verify', [ApplicantCaseController::class, 'verifyTimeline']);
        Route::post('/guarantees/validate', [HospitalController::class, 'validateGuarantee']);
        Route::get('/guarantees/{guarantee}', [AgencyController::class, 'showGuarantee']);

        // Each role has its own notification feed.
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);

        /*
        |------------------------------------------------------------------
        | eGov catalog proxies
        |------------------------------------------------------------------
        | Server-side shims that hold the credentials. Previously open to
        | anyone, which let an anonymous caller spend eGov AI credits and
        | push real SMS.
        */
        Route::post('/egov/sso/token', [EGovIntegrationController::class, 'ssoToken']);
        Route::post('/egov/sso/profile', [EGovIntegrationController::class, 'ssoAuthentication']);
        Route::post('/everify/auth', [EGovIntegrationController::class, 'eVerifyAuth']);
        Route::post('/everify/query', [EGovIntegrationController::class, 'eVerifyQuery']);
        Route::post('/everify/qr/check', [EGovIntegrationController::class, 'eVerifyQrCheck']);
        Route::post('/everify/qr/verify', [EGovIntegrationController::class, 'eVerifyQrVerify']);

        Route::post('/egovchain/rpc', [EGovIntegrationController::class, 'besuJsonRpc']);
        Route::post('/egovchain/anchor', [EGovIntegrationController::class, 'anchorRecord']);

        Route::post('/pay/settle', [EGovIntegrationController::class, 'paySettle']);
        Route::post('/pay/transaction', [EGovIntegrationController::class, 'payCreateTransaction']);
        Route::get('/pay/transaction/{uuid}', [EGovIntegrationController::class, 'payGetTransaction']);
        Route::put('/pay/transaction/{uuid}/void', [EGovIntegrationController::class, 'payVoidTransaction']);
        Route::post('/emessage/send', [EGovIntegrationController::class, 'sendMessage']);
        Route::post('/ereport/submit', [EGovIntegrationController::class, 'submitReport']);
        Route::post('/v1/ereport/token', [EGovIntegrationController::class, 'ereportToken']);
        Route::get('/v1/ereport/datasets/report_types', [EGovIntegrationController::class, 'ereportReportTypes']);
        Route::get('/v1/ereport/datasets/regions', [EGovIntegrationController::class, 'ereportRegions']);
        Route::get('/v1/ereport/datasets/provinces', [EGovIntegrationController::class, 'ereportProvinces']);
        Route::get('/v1/ereport/datasets/municipalities', [EGovIntegrationController::class, 'ereportMunicipalities']);
        Route::get('/v1/ereport/datasets/barangays', [EGovIntegrationController::class, 'ereportBarangays']);
        Route::post('/v1/ereport/submit_complaint', [EGovIntegrationController::class, 'ereportSubmitComplaint']);
        Route::post('/v1/ereport/verify/request', [EGovIntegrationController::class, 'ereportVerifyRequest']);
        Route::post('/v1/ereport/verify/confirm', [EGovIntegrationController::class, 'ereportVerifyConfirm']);
        Route::get('/v1/ereport/reports', [EGovIntegrationController::class, 'ereportReports']);
        Route::get('/v1/ereport/reports/{case_number}', [EGovIntegrationController::class, 'ereportViewReport']);
        Route::get('/compass/budget', [EGovIntegrationController::class, 'compassBudget']);
        Route::get('/v1/records/saaodb', [EGovIntegrationController::class, 'compassSaaodb']);
        Route::get('/v1/records/saaodb/dashboard', [EGovIntegrationController::class, 'compassSaaodbDashboard']);
        Route::get('/v1/records/saaodb/entities', [EGovIntegrationController::class, 'compassSaaodbEntities']);
        Route::get('/v1/records/nca', [EGovIntegrationController::class, 'compassNca']);
        Route::get('/v1/records/saro', [EGovIntegrationController::class, 'compassSaro']);
        Route::get('/v1/records/lgsf', [EGovIntegrationController::class, 'compassLgsf']);
        Route::get('/v1/records/lgsf/dashboard', [EGovIntegrationController::class, 'compassLgsfDashboard']);

        /*
        |------------------------------------------------------------------
        | Applicant portal
        |------------------------------------------------------------------
        */
        Route::middleware('role:applicant')->group(function () {
            Route::get('/cases', [ApplicantCaseController::class, 'index']);
            Route::post('/cases', [ApplicantCaseController::class, 'store']);
            Route::get('/cases/{case}', [ApplicantCaseController::class, 'show']);
            Route::post('/cases/{case}/documents', [ApplicantCaseController::class, 'uploadDocument']);
            Route::post('/cases/{case}/hospital-request', [ApplicantCaseController::class, 'requestHospitalDocuments']);
            Route::post('/cases/{case}/agency-applications', [ApplicantCaseController::class, 'submitAgencyApplication']);
        });

        /*
        |------------------------------------------------------------------
        | Hospital portal
        |------------------------------------------------------------------
        */
        Route::middleware('role:hospital_staff')->group(function () {
            Route::get('/hospital/requests', [HospitalController::class, 'pendingRequests']);
            Route::get('/hospital/requests/{docReq}', [HospitalController::class, 'showRequest']);
            Route::post('/hospital/requests/{docReq}/documents', [HospitalController::class, 'submitDocuments']);
            Route::post('/hospital/cases/{case}/documents', [HospitalController::class, 'uploadHospitalDocument']);
            Route::post('/documents/{document}/certify', [HospitalController::class, 'certifyDocument']);
            Route::get('/documents/{document}/verify-blockchain', [HospitalController::class, 'verifyDocumentBlockchain']);
            Route::post('/guarantees/{guarantee}/utilizations', [HospitalController::class, 'recordUtilization']);
        });

        /*
        |------------------------------------------------------------------
        | Agency portal
        |------------------------------------------------------------------
        */
        Route::middleware('role:agency_evaluator')->group(function () {
            Route::get('/agency/applications', [AgencyController::class, 'index']);
            Route::get('/agency/applications/{application}', [AgencyController::class, 'show']);
            Route::post('/agency/applications/{application}/summary', [AgencyController::class, 'generateSummary']);
            Route::post('/agency/applications/{application}/decision', [AgencyController::class, 'decision']);
        });
    });
});
