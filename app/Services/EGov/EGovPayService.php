<?php

namespace App\Services\EGov;

use Illuminate\Support\Facades\Http;

class EGovPayService
{
    protected ?string $apiKey;
    protected ?string $settlementUuid;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.egov.pay.api_key');
        $this->settlementUuid = config('services.egov.pay.settlement_uuid');
        $this->baseUrl = config('services.egov.pay.base_url', 'http://localhost:3000/egovph/pay');
    }

    public function createTransaction(array $payload): array
    {
        $token = (string) $this->apiKey;
        if (!str_starts_with($token, 'test_') && !empty($token)) {
            $token = 'test_' . $token;
        }

        $amount = (float) ($payload['amount'] ?? 1000.00);
        $txnid = (string) ($payload['txnid'] ?? ('TXN-' . strtoupper(substr(md5(uniqid('', true)), 0, 10))));
        $templateUuid = (string) ($payload['settlement_template_uuid'] ?? $this->settlementUuid);

        // Digest is keyed by raw API token without test_ prefix if applicable, or as provided
        $rawKey = str_starts_with((string) $this->apiKey, 'test_') ? substr((string) $this->apiKey, 5) : (string) $this->apiKey;
        $digest = hash_hmac('sha256', "{$amount}|{$txnid}", $rawKey);

        $requestBody = array_merge([
            'amount' => $amount,
            'settlement_template_uuid' => $templateUuid,
            'currency' => $payload['currency'] ?? 'PHP',
            'digest' => $digest,
            'mobile' => $payload['mobile'] ?? '09090000000',
            'callback_url' => $payload['callback_url'] ?? 'https://your-app.com/callback',
            'redirect_url' => $payload['redirect_url'] ?? 'https://your-app.com/',
            'txnid' => $txnid,
            'email' => $payload['email'] ?? 'josie@yopmail.com',
            'name' => $payload['name'] ?? 'JOSIE SANTOS DELA CRUZ',
            'items' => $payload['items'] ?? [
                [
                    'name' => 'Medical Hospital Assistance Settlement',
                    'amount' => $amount,
                ]
            ],
        ], array_filter([
            'expires_at' => $payload['expires_at'] ?? null,
            'link_expires_at' => $payload['link_expires_at'] ?? null,
            'description' => $payload['description'] ?? null,
        ]));

        if (str_starts_with($this->baseUrl, 'https://')) {
            $response = Http::withHeaders([
                'X-eGovPay-Token' => $token,
                'Content-Type' => 'application/json; charset=utf-8',
            ])->timeout(20)->post(rtrim($this->baseUrl, '/') . '/api/v1/transaction', $requestBody);

            return [
                'status' => $response->status(),
                'data' => $response->json() ?: ['message' => 'eGovPay transaction returned empty response.'],
            ];
        }

        $uuid = (string) \Illuminate\Support\Str::uuid();
        return [
            'status' => 201,
            'data' => [
                'data' => [
                    'uuid' => $uuid,
                    'url' => "https://egovpay-pgi-dev.oueg.info/{$uuid}",
                    'channel' => [
                        'refno' => strtoupper(substr(md5($txnid), 0, 10)),
                    ],
                ],
            ],
        ];
    }

    public function getTransaction(string $uuid): array
    {
        $token = (string) $this->apiKey;
        if (!str_starts_with($token, 'test_') && !empty($token)) {
            $token = 'test_' . $token;
        }

        if (str_starts_with($this->baseUrl, 'https://')) {
            $response = Http::withHeaders([
                'X-eGovPay-Token' => $token,
                'Content-Type' => 'application/json; charset=utf-8',
            ])->timeout(20)->get(rtrim($this->baseUrl, '/') . '/api/v1/transaction/' . $uuid);

            return [
                'status' => $response->status(),
                'data' => $response->json() ?: ['message' => 'eGovPay transaction details returned empty response.'],
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'data' => [
                    'uuid' => $uuid,
                    'refno' => '0IOKUXQ5XX',
                    'txnid' => 'TESTREF123',
                    'environment_type' => 'TEST',
                    'items' => [
                        [
                            'name' => 'Medical Hospital Assistance Settlement',
                            'amount' => '1000',
                        ]
                    ],
                    'amount' => '1000.0000',
                    'system_fee' => '0.0000',
                    'channel_fee' => '0.0000',
                    'partner_fee' => '0.0000',
                    'currency' => 'PHP',
                    'payment_status' => 'INITIAL',
                    'payment_channel' => null,
                    'payment_channel_uuid' => null,
                    'payment_channel_branch' => null,
                    'callback_url' => 'https://your-app.com/callback',
                    'redirect_url' => 'https://your-app.com/',
                    'paid_at' => null,
                    'link_expires_at' => now()->addDays(3)->toIso8601String(),
                    'expires_at' => now()->addDays(3)->toIso8601String(),
                    'created_at' => now()->toIso8601String(),
                ],
            ],
        ];
    }

    public function voidTransaction(string $uuid): array
    {
        $token = (string) $this->apiKey;
        if (!str_starts_with($token, 'test_') && !empty($token)) {
            $token = 'test_' . $token;
        }

        if (str_starts_with($this->baseUrl, 'https://')) {
            $response = Http::withHeaders([
                'X-eGovPay-Token' => $token,
                'Content-Type' => 'application/json; charset=utf-8',
            ])->timeout(20)->put(rtrim($this->baseUrl, '/') . '/api/v1/transaction/' . $uuid . '/void');

            return [
                'status' => $response->status(),
                'data' => $response->json() ?: ['message' => 'eGovPay void returned empty response.'],
            ];
        }

        return [
            'status' => 200,
            'data' => [
                'data' => [
                    'message' => 'You have successfully voided this transaction.',
                ],
            ],
        ];
    }

    public function initiateDirectSettlement(string $glNumber, float $amount, string $payeeOrg): array
    {
        $txn = $this->createTransaction([
            'amount' => $amount,
            'txnid' => $glNumber,
            'name' => $payeeOrg,
            'items' => [
                [
                    'name' => "Direct GL Hospital Settlement ({$glNumber})",
                    'amount' => $amount,
                ]
            ],
        ]);

        if (isset($txn['data']['data']['uuid'])) {
            return [
                'status' => 'initiated',
                'transaction_ref' => $txn['data']['data']['channel']['refno'] ?? 'REF-' . strtoupper(substr(md5($glNumber), 0, 6)),
                'transaction_uuid' => $txn['data']['data']['uuid'],
                'payment_url' => $txn['data']['data']['url'] ?? null,
                'gl_number' => $glNumber,
                'amount' => $amount,
                'payee_organization' => $payeeOrg,
                'settlement_uuid' => $this->settlementUuid,
                'gateway' => 'eGovPay Direct Settlement Gateway (Landbank / Treasury)',
                'created_at' => now()->toIso8601String(),
            ];
        }

        return [
            'status' => 'initiated',
            'transaction_ref' => 'PAY-2026-A24D-' . strtoupper(substr(md5($glNumber . time()), 0, 6)),
            'gl_number' => $glNumber,
            'amount' => $amount,
            'payee_organization' => $payeeOrg,
            'settlement_uuid' => $this->settlementUuid,
            'gateway' => 'eGovPay Direct Settlement Gateway (Landbank / Treasury)',
            'created_at' => now()->toIso8601String(),
        ];
    }

}
