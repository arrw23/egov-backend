<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicConfigAndChainHonestyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A4: the SSO partner code and liveness public key were copied into the
     * frontend in four places. They are public by design but must have a single
     * source of truth.
     */
    public function test_public_config_exposes_only_non_secret_values(): void
    {
        $response = $this->getJson('/api/v1/egov/public-config');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'sso' => ['partner_code', 'host'],
                'liveness' => ['pubkey', 'sdk_src'],
            ]);

        $this->assertSame(
            config('services.egov.sso.partner_code'),
            $response->json('sso.partner_code')
        );
        $this->assertSame(
            config('services.egov.everify.pubkey'),
            $response->json('liveness.pubkey')
        );
    }

    public function test_public_config_never_leaks_a_secret(): void
    {
        $payload = $this->getJson('/api/v1/egov/public-config')->json();
        $encoded = json_encode($payload);

        foreach ([
            'EGOV_SSO_PARTNER_SECRET' => config('services.egov.sso.partner_secret'),
            'EGOV_EVERIFY_CLIENT_SECRET' => config('services.egov.everify.client_secret'),
            'EGOV_FACE_LIVENESS_API_KEY' => config('services.egov.face_liveness.api_key'),
            'EGOV_AI_ACCESS_CODE' => config('services.egov.ai.access_code'),
            'EGOV_REPORT_ACCESS_CODE' => config('services.egov.report.access_code'),
            'EGOV_EMESSAGE_ACCESS_TOKEN' => config('services.egov.emessage.access_token'),
        ] as $name => $secret) {
            if (! is_string($secret) || $secret === '') {
                continue;
            }
            $this->assertStringNotContainsString(
                $secret,
                $encoded,
                "public-config must never expose {$name}."
            );
        }
    }

    /**
     * B8: git history shows verifyRecordOnBesu always returned verified=true,
     * which was meaningless because nothing was ever submitted to a chain.
     */
    public function test_chain_verification_does_not_claim_success_without_a_ledger(): void
    {
        $chain = app(\App\Services\EGov\EGovChainService::class);

        $this->assertTrue($chain->isSimulated(), 'No contract address is configured in tests.');

        $result = $chain->verifyRecordOnBesu('0xdeadbeef');

        $this->assertFalse(
            $result['result']['verified'],
            'A simulated ledger must not report a record as verified on-chain.'
        );
        $this->assertTrue($result['result']['simulated']);
        $this->assertSame('NOT_ANCHORED', $result['result']['state']);
    }

    public function test_anchoring_reports_itself_as_simulated(): void
    {
        $chain = app(\App\Services\EGov\EGovChainService::class);

        $anchor = $chain->anchorRecordOnBesu('DOC-1', hash('sha256', 'x'), 'TEST');

        $this->assertFalse($anchor['anchored']);
        $this->assertTrue($anchor['simulated']);
        $this->assertSame('Simulated ledger (no chain submission)', $anchor['result']['chain_name']);
    }

    /**
     * B8: the chain id disagreed between config (2026) and the mock endpoints
     * (0x343b = 13371).
     */
    public function test_chain_id_is_consistent_between_config_and_json_rpc(): void
    {
        $chain = app(\App\Services\EGov\EGovChainService::class);

        $netVersion = $chain->handleJsonRpc(['method' => 'net_version', 'params' => [], 'id' => 1]);
        $chainId = $chain->handleJsonRpc(['method' => 'eth_chainId', 'params' => [], 'id' => 1]);

        $this->assertSame($chain->chainId(), $netVersion['result']);
        $this->assertSame('0x' . dechex((int) $chain->chainId()), $chainId['result']);
    }

    /**
     * B10: generateDocumentHash returned a 16-character prefix.
     */
    public function test_document_hash_is_a_full_sha256(): void
    {
        $chain = app(\App\Services\EGov\EGovChainService::class);

        $hash = $chain->generateDocumentHash('some document content');

        $this->assertSame(64, strlen($hash));
        $this->assertSame(hash('sha256', 'some document content'), $hash);
    }
}
