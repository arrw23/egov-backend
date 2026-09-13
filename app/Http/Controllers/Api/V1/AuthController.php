<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EGov\EGovSSOService;
use App\Services\EGov\EReportService;
use App\Services\EGov\MockEGovIdentityProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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

    /**
     * Legacy GET callback. Kept working for the mock identity provider, and
     * now tolerant of upstream failure instead of returning a 500.
     */
    public function callback(Request $request, MockEGovIdentityProvider $provider): JsonResponse
    {
        $code = $request->query('code', 'mock_code_applicant');
        $result = $provider->exchangeCode($code);
        $user = $provider->resolveUser(str_replace('mock_code_', '', $code));

        try {
            $sso = app(EGovSSOService::class);
            $tokenData = $sso->exchangeToken($code);
            $token = $tokenData['data']['access_token'] ?? null;

            if ($token) {
                $profile = $sso->fetchSsoProfile($token);
                $data = $profile['data'] ?? [];

                if (! empty($data['uniqid'])) {
                    $user->update([
                        'name' => $this->profileName($data) ?: $user->name,
                        'email' => $data['email'] ?? $user->email,
                        'mobile' => $data['mobile'] ?? $user->mobile,
                    ]);
                }
            }

            app(EReportService::class)->submitAuditReport('SSO_LOGIN', ['sub' => $user->egov_sub], $user);
        } catch (\Throwable $e) {
            // An ArgumentCountError or a bad profile shape is an Error, not an
            // Exception, so catching \Exception alone let it become a 500.
            Log::warning('eGov SSO callback degraded to mock identity.', ['error' => $e->getMessage()]);
        }

        Auth::login($user);

        return response()->json([
            'status' => 'success',
            'token' => $result['access_token'],
            'user' => $this->userPayload($user),
            'badge' => 'Authenticated through simulated eGovPH SSO',
        ]);
    }

    /**
     * POST /api/v1/auth/egov/exchange { exchange_code }
     *
     * Redeems a single-use eGov SSO exchange code, resolves the real citizen
     * profile, upserts the local user and returns that user. This is the
     * endpoint the SSO page uses; the old flow exchanged the token but then
     * signed the visitor in as the mock applicant regardless of who they were.
     */
    public function exchange(Request $request, EGovSSOService $sso): JsonResponse
    {
        $validated = $request->validate([
            'exchange_code' => ['required', 'string'],
        ]);

        $tokenData = $sso->exchangeToken($validated['exchange_code']);
        $status = $tokenData['status'] ?? 500;

        if ($status !== 200) {
            return response()->json(
                $tokenData['data'] ?? ['message' => 'eGov SSO token exchange failed.'],
                $status
            );
        }

        $accessToken = $tokenData['data']['access_token'] ?? null;
        if (! $accessToken) {
            return response()->json(['message' => 'eGov SSO returned no access token.'], 502);
        }

        $profileRes = $sso->fetchSsoProfile($accessToken);
        $profileStatus = $profileRes['status'] ?? 500;

        if ($profileStatus !== 200) {
            return response()->json(
                $profileRes['data'] ?? ['message' => 'eGov SSO profile lookup failed.'],
                $profileStatus
            );
        }

        $data = $profileRes['data'] ?? [];
        $sub = $data['uniqid'] ?? null;

        if (! $sub) {
            return response()->json(['message' => 'eGov SSO profile is missing a UniqID.'], 502);
        }

        $user = User::updateOrCreate(
            ['egov_sub' => $sub],
            array_filter([
                'name' => $this->profileName($data),
                'email' => $data['email'] ?? null,
                'mobile' => $data['mobile'] ?? null,
            ], fn ($value) => $value !== null && $value !== '')
        );

        app(EReportService::class)->submitAuditReport('SSO_LOGIN', ['sub' => $user->egov_sub], $user);

        return response()->json([
            'status' => 'success',
            'token' => $user->createToken('web')->plainTextToken,
            'user' => $this->userPayload($user),
            'profile' => $data,
        ]);
    }

    public function mockLogin(Request $request, MockEGovIdentityProvider $provider): JsonResponse
    {
        $role = $request->input('role', 'applicant');
        $user = $provider->resolveUser($role);

        Auth::login($user);

        return response()->json([
            'status' => 'success',
            'token' => $user->createToken('web')->plainTextToken,
            'user' => $this->userPayload($user),
            'badge' => 'Authenticated through simulated eGovPH SSO',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return response()->json([
            'status' => 'success',
            'user' => $this->userPayload($user) + [
                'applicant_profile' => $user->applicantProfile,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // Revoke the token that authenticated this request.
        $token = $request->user()?->currentAccessToken();
        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        Auth::logout();

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * The SSO profile uses data.first_name / middle_name / last_name / suffix;
     * there is no "name" field.
     */
    private function profileName(array $data): ?string
    {
        $name = trim(implode(' ', array_filter([
            $data['first_name'] ?? null,
            $data['middle_name'] ?? null,
            $data['last_name'] ?? null,
            $data['suffix'] ?? null,
        ])));

        return $name !== '' ? $name : ($data['name'] ?? null);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'sub' => $user->egov_sub,
            'name' => $user->name,
            'email' => $user->email,
            'mobile' => $user->mobile,
            'role' => $user->role,
            'organization' => $user->organization ? [
                'id' => $user->organization->id,
                'name' => $user->organization->name,
                'code' => $user->organization->code,
                'type' => $user->organization->type,
            ] : null,
            'verified_identity' => $user->verified_identity,
        ];
    }
}
