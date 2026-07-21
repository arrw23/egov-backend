<?php

namespace App\Services\EGov;

use App\Models\User;

interface EGovIdentityProvider
{
    public function authorizationUrl(string $roleHint = 'applicant'): string;

    public function exchangeCode(string $code): array;

    public function validateToken(string $token): bool;

    public function fetchProfile(string $subject): array;

    public function logout(): void;
}
