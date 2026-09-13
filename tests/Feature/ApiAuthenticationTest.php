<?php

namespace Tests\Feature;

use App\Models\MedicalCase;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A6: routes had no middleware at all, and every controller fell back to
 * Auth::user() ?: resolveUser(...). Anyone could certify documents, approve
 * guarantee letters or record utilizations with a plain curl.
 */
class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public static function protectedRoutes(): array
    {
        return [
            'me' => ['get', '/api/v1/me'],
            'list cases' => ['get', '/api/v1/cases'],
            'create case' => ['post', '/api/v1/cases'],
            'case detail' => ['get', '/api/v1/cases/1'],
            'upload document' => ['post', '/api/v1/cases/1/documents'],
            'hospital queue' => ['get', '/api/v1/hospital/requests'],
            'hospital request' => ['get', '/api/v1/hospital/requests/1'],
            'certify document' => ['post', '/api/v1/documents/1/certify'],
            'record utilization' => ['post', '/api/v1/guarantees/1/utilizations'],
            'agency inbox' => ['get', '/api/v1/agency/applications'],
            'agency decision' => ['post', '/api/v1/agency/applications/1/decision'],
            'notifications' => ['get', '/api/v1/notifications'],
            'verify identity' => ['post', '/api/v1/identity/verify'],
            'emessage send' => ['post', '/api/v1/emessage/send'],
            'egov ai token' => ['post', '/api/v1/egov/sso/token'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('protectedRoutes')]
    public function test_protected_routes_reject_anonymous_callers(string $method, string $uri): void
    {
        $response = $this->json($method, $uri);

        $response->assertStatus(401);
    }

    public function test_role_middleware_refuses_the_wrong_role(): void
    {
        $applicant = User::where('role', 'applicant')->firstOrFail();
        $this->actingAsUser($applicant);

        // An applicant must not reach the hospital or agency portals.
        $this->getJson('/api/v1/hospital/requests')->assertStatus(403);
        $this->getJson('/api/v1/agency/applications')->assertStatus(403);

        $hospital = User::where('role', 'hospital_staff')->firstOrFail();
        $this->actingAsUser($hospital);

        $this->getJson('/api/v1/agency/applications')->assertStatus(403);
        $this->getJson('/api/v1/cases')->assertStatus(403);
    }

    public function test_applicant_cannot_read_another_applicants_case(): void
    {
        $applicant = User::where('role', 'applicant')->firstOrFail();

        $otherCase = MedicalCase::where('applicant_id', '!=', $applicant->id)->firstOrFail();

        $this->actingAsUser($applicant);

        $this->getJson("/api/v1/cases/{$otherCase->id}")->assertStatus(403);
    }

    public function test_applicant_can_read_their_own_case(): void
    {
        $applicant = User::where('role', 'applicant')->firstOrFail();
        $ownCase = MedicalCase::where('applicant_id', $applicant->id)->firstOrFail();

        $this->actingAsUser($applicant);

        $this->getJson("/api/v1/cases/{$ownCase->id}")->assertStatus(200);
    }

    public function test_hospital_staff_only_see_their_own_hospitals_requests(): void
    {
        $hospital = User::where('role', 'hospital_staff')->firstOrFail();

        $this->actingAsUser($hospital);

        $response = $this->getJson('/api/v1/hospital/requests');
        $response->assertStatus(200);

        foreach ($response->json('requests') as $request) {
            $this->assertSame(
                (int) $hospital->organization_id,
                (int) $request['hospital_id'],
                'The hospital queue must not leak other hospitals\' requests.'
            );
        }
    }

    public function test_mock_login_returns_a_usable_token(): void
    {
        $response = $this->postJson('/api/v1/auth/mock/login', ['role' => 'applicant']);

        $response->assertStatus(200)->assertJsonPath('status', 'success');

        $token = $response->json('token');
        $this->assertNotEmpty($token, 'Sign-in must return a Sanctum token.');

        // The token alone must authenticate a protected request.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertStatus(200)
            ->assertJsonPath('user.role', 'applicant');
    }

    public function test_public_config_needs_no_authentication(): void
    {
        // The login page itself depends on this being reachable pre-sign-in.
        $this->getJson('/api/v1/egov/public-config')->assertStatus(200);
    }
}
