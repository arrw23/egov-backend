<?php

namespace Tests\Feature;

use App\Services\EGov\EGovAIService;
use App\Services\EGov\EGovMode;
use App\Services\EGov\EGovSSOService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The providers are not equally demoable. eGov SSO needs a live citizen
 * handshake that cannot be replayed on stage, while eGov AI should be called
 * for real, so a single EGOV_MODE could not serve both.
 */
class EgovProviderModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_provider_override_pins_that_provider_only(): void
    {
        config([
            'services.egov.mode' => 'live',
            'services.egov.modes.sso' => 'sandbox',
            'services.egov.modes.ai' => null,
        ]);

        $this->assertTrue(EGovMode::isSandbox('sso'), 'SSO must honour its own override.');
        $this->assertTrue(EGovMode::isLive('ai'), 'AI must inherit the global mode.');
        $this->assertTrue(EGovMode::isLive('chain'), 'An unlisted provider inherits the global mode.');
        $this->assertTrue(EGovMode::isLive(), 'The global query is unaffected by overrides.');
    }

    public function test_sso_is_mocked_while_ai_calls_the_real_provider(): void
    {
        $ssoBase = (string) config('services.egov.sso.base_url');
        $aiBase = (string) config('services.egov.ai.base_url');

        config([
            'services.egov.mode' => 'live',
            'services.egov.modes.sso' => 'sandbox',
            'services.egov.modes.report' => 'sandbox',
            'services.egov.sso.partner_code' => 'partner',
            'services.egov.sso.partner_secret' => 'secret',
            'services.egov.ai.access_code' => 'ai_access_code',
        ]);

        Http::fake([
            $aiBase.'*/integration/token' => Http::response(['access_token' => 'AI-TOKEN', 'expires_in_seconds' => 172800], 200),
            $aiBase.'*/ai_assistant/generate' => Http::response(['data' => 'real answer', 'session_id' => 's'], 200),
        ]);

        $sso = app(EGovSSOService::class)->exchangeToken('ANY_STRING_WORKS_IN_SANDBOX');
        $this->assertSame(200, $sso['status']);
        $this->assertArrayHasKey('access_token', $sso['data']);

        $ai = app(EGovAIService::class)->aiAssistant('question');
        $this->assertSame(200, $ai['status']);
        $this->assertSame('real answer', $ai['data']['data'] ?? null);

        $urls = collect(Http::recorded())->map(fn ($pair) => $pair[0]->url())->values();

        $this->assertTrue(
            $urls->contains(fn ($url) => str_starts_with($url, $aiBase)),
            'The AI provider must really be called while the mode is live.'
        );
        $this->assertFalse(
            $urls->contains(fn ($url) => str_starts_with($url, $ssoBase)),
            'A sandboxed SSO must not send the demo exchange code upstream.'
        );
    }

    public function test_live_sso_reaches_the_provider_instead_of_inventing_a_token(): void
    {
        config([
            'services.egov.mode' => 'live',
            'services.egov.modes.sso' => 'live',
            'services.egov.sso.partner_code' => 'partner',
            'services.egov.sso.partner_secret' => 'secret',
        ]);

        Http::fake([
            config('services.egov.sso.base_url').'*' => Http::response([
                'message' => 'Invalid exchange_code',
                'errors' => ['exchange_code' => ['Invalid exchange_code']],
            ], 422),
        ]);

        $result = app(EGovSSOService::class)->exchangeToken('spent_single_use_code');

        // Fail closed: the provider's rejection is surfaced, not replaced with
        // the mock JWT that used to answer for any input at all.
        $this->assertSame(422, $result['status']);
        $this->assertArrayNotHasKey('access_token', $result['data']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/token'));
    }
}
