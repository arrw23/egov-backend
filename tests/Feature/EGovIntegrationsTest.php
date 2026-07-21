<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EGovIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_egov_sso_token_exchange_and_profile_fetching(): void
    {
        // 1. POST /api/token
        $tokenRes = $this->postJson('/api/token', [
            'exchange_code' => 'generated_exchange_code',
            'scope' => 'SSO_AUTHENTICATION',
            'partner_code' => 'HACKATHON_SSO',
            'partner_secret' => '0d77fba530ee49f5b00e36fe947bd384',
        ]);

        $tokenRes->assertStatus(200)
            ->assertJsonStructure(['access_token']);

        $token = $tokenRes->json('access_token');

        // 2. POST /api/partner/sso_authentication
        $profileRes = $this->postJson('/api/partner/sso_authentication', [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $profileRes->assertStatus(200)
            ->assertJsonPath('data.uniqid', 'MVPCBEUVCGPZR')
            ->assertJsonPath('data.email', 'josie@yopmail.com')
            ->assertJsonPath('data.first_name', 'JOSIE')
            ->assertJsonPath('data.national_id.pcn', '9639954762664080');
    }

    public function test_everify_authentication_and_queries(): void
    {
        // 1. Auth: POST /api/auth
        $authRes = $this->postJson('/api/auth', [
            'client_id' => 'a24bef86-8826-48f7-aac5-978ca5805c29',
            'client_secret' => '1EQT3mEC8GqEYCcUufaylPewnWi052VcJdnAOmIPHFy5zbUv0JcqVEwf7DSeb1OB',
        ]);
        $authRes->assertStatus(200)
            ->assertJsonStructure(['data' => ['access_token', 'token_type', 'expires_at']]);

        // 2. Query Demographics: POST /api/query
        $queryRes = $this->postJson('/api/query', [
            'first_name' => 'Juan',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'suffix' => 'JR',
            'birth_date' => '1989-09-12',
            'face_liveness_session_id' => 'a1b3fae6-af74-4896-bd58-32a81604de01',
        ]);
        $queryRes->assertStatus(200)
            ->assertJsonPath('meta.tier_level', 'Tier II');

        // 3. QR Check: POST /api/query/qr/check
        $qrCheck = $this->postJson('/api/query/qr/check', ['value' => 'RAW_QR']);
        $qrCheck->assertStatus(200)
            ->assertJsonPath('meta.qr_type', 'Philsys Card Number');

        // 4. QR Verify: POST /api/query/qr
        $qrVerify = $this->postJson('/api/query/qr', [
            'value' => 'RAW_QR',
            'face_liveness_session_id' => 'a1b3fae6-af74-4896-bd58-32a81604de01',
        ]);
        $qrVerify->assertStatus(200)
            ->assertJsonPath('meta.tier_level', 'Tier II');
    }

    public function test_face_liveness_session_creation_and_result(): void
    {
        $sessionRes = $this->postJson('/v1/liveness/session', [
            'action' => 'redirect',
            'callback_url' => 'https://your-app.com/callback',
            'delay' => 3000,
        ]);
        $sessionRes->assertStatus(201)
            ->assertJsonStructure(['token', 'url']);

        $token = $sessionRes->json('token');
        $resultRes = $this->getJson("/v1/liveness/result/{$token}");
        $resultRes->assertStatus(200)
            ->assertJsonPath('status', 'SUCCEEDED')
            ->assertJsonPath('confidence_score', 98.71);
    }

    public function test_egov_ai_endpoints(): void
    {
        // Token
        $token = $this->postJson('/api/v1/egov/integration/token', ['access_code' => 'test_code']);
        $token->assertStatus(200)->assertJsonStructure(['access_token']);

        // AI Assistant
        $ai = $this->postJson('/api/v1/egov/integration/ai_assistant/generate', ['prompt' => 'How to apply', 'category' => 'PH']);
        $ai->assertStatus(200)->assertJsonStructure(['data', 'session_id']);

        // Speech Maker
        $speech = $this->postJson('/api/v1/egov/integration/speech_maker/generate', ['prompt' => 'Trends', 'category' => 'PH']);
        $speech->assertStatus(200)->assertJsonStructure(['data', 'session_id']);

        // Tourism
        $tour = $this->postJson('/api/v1/egov/integration/tourism/generate', ['prompt' => 'Boracay', 'category' => 'PH']);
        $tour->assertStatus(200)->assertJsonStructure(['data', 'session_id']);

        // Laws & Regulations
        $laws = $this->postJson('/api/v1/egov/integration/laws_and_regulations/generate', ['prompt' => 'Data privacy', 'category' => 'PH']);
        $laws->assertStatus(200)->assertJsonStructure(['data', 'session_id']);

        // Translator
        $trans = $this->postJson('/api/v1/egov/integration/translator/generate', ['prompt' => 'Hello world', 'source_lang' => 'en', 'target_lang' => 'fil']);
        $trans->assertStatus(200)->assertJsonStructure(['translated_prompt']);
    }

    public function test_egovchain_hyperledger_besu_json_rpc(): void
    {
        // eth_blockNumber
        $block = $this->postJson('/api/v1/egovchain/rpc', [
            'jsonrpc' => '2.0',
            'method' => 'eth_blockNumber',
            'params' => [],
            'id' => 1,
        ]);
        $block->assertStatus(200)->assertJsonPath('jsonrpc', '2.0');

        // egov_anchorRecord
        $anchor = $this->postJson('/api/v1/egovchain/anchor', [
            'record_id' => 'GL-DSWD-2026-04821',
            'hash' => '0xd8f2910c5d12a8f9104b2819c5b201f8',
        ]);
        $anchor->assertStatus(200)->assertJsonPath('result.chain_name', 'eGovChain (Hyperledger Besu)');
    }
}
