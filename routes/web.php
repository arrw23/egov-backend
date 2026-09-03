<?php

use App\Http\Controllers\Api\V1\EGovIntegrationController;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Support\Facades\Route;

Route::middleware([HandleCors::class])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->group(function () {
    Route::get('/', function () {
        return response()->json([
            'system' => "GabayMed Platform",
            'status' => 'online',
            'integrated_services' => [
                'eGov SSO',
                'eVerify',
                'Face Liveness',
                'eGov AI',
                'eGovPay',
                'eGovChain (Hyperledger Besu Blockchain)',
                'eMessage',
                'eReport',
                'Compass DBM Transparency',
            ],
        ]);
    });

    // Root-level eGov API fallbacks & preflight OPTIONS support
    Route::options('/{any}', function () {
        return response()->json([], 200);
    })->where('any', '.*');

    Route::post('/v1/liveness/session', [EGovIntegrationController::class, 'createLivenessSession']);
    Route::get('/v1/liveness/result/{sessionToken}', [EGovIntegrationController::class, 'getLivenessResult']);
    Route::post('/api/token', [EGovIntegrationController::class, 'ssoToken']);
    Route::post('/api/partner/sso_authentication', [EGovIntegrationController::class, 'ssoAuthentication']);
    Route::post('/messaging/v1/sms/push', [EGovIntegrationController::class, 'pushSms']);

    // eGovPay standard root routes
    Route::post('/api/v1/transaction', [EGovIntegrationController::class, 'payCreateTransaction']);
    Route::get('/api/v1/transaction/{uuid}', [EGovIntegrationController::class, 'payGetTransaction']);
    Route::put('/api/v1/transaction/{uuid}/void', [EGovIntegrationController::class, 'payVoidTransaction']);

    // eReport standard root routes
    Route::post('/api/integration/token', [EGovIntegrationController::class, 'ereportToken']);
    Route::get('/api/integration/datasets/report_types', [EGovIntegrationController::class, 'ereportReportTypes']);
    Route::get('/api/integration/datasets/regions', [EGovIntegrationController::class, 'ereportRegions']);
    Route::get('/api/integration/datasets/provinces', [EGovIntegrationController::class, 'ereportProvinces']);
    Route::get('/api/integration/datasets/municipalities', [EGovIntegrationController::class, 'ereportMunicipalities']);
    Route::get('/api/integration/datasets/barangays', [EGovIntegrationController::class, 'ereportBarangays']);
    Route::post('/api/integration/submit_complaint', [EGovIntegrationController::class, 'ereportSubmitComplaint']);
    Route::post('/api/integration/verify/request', [EGovIntegrationController::class, 'ereportVerifyRequest']);
    Route::post('/api/integration/verify/confirm', [EGovIntegrationController::class, 'ereportVerifyConfirm']);
    Route::get('/api/integration/reports', [EGovIntegrationController::class, 'ereportReports']);
    Route::get('/api/integration/reports/{case_number}', [EGovIntegrationController::class, 'ereportViewReport']);

    // DBM Compass standard root routes
    Route::get('/api/v1/records/saaodb', [EGovIntegrationController::class, 'compassSaaodb']);
    Route::get('/api/v1/records/saaodb/dashboard', [EGovIntegrationController::class, 'compassSaaodbDashboard']);
    Route::get('/api/v1/records/saaodb/entities', [EGovIntegrationController::class, 'compassSaaodbEntities']);
    Route::get('/api/v1/records/nca', [EGovIntegrationController::class, 'compassNca']);
    Route::get('/api/v1/records/saro', [EGovIntegrationController::class, 'compassSaro']);
    Route::get('/api/v1/records/lgsf', [EGovIntegrationController::class, 'compassLgsf']);
    Route::get('/api/v1/records/lgsf/dashboard', [EGovIntegrationController::class, 'compassLgsfDashboard']);
    Route::get('/api/compass/budget', [EGovIntegrationController::class, 'compassBudget']);
    Route::get('/api/v1/compass/budget', [EGovIntegrationController::class, 'compassBudget']);
});
