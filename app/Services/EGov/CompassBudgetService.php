<?php

namespace App\Services\EGov;

class CompassBudgetService
{
    protected string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.egov.compass.api_key', 'dbm_live_571ba033e980281325557042746ca1c82fca5de2350e6c6c');
    }

    public function getBudgetStatus(string $programCode = 'DSWD-AICS'): array
    {
        return [
            'program_code' => $programCode,
            'fund_source' => 'GAA 2026 DSWD AICS Budget Allocation',
            'total_allocation' => 20000000.00,
            'utilized_amount' => 5200000.00,
            'remaining_balance' => 14800000.00,
            'compass_reference' => 'DBM-COMPASS-2026-AICS-NCR',
            'status' => 'Active · Funds Available',
        ];
    }
}
