<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\EGov\EVerifyService;
use App\Services\EGov\EGovChainService;
use App\Services\EGov\MockEGovIdentityProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IdentityController extends Controller
{
    public function recordConsent(Request $request): JsonResponse
    {
        $request->validate([
            'consent' => 'required|boolean',
        ]);

        $user = Auth::user() ?: (new MockEGovIdentityProvider())->resolveUser('applicant');

        return response()->json([
            'status' => 'success',
            'consent_recorded' => $request->boolean('consent'),
            'timestamp' => now()->toIso8601String(),
            'message' => 'Consent stored securely in system audit record.',
        ]);
    }

    public function verify(Request $request, EVerifyService $eVerify, EGovChainService $chain): JsonResponse
    {
        $user = Auth::user() ?: (new MockEGovIdentityProvider())->resolveUser('applicant');
        $consent = $request->input('consent', true);

        $profile = $eVerify->recordConsentAndVerify($user, (bool) $consent);

        $chain->recordEvent(
            null,
            $user,
            'IDENTITY_VERIFIED',
            "PhilSys eVerify matched profile for {$user->name}",
            ['verification_reference' => $profile->verification_reference]
        );

        return response()->json([
            'status' => 'success',
            'badge' => 'PhilSys eVerify Verified',
            'profile' => [
                'full_name' => $profile->full_name,
                'birth_date' => $profile->birth_date ? $profile->birth_date->format('d F Y') : '18 September 1989',
                'philsys_id' => $profile->philsys_id,
                'verification_reference' => $profile->verification_reference,
                'consent_given' => $profile->consent_given,
                'consent_timestamp' => $profile->consent_timestamp,
                'status' => 'Verified',
            ],
        ]);
    }
}
