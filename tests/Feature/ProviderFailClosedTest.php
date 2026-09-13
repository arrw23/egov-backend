<?php

namespace Tests\Feature;

use App\Services\EGov\EGovAIService;
use App\Services\EGov\EReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B13: in live mode an upstream failure must return an error, never canned
 * data. The adapters originally decided this by sniffing whether their base URL
 * started with "https://", and several fell through to fabricated data when a
 * secondary guard (e.g. "&& $authToken") was false.
 */
class ProviderFailClosedTest extends TestCase
{
    use RefreshDatabase;

    protected function liveMode(): void
    {
        config(['services.egov.mode' => 'live']);
    }

    protected function sandboxMode(): void
    {
        config(['services.egov.mode' => 'sandbox']);
    }

    public function test_live_mode_without_an_ereport_token_does_not_return_canned_datasets(): void
    {
        $this->liveMode();
        config(['services.egov.report.access_token' => null]);

        $service = app(EReportService::class);

        foreach (['getReportTypes', 'getRegions'] as $method) {
            $result = $service->{$method}(null);

            $this->assertSame(
                503,
                $result['status'],
                "{$method}() must fail closed in live mode, not serve canned data."
            );
            $this->assertArrayNotHasKey(
                'data',
                $result['data'],
                "{$method}() must not return a fabricated dataset."
            );
        }
    }

    public function test_sandbox_mode_still_serves_the_canned_datasets(): void
    {
        $this->sandboxMode();

        $result = app(EReportService::class)->getReportTypes();

        $this->assertSame(200, $result['status']);
        $this->assertNotEmpty($result['data']['data'] ?? []);
    }

    public function test_document_extraction_fails_closed_without_a_file_in_live_mode(): void
    {
        $this->liveMode();

        $result = app(EGovAIService::class)->documentExtractor(null);

        $this->assertSame(422, $result['status']);
        $this->assertStringNotContainsString(
            "Driver's License",
            json_encode($result),
            'No canned extraction may be returned in live mode.'
        );
    }

    public function test_audit_report_does_not_pretend_to_be_filed(): void
    {
        $this->sandboxMode();

        $result = app(EReportService::class)->submitAuditReport('TEST_ACTION', ['a' => 1]);

        $this->assertSame('not_submitted', $result['status']);
        $this->assertFalse($result['submitted']);
        $this->assertArrayNotHasKey(
            'report_id',
            $result,
            'A local audit entry must not carry an invented eReport filing id.'
        );
        $this->assertNotEmpty($result['payload_hash']);
    }
}
