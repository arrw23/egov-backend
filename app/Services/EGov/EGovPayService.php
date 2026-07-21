<?php

namespace App\Services\EGov;

class EGovPayService
{
    protected string $apiKey;
    protected string $settlementUuid;

    public function __construct()
    {
        $this->apiKey = config('services.egov.pay.api_key', 'test_bcc2092263e426957841fb633d09ec8b38b865929f2d3cb75d71f18469862885');
        $this->settlementUuid = config('services.egov.pay.settlement_uuid', 'a24d6045-cf2b-4bca-9072-865c352563f5');
    }

    public function initiateDirectSettlement(string $glNumber, float $amount, string $payeeOrg): array
    {
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
