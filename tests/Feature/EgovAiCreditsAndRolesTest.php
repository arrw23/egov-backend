<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EGov\EGovAIService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Live-mode regressions found while auditing the eGov AI integration:
 *   - /credits forwarded the caller's Sanctum bearer to the government API,
 *     leaking session tokens and guaranteeing a 401.
 *   - a fresh upstream token was minted per call, and because credits are
 *     metered per token the usage counter could never leave zero.
 *   - mock login keyed its accounts by short alias, so the documented
 *     "agency_evaluator" / "hospital_staff" roles silently became applicant.
 *   - document classification served a template but labelled it AI output.
 */
class EgovAiCreditsAndRolesTest extends TestCase
{
    use RefreshDatabase;

    private string $aiBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->aiBase = (string) config('services.egov.ai.base_url');

        config([
            'services.egov.mode' => 'live',
            'services.egov.ai.access_code' => 'test_access_code',
        ]);
    }

    public function test_mock_login_resolves_canonical_role_names(): void
    {
        foreach (['agency_evaluator' => 'agency_evaluator', 'hospital_staff' => 'hospital_staff', 'agency' => 'agency_evaluator', 'hospital' => 'hospital_staff'] as $sent => $expected) {
            $res = $this->postJson('/api/v1/auth/mock/login', ['role' => $sent]);

            $res->assertStatus(200)
                ->assertJsonPath('user.role', $expected)
                ->assertJsonStructure(['token']);
        }
    }

    public function test_credits_never_forwards_the_callers_session_token_upstream(): void
    {
        $user = User::where('role', 'agency_evaluator')->firstOrFail();
        $sanctumToken = $user->createToken('web')->plainTextToken;

        Http::fake([
            $this->aiBase.'*/integration/token' => Http::response([
                'access_token' => 'UPSTREAM-AI-TOKEN',
                'expires_in_seconds' => 172800,
            ], 200),
            $this->aiBase.'*/integration/credits' => Http::response([
                'credits_total' => 200,
                'credits_used' => 3,
                'credits_remaining' => 197,
                'expires_at' => '2026-09-23T00:00:00.000+08:00',
            ], 200),
        ]);

        $this->getJson('/api/v1/egov/integration/credits', ['Authorization' => 'Bearer '.$sanctumToken])
            ->assertStatus(200)
            ->assertJsonPath('credits_remaining', 197)
            ->assertJsonPath('simulated', false);

        $credits = $this->recordedUpstream('/integration/credits');

        $this->assertSame(
            'Bearer UPSTREAM-AI-TOKEN',
            $credits->header('Authorization')[0] ?? null,
            'The caller\'s Sanctum bearer must never reach a third-party API.'
        );
        $this->assertStringNotContainsString($sanctumToken, (string) $credits->header('Authorization')[0]);
    }

    public function test_the_upstream_access_token_is_minted_once_for_consecutive_calls(): void
    {
        Http::fake([
            $this->aiBase.'*/integration/token' => Http::response([
                'access_token' => 'SHARED-AI-TOKEN',
                'expires_in_seconds' => 172800,
            ], 200),
            $this->aiBase.'*/ai_assistant/generate' => Http::response(['data' => 'answer', 'session_id' => 's1'], 200),
        ]);

        $service = app(EGovAIService::class);
        $service->aiAssistant('first question');
        $service->aiAssistant('second question');

        $tokenCalls = collect(Http::recorded())
            ->filter(fn ($recorded) => str_ends_with($recorded[0]->url(), '/integration/token'));

        // Credits are metered per access token, so one token per call kept the
        // balance permanently unspent.
        $this->assertCount(1, $tokenCalls, 'The upstream token should be reused within its validity window.');
    }

    public function test_classification_reports_real_ai_and_an_honest_ocr_state(): void
    {
        Http::fake([
            $this->aiBase.'*/integration/token' => Http::response(['access_token' => 'T', 'expires_in_seconds' => 172800], 200),
            $this->aiBase.'*/document_extractor/generate' => Http::response([
                'message' => 'E_INVALID_MULTIPART_REQUEST: Invalid multipart request',
                'code' => 'E_INVALID_MULTIPART_REQUEST',
            ], 400),
            $this->aiBase.'*/ai_assistant/generate' => Http::response([
                'data' => 'Confirm the patient name, bill number and total due against the hospital ledger.',
                'session_id' => 'sess-1',
            ], 200),
        ]);

        $file = UploadedFile::fake()->createWithContent('soa-2026.pdf', '%PDF-1.4 test');
        $result = app(EGovAIService::class)->classifyAndExtract('soa-2026.pdf', 'soa', $file);

        $this->assertSame('live_ai_assistant', $result['source']);
        $this->assertStringContainsString('bill number', $result['ai_analysis']);
        $this->assertSame('unavailable', $result['extraction']['status']);
        $this->assertNotNull($result['extraction']['message']);
        $this->assertNull($result['confidence'], 'A metadata label must not claim model confidence.');
        $this->assertStringNotContainsString('AI-generated extraction', $result['disclaimer']);
    }

    public function test_classification_falls_back_to_a_metadata_label_when_no_provider_answers(): void
    {
        Http::fake([
            $this->aiBase.'*/integration/token' => Http::response(['access_token' => 'T', 'expires_in_seconds' => 172800], 200),
            $this->aiBase.'*/ai_assistant/generate' => Http::response(['message' => 'boom'], 502),
        ]);

        $result = app(EGovAIService::class)->classifyAndExtract('indigency.jpg', 'indigency');

        $this->assertSame('sandbox_classifier', $result['source']);
        $this->assertNull($result['ai_analysis']);
        $this->assertStringContainsString('no AI provider result', $result['disclaimer']);
        $this->assertSame('Barangay Certificate of Indigency', $result['classified_type']);
    }

    private function recordedUpstream(string $suffix)
    {
        foreach (Http::recorded() as $recorded) {
            if (str_ends_with($recorded[0]->url(), $suffix)) {
                return $recorded[0];
            }
        }

        $this->fail("No outbound request recorded for {$suffix}.");
    }
}
