<?php

namespace App\Domain;

use App\Models\MedicalCase;
use App\Models\GuaranteeLetter;

class CaseCalculationService
{
    public function calculate(MedicalCase $medicalCase): array
    {
        $verifiedBill = (float) $medicalCase->verified_bill;

        $approvedAssistance = (float) $medicalCase->agencyApplications()
            ->whereIn('status', ['approved', 'partially_approved'])
            ->sum('approved_amount');

        $utilizedAmount = (float) $medicalCase->guaranteeLetters()
            ->with('utilizations')
            ->get()
            ->flatMap(fn (GuaranteeLetter $gl) => $gl->utilizations)
            ->where('status', 'confirmed')
            ->sum('utilized_amount');

        $remainingUncovered = max(0.00, $verifiedBill - $approvedAssistance);

        return [
            'verified_bill' => round($verifiedBill, 2),
            'total_approved_assistance' => round($approvedAssistance, 2),
            'total_utilized' => round($utilizedAmount, 2),
            'remaining_uncovered_balance' => round($remainingUncovered, 2),
        ];
    }

    public function calculateGuarantee(GuaranteeLetter $guaranteeLetter): array
    {
        $approvedAmount = (float) $guaranteeLetter->approved_amount;
        $utilizedAmount = (float) $guaranteeLetter->utilizations()
            ->where('status', 'confirmed')
            ->sum('utilized_amount');
        $remainingValue = max(0.00, $approvedAmount - $utilizedAmount);

        return [
            'approved_amount' => round($approvedAmount, 2),
            'utilized_amount' => round($utilizedAmount, 2),
            'remaining_value' => round($remainingValue, 2),
        ];
    }
}
