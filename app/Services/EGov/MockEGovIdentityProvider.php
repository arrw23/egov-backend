<?php

namespace App\Services\EGov;

use App\Models\User;
use App\Models\Organization;

class MockEGovIdentityProvider implements EGovIdentityProvider
{
    protected array $mockAccounts = [
        'applicant' => [
            'sub' => 'MVPCBEUVCGPZR',
            'name' => 'JOSIE SANTOS DELA CRUZ',
            'email' => 'josie@yopmail.com',
            'role' => 'applicant',
            'org_code' => null,
            'verified' => true,
            'uniqid' => 'MVPCBEUVCGPZR',
            'pcn' => '9639954762664080',
            'mobile' => '+639090000000',
            'address' => '1123 RIZAL ST., POBLACION, CITY OF ALAMINOS, PANGASINAN, PHILIPPINES',
        ],
        'hospital_staff' => [
            'sub' => 'egov-sub-hospital-ana-002',
            'name' => 'Dr. Ana Reyes',
            'email' => 'ana.reyes@manilageneral.ph',
            'role' => 'hospital_staff',
            'org_code' => 'MGH-MANILA',
            'verified' => true,
        ],
        'agency_evaluator' => [
            'sub' => 'egov-sub-agency-miguel-003',
            'name' => 'Miguel dela Cruz',
            'email' => 'miguel.delacruz@dswd.gov.ph',
            'role' => 'agency_evaluator',
            'org_code' => 'DSWD-NCR',
            'verified' => true,
        ],
    ];

    /**
     * The UI sends the short forms below, the documented REST API the canonical
     * role names. Without this map a caller asking for "agency_evaluator" fell
     * through to the applicant account and never reached the agency portal.
     */
    protected const ROLE_ALIASES = [
        'hospital' => 'hospital_staff',
        'hospital_staff' => 'hospital_staff',
        'agency' => 'agency_evaluator',
        'agency_evaluator' => 'agency_evaluator',
        'applicant' => 'applicant',
    ];

    private function canonicalRole(string $roleHint): string
    {
        return self::ROLE_ALIASES[strtolower(trim($roleHint))] ?? 'applicant';
    }

    private function accountFor(string $roleHint): array
    {
        return $this->mockAccounts[$this->canonicalRole($roleHint)];
    }

    public function authorizationUrl(string $roleHint = 'applicant'): string
    {
        return "/api/v1/auth/egov/callback?code=mock_code_" . urlencode($roleHint);
    }

    public function exchangeCode(string $code): array
    {
        $roleHint = $this->canonicalRole(str_replace('mock_code_', '', urldecode($code)));
        $profile = $this->mockAccounts[$roleHint];

        return [
            'access_token' => 'mock_token_' . md5($roleHint),
            'sub' => $profile['sub'],
            'profile' => $profile,
        ];
    }

    public function validateToken(string $token): bool
    {
        return str_starts_with($token, 'mock_token_');
    }

    public function fetchProfile(string $subject): array
    {
        foreach ($this->mockAccounts as $acc) {
            if ($acc['sub'] === $subject) {
                return $acc;
            }
        }

        return $this->mockAccounts['applicant'];
    }

    public function logout(): void
    {
        // Session cleared
    }

    public function resolveUser(string $roleHint): User
    {
        $profile = $this->accountFor($roleHint);

        $orgId = null;
        if (!empty($profile['org_code'])) {
            $org = Organization::where('code', $profile['org_code'])->first();
            $orgId = $org?->id;
        }

        return User::updateOrCreate(
            ['egov_sub' => $profile['sub']],
            [
                'name' => $profile['name'],
                'email' => $profile['email'],
                'role' => $profile['role'],
                'organization_id' => $orgId,
                'verified_identity' => $profile['verified'],
                'avatar_url' => 'https://ui-avatars.com/api/?name=' . urlencode($profile['name']),
            ]
        );
    }
}
