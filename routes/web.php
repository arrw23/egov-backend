<?php

use App\Http\Controllers\Api\V1\EGovIntegrationController;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Support\Facades\Route;

Route::middleware([HandleCors::class])->group(function () {
    Route::get('/', function () {
        return response()->json([
            'system' => "eGov's eGuarantee Platform",
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
});
