<?php

namespace Tests\Feature;

use App\Models\HospitalDocumentRequest;
use App\Models\MedicalCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression tests for the two routes that returned HTTP 500.
 */
class VerifiedFiveHundredsTest extends TestCase
{
    use RefreshDatabase;

    protected function seedDemo(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    /**
     * B1: exchangeToken() was called with one argument while its signature
     * required four, throwing ArgumentCountError (an Error, so the surrounding
     * catch (\Exception) missed it), and fetchProfile() did not exist.
     */
    public function test_egov_callback_route_does_not_error(): void
    {
        $this->seedDemo();

        $response = $this->getJson('/api/v1/auth/egov/callback?code=mock_code_applicant');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_egov_exchange_endpoint_creates_the_real_citizen(): void
    {
        $this->seedDemo();

        $response = $this->postJson('/api/v1/auth/egov/exchange', [
            'exchange_code' => 'mock_code_applicant',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('user.sub', 'MVPCBEUVCGPZR');

        // The profile fields are first_name/middle_name/last_name; there is no
        // "name" field on the SSO payload.
        $this->assertSame(
            'JOSIE SANTOS DELA CRUZ',
            $response->json('user.name'),
            'The SSO citizen name must be assembled from the real profile fields.'
        );

        $this->assertDatabaseHas('users', ['egov_sub' => 'MVPCBEUVCGPZR']);
    }

    public function test_egov_exchange_requires_an_exchange_code(): void
    {
        $this->seedDemo();

        $this->postJson('/api/v1/auth/egov/exchange', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('exchange_code');
    }

    /**
     * B2: the route parameter is {docReq} but the controller argument was named
     * $request, so implicit binding never resolved the model and
     * generateCaseSummary(null) threw.
     */
    public function test_hospital_request_detail_returns_the_bound_model(): void
    {
        $this->seedDemo();

        $docReq = HospitalDocumentRequest::firstOrFail();

        $response = $this->getJson("/api/v1/hospital/requests/{$docReq->id}");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('request.id', $docReq->id);

        $this->assertNotNull(
            $response->json('request.medical_case'),
            'The bound document request must include its medical case.'
        );
    }

    public function test_hospital_request_detail_404s_for_an_unknown_id(): void
    {
        $this->seedDemo();

        $this->getJson('/api/v1/hospital/requests/999999')->assertStatus(404);
    }
}
