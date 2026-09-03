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
            'partner_secret' => (string) config('services.egov.sso.partner_secret'),
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
            'client_secret' => (string) config('services.egov.everify.client_secret'),
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

        // Credits
        $credits = $this->getJson('/api/v1/egov/integration/credits');
        $credits->assertStatus(200)->assertJsonStructure(['credits_total', 'credits_remaining']);
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

        // rpc_modules
        $mods = $this->postJson('/api/v1/egovchain/rpc', [
            'jsonrpc' => '2.0',
            'method' => 'rpc_modules',
            'params' => [],
            'id' => 1,
        ]);
        $mods->assertStatus(200)->assertJsonStructure(['result' => ['eth', 'net', 'web3', 'txpool']]);

        // net_version
        $net = $this->postJson('/api/v1/egovchain/rpc', [
            'jsonrpc' => '2.0',
            'method' => 'net_version',
            'params' => [],
            'id' => 1,
        ]);
        $net->assertStatus(200)->assertJsonPath('result', '13371');

        // eth_chainId
        $chain = $this->postJson('/api/v1/egovchain/rpc', [
            'jsonrpc' => '2.0',
            'method' => 'eth_chainId',
            'params' => [],
            'id' => 1,
        ]);
        $chain->assertStatus(200)->assertJsonPath('result', '0x343b');

        // eth_gasPrice (zero fee)
        $gas = $this->postJson('/api/v1/egovchain/rpc', [
            'jsonrpc' => '2.0',
            'method' => 'eth_gasPrice',
            'params' => [],
            'id' => 1,
        ]);
        $gas->assertStatus(200)->assertJsonPath('result', '0x0');

        // txpool_besuStatistics
        $txp = $this->postJson('/api/v1/egovchain/rpc', [
            'jsonrpc' => '2.0',
            'method' => 'txpool_besuStatistics',
            'params' => [],
            'id' => 1,
        ]);
        $txp->assertStatus(200)->assertJsonStructure(['result' => ['localCount', 'remoteCount']]);

        // eth_call - HackathonGuestbook teamCount()
        $call = $this->postJson('/api/v1/egovchain/rpc', [
            'jsonrpc' => '2.0',
            'method' => 'eth_call',
            'params' => [['to' => '0x52B6c6ffc6b5413F09C2E3C9a85703f848EaF014', 'data' => '0x7d0a5142'], 'latest'],
            'id' => 1,
        ]);
        $call->assertStatus(200)->assertJsonStructure(['result']);

        // egov_anchorRecord
        $anchor = $this->postJson('/api/v1/egovchain/anchor', [
            'record_id' => 'GL-DSWD-2026-04821',
            'hash' => '0xd8f2910c5d12a8f9104b2819c5b201f8',
        ]);
        $anchor->assertStatus(200)->assertJsonPath('result.chain_name', 'eGovChain (Hyperledger Besu)');
    }

    public function test_emessage_sms_push(): void
    {
        // 1. Successful push
        $res = $this->postJson('/messaging/v1/sms/push', [
            'number' => '+639090000000',
            'message' => 'Test message',
        ]);
        $res->assertStatus(201)
            ->assertJsonStructure(['data' => ['message']]);

        // 2. Validation error (missing required fields)
        $invalid = $this->postJson('/messaging/v1/sms/push', []);
        $invalid->assertStatus(422)
            ->assertJsonPath('error', 'unprocessable_entity');
    }

    public function test_egovpay_transactions(): void
    {
        // 1. Create Transaction (POST /api/v1/transaction)
        $createRes = $this->postJson('/api/v1/transaction', [
            'amount' => 1000,
            'txnid' => 'TESTREF123',
            'name' => 'JOSIE SANTOS DELA CRUZ',
            'items' => [
                ['name' => 'Medical Hospital Assistance Settlement', 'amount' => 1000]
            ],
        ]);
        $createRes->assertStatus(201)
            ->assertJsonStructure(['data' => ['uuid', 'url', 'channel' => ['refno']]]);

        $uuid = $createRes->json('data.uuid');

        // 2. Query Transaction Details (GET /api/v1/transaction/{uuid})
        $getRes = $this->getJson("/api/v1/transaction/{$uuid}");
        $getRes->assertStatus(200)
            ->assertJsonPath('data.uuid', $uuid);

        // 3. Void Transaction (PUT /api/v1/transaction/{uuid}/void)
        $voidRes = $this->putJson("/api/v1/transaction/{$uuid}/void");
        $voidRes->assertStatus(200)
            ->assertJsonStructure(['data' => ['message']]);

        // 4. Direct Settlement route
        $settleRes = $this->postJson('/api/v1/pay/settle', [
            'gl_number' => 'GL-DSWD-2026-04821',
            'amount' => 50000,
            'payee_organization' => 'Manila General Hospital',
        ]);
        $settleRes->assertStatus(200)
            ->assertJsonPath('status', 'initiated');
    }

    public function test_ereport_integration_flows(): void
    {
        // 1. Generate Token
        $tokenRes = $this->postJson('/api/integration/token', [
            'access_code' => '2a72bdcac1b0405fb2c679d029f03cfb',
        ]);
        $tokenRes->assertStatus(200)
            ->assertJsonStructure(['access_token', 'expires_at']);

        $token = $tokenRes->json('access_token');

        // 2. Report Type List
        $typesRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/integration/datasets/report_types');
        $typesRes->assertStatus(200)
            ->assertJsonStructure(['jsonapi', 'data']);

        // 3. Region List
        $regionsRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/integration/datasets/regions');
        $regionsRes->assertStatus(200)
            ->assertJsonStructure(['jsonapi', 'data']);

        // 4. Province List by Params
        $provincesRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/integration/datasets/provinces?region_code=040000000');
        $provincesRes->assertStatus(200)
            ->assertJsonStructure(['jsonapi', 'data']);

        // 5. Municipality List by Params
        $munisRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/integration/datasets/municipalities?province_code=042100000');
        $munisRes->assertStatus(200)
            ->assertJsonStructure(['jsonapi', 'data']);

        // 6. Barangay List by Params
        $brgyRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/integration/datasets/barangays?municipality_code=042111000');
        $brgyRes->assertStatus(200)
            ->assertJsonStructure(['jsonapi', 'data']);

        // 7. Submit Complaint
        $complaintRes = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/integration/submit_complaint', [
                'mobile' => '639090000000',
                'first_name' => 'Josie',
                'last_name' => 'Dela Cruz',
                'gender' => 'Female',
                'complainant_email' => 'josie@yopmail.com',
                'report_type' => 'red_tape',
                'subject' => 'Delayed Medical Clearance Evaluation',
                'message' => 'Hospital social work assessment processing exceeded standard processing time.',
                'region_code' => '040000000',
                'province_code' => '042100000',
                'municipality_code' => '042111000',
                'barangay_code' => '042111011',
            ]);
        $complaintRes->assertStatus(200)
            ->assertJsonPath('code', 200)
            ->assertJsonStructure(['case_number']);

        $caseNumber = $complaintRes->json('case_number');

        // 8. Verify - Request OTP
        $otpReq = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/integration/verify/request', [
                'email' => 'josie@yopmail.com',
            ]);
        $otpReq->assertStatus(200)
            ->assertJsonPath('code', 200);

        // 9. Verify - Confirm OTP
        $otpConf = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/integration/verify/confirm', [
                'email' => 'josie@yopmail.com',
                'otp' => '000000',
            ]);
        $otpConf->assertStatus(200)
            ->assertJsonStructure(['code', 'report_view_token']);

        $viewToken = $otpConf->json('report_view_token');

        // 10. Reports List
        $reportsRes = $this->withHeader('X-EReport-View-Token', $viewToken)
            ->getJson('/api/integration/reports');
        $reportsRes->assertStatus(200)
            ->assertJsonStructure(['jsonapi', 'data']);

        // 11. View Report by Case Number
        $viewRes = $this->withHeader('X-EReport-View-Token', $viewToken)
            ->getJson("/api/integration/reports/{$caseNumber}");
        $viewRes->assertStatus(200)
            ->assertJsonStructure(['data' => ['id', 'case_number', 'complainant']]);
    }

    public function test_compass_integration_records(): void
    {
        // 1. SAAODB Dashboard
        $dashRes = $this->getJson('/api/v1/records/saaodb/dashboard?reportYear=2026&sheetScope=summary');
        $dashRes->assertStatus(200)
            ->assertJsonStructure(['reportYear', 'sheetScope', 'cascade', 'rates', 'classBreakdown']);

        // 2. SAAODB Records
        $recRes = $this->getJson('/api/v1/records/saaodb?reportYear=2026&period=FY&class=PS&sheetScope=summary&entityName=Agriculture&page=1&limit=100');
        $recRes->assertStatus(200)
            ->assertJsonStructure(['data', 'total', 'page', 'limit']);

        // 3. SAAODB Entities
        $entRes = $this->getJson('/api/v1/records/saaodb/entities?reportYear=2026&sheetScope=agency&expandParent=Department of Finance');
        $entRes->assertStatus(200)
            ->assertJsonStructure(['reportYear', 'sheetScope', 'entities']);

        // 4. NCA Records
        $ncaRes = $this->getJson('/api/v1/records/nca?budgetYear=2026&deptCode=010000000000&agencyCode=010010000000&page=1&limit=100');
        $ncaRes->assertStatus(200)
            ->assertJsonStructure(['data', 'total', 'page', 'limit']);

        // 5. SARO Records
        $saroRes = $this->getJson('/api/v1/records/saro?saroNo=SARO-BMB-A-26-0000001&page=1&limit=100');
        $saroRes->assertStatus(200)
            ->assertJsonStructure(['data', 'total', 'page', 'limit']);

        // 6. LGSF Records
        $lgsfRes = $this->getJson('/api/v1/records/lgsf?fiscalYear=2026&programCode=FALGU&province=Bulacan&cityMunicipality=Malolos&page=1&limit=100');
        $lgsfRes->assertStatus(200)
            ->assertJsonStructure(['data', 'total', 'page', 'limit']);

        // 7. LGSF Dashboard
        $lgsfDash = $this->getJson('/api/v1/records/lgsf/dashboard?programCode=FALGU&reportYear=2026&province=Bulacan&municipality=Malolos&page=1&limit=25');
        $lgsfDash->assertStatus(200)
            ->assertJsonStructure(['programCode', 'reportYear', 'kpis', 'projects']);

        // 8. DSWD Live Budget Status
        $budRes = $this->getJson('/api/compass/budget?program_code=DSWD-AICS');
        $budRes->assertStatus(200)
            ->assertJsonStructure(['program_code', 'total_allocation', 'utilized_amount', 'remaining_balance']);
    }
}


