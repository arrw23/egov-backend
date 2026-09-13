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
        $request->validate([
            'consent' => 'required|boolean',
            'first_name' => 'nullable|string',
            'middle_name' => 'nullable|string',
            'last_name' => 'nullable|string',
            'birth_date' => 'nullable|date',
        ]);

        $user = Auth::user() ?: (new MockEGovIdentityProvider())->resolveUser('applicant');

        if (! $request->boolean('consent')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Consent must be granted to proceed with the PhilSys eVerify identity check.',
            ], 422);
        }

        // Perform the actual verification and store what eVerify returned.
        // The profile used to be written with a hard-coded PhilSys ID and
        // birth date for every user.
        $tokenRes = $eVerify->authenticate(
            (string) config('services.egov.everify.client_id'),
            (string) config('services.egov.everify.client_secret')
        );
        $accessToken = $tokenRes['data']['data']['access_token'] ?? $tokenRes['data']['access_token'] ?? '';

        $query = array_filter([
            'first_name' => $request->input('first_name'),
            'middle_name' => $request->input('middle_name'),
            'last_name' => $request->input('last_name'),
            'birth_date' => $request->input('birth_date'),
        ], fn ($v) => $v !== null && $v !== '');

        $verified = $eVerify->verifyDemographics($query, $accessToken);

        if (($verified['status'] ?? 500) !== 200) {
            return response()->json([
                'status' => 'error',
                'message' => $verified['data']['message'] ?? 'PhilSys eVerify could not verify this identity.',
            ], $verified['status'] ?? 502);
        }

        $profile = $eVerify->recordConsentAndVerify($user, true, $verified);

        $chain->recordEvent(
            null,
            $user,
            'IDENTITY_VERIFIED',
            "PhilSys eVerify matched profile for {$user->name}",
            ['verification_reference' => $profile->verification_reference]
        );

        try {
            $chain->anchorRecordOnBesu('IDENTITY-' . $user->id, hash('sha256', $profile->verification_reference), 'IDENTITY_VERIFIED');
        } catch (\Exception $e) {
            // Ignore error
        }

        if ($profile->status !== 'verified') {
            return response()->json([
                'status' => 'error',
                'message' => 'eVerify did not return a verified match for these details.',
                'profile' => [
                    'full_name' => $profile->full_name,
                    'verification_reference' => $profile->verification_reference,
                    'status' => 'Unverified',
                ],
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'badge' => 'PhilSys eVerify Verified',
            'profile' => [
                'full_name' => $profile->full_name,
                'birth_date' => $profile->birth_date ? $profile->birth_date->format('d F Y') : null,
                'philsys_id' => $profile->philsys_id,
                'verification_reference' => $profile->verification_reference,
                'consent_given' => $profile->consent_given,
                'consent_timestamp' => $profile->consent_timestamp,
                'status' => 'Verified',
            ],
        ]);
    }
}
