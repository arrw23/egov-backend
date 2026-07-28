<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\EGov\MockEGovIdentityProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function redirect(Request $request, MockEGovIdentityProvider $provider): JsonResponse
    {
        $roleHint = $request->query('role', 'applicant');
        $url = $provider->authorizationUrl($roleHint);

        return response()->json([
            'status' => 'success',
            'redirect_url' => $url,
            'badge' => 'Authenticated through simulated eGovPH SSO',
        ]);
    }

    public function callback(Request $request, MockEGovIdentityProvider $provider): JsonResponse
    {
        $code = $request->query('code', 'mock_code_applicant');
        $result = $provider->exchangeCode($code);
        $user = $provider->resolveUser(str_replace('mock_code_', '', $code));

        try {
            $sso = app(\App\Services\EGov\EGovSSOService::class);
            $tokenData = $sso->exchangeToken($code);
            $token = $tokenData['access_token'] ?? 'mock_token';
            $profile = $sso->fetchProfile($token);
            
            // Create/update user from profile data
            $user->update([
                'name' => $profile['name'] ?? $user->name,
                'email' => $profile['email'] ?? $user->email,
            ]);
            
            app(\App\Services\EGov\EReportService::class)->submitAuditReport('SSO_LOGIN', ['sub' => $user->egov_sub], $user);
        } catch (\Exception $e) {
            // Ignore error, fallback to mock behavior
        }

        Auth::login($user);

        return response()->json([
            'status' => 'success',
            'token' => $result['access_token'],
            'user' => [
                'id' => $user->id,
                'sub' => $user->egov_sub,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'organization' => $user->organization ? [
                    'id' => $user->organization->id,
                    'name' => $user->organization->name,
                    'code' => $user->organization->code,
                    'type' => $user->organization->type,
                ] : null,
                'verified_identity' => $user->verified_identity,
            ],
            'badge' => 'Authenticated through simulated eGovPH SSO',
        ]);
    }

    public function mockLogin(Request $request, MockEGovIdentityProvider $provider): JsonResponse
    {
        $role = $request->input('role', 'applicant');
        $user = $provider->resolveUser($role);

        Auth::login($user);

        return response()->json([
            'status' => 'success',
            'user' => [
                'id' => $user->id,
                'sub' => $user->egov_sub,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'organization' => $user->organization ? [
                    'id' => $user->organization->id,
                    'name' => $user->organization->name,
                    'code' => $user->organization->code,
                    'type' => $user->organization->type,
                ] : null,
                'verified_identity' => $user->verified_identity,
            ],
            'badge' => 'Authenticated through simulated eGovPH SSO',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            // Default fallback to applicant if session uninitialized for demo
            $provider = new MockEGovIdentityProvider();
            $user = $provider->resolveUser('applicant');
            Auth::login($user);
        }

        return response()->json([
            'status' => 'success',
            'user' => [
                'id' => $user->id,
                'sub' => $user->egov_sub,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'organization' => $user->organization ? [
                    'id' => $user->organization->id,
                    'name' => $user->organization->name,
                    'code' => $user->organization->code,
                    'type' => $user->organization->type,
                ] : null,
                'verified_identity' => $user->verified_identity,
                'applicant_profile' => $user->applicantProfile,
            ],
        ]);
    }

    public function logout(): JsonResponse
    {
        Auth::logout();

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully.',
        ]);
    }
}
