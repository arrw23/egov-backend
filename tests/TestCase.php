<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Fail loudly on any outbound HTTP call that a test has not explicitly
        // faked. Without this, the suite reaches live eMessage / eGovPay /
        // eVerify and fails with provider errors (e.g. HTTP 429) that have
        // nothing to do with the code under test.
        Http::preventStrayRequests();
    }

    /**
     * Act as the given user for subsequent requests.
     *
     * Routes are protected by auth:sanctum + role middleware, so tests must
     * authenticate like a real client does rather than relying on the old
     * Auth::user() ?: resolveUser() fallback.
     */
    protected function actingAsUser(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /**
     * Act as the seeded user with the given role.
     */
    protected function actingAsRole(string $role): static
    {
        $user = User::where('role', $role)->firstOrFail();

        return $this->actingAsUser($user);
    }
}
