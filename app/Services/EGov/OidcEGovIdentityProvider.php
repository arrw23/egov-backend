<?php

namespace App\Services\EGov;

use RuntimeException;

class OidcEGovIdentityProvider implements EGovIdentityProvider
{
    public function authorizationUrl(string $roleHint = 'applicant'): string
    {
        $baseUrl = config('services.egov.oidc_url', 'https://sso.egov.ph/authorize');
        $clientId = config('services.egov.client_id', 'eguarantee_app');
        return "{$baseUrl}?client_id={$clientId}&response_type=code&scope=openid+profile+philsys&role_hint={$roleHint}";
    }

    public function exchangeCode(string $code): array
    {
        throw new RuntimeException("OIDC production provider not configured for hackathon environment.");
    }

    public function validateToken(string $token): bool
    {
        return false;
    }

    public function fetchProfile(string $subject): array
    {
        throw new RuntimeException("OIDC production provider not configured for hackathon environment.");
    }

    public function logout(): void
    {
    }
}
