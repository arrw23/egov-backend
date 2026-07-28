<?php

namespace App\Services\EGov;

use App\Models\GuaranteeLetter;

class CompassBudgetService
{
    protected string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.egov.compass.api_key', 'dbm_live_571ba033e980281325557042746ca1c82fca5de2350e6c6c');
    }

    public function getBudgetStatus(string $programCode = 'DSWD-AICS'): array
    {
        $totalAllocation = 20000000.00;
        $utilizedAmount = GuaranteeLetter::where('status', 'active')->sum('approved_amount');
        $remainingBalance = $totalAllocation - $utilizedAmount;

        return [
            'program_code' => $programCode,
            'fund_source' => 'GAA 2026 DSWD AICS Budget Allocation',
            'total_allocation' => $totalAllocation,
            'utilized_amount' => $utilizedAmount,
            'remaining_balance' => $remainingBalance,
            'compass_reference' => 'DBM-COMPASS-2026-AICS-NCR',
            'status' => 'Active A Funds Available',
        ];
    }
}
