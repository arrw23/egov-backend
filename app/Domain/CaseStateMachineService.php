<?php

namespace App\Domain;

use App\Models\MedicalCase;
use App\Models\User;
use InvalidArgumentException;

class CaseStateMachineService
{
    public const DRAFT = 'DRAFT';
    public const WAITING_FOR_HOSPITAL_DOCUMENTS = 'WAITING_FOR_HOSPITAL_DOCUMENTS';
    public const READY_FOR_SUBMISSION = 'READY_FOR_SUBMISSION';
    public const UNDER_AGENCY_REVIEW = 'UNDER_AGENCY_REVIEW';
    public const APPROVED = 'APPROVED';
    public const PARTIALLY_APPROVED = 'PARTIALLY_APPROVED';
    public const DENIED = 'DENIED';
    public const NEEDS_INFORMATION = 'NEEDS_INFORMATION';
    public const GUARANTEE_LETTER_ISSUED = 'GUARANTEE_LETTER_ISSUED';
    public const PARTIALLY_UTILIZED = 'PARTIALLY_UTILIZED';
    public const FULLY_UTILIZED = 'FULLY_UTILIZED';

    protected array $allowedTransitions = [
        self::DRAFT => [self::WAITING_FOR_HOSPITAL_DOCUMENTS, self::READY_FOR_SUBMISSION],
        self::WAITING_FOR_HOSPITAL_DOCUMENTS => [self::READY_FOR_SUBMISSION],
        self::READY_FOR_SUBMISSION => [self::UNDER_AGENCY_REVIEW],
        self::UNDER_AGENCY_REVIEW => [self::APPROVED, self::PARTIALLY_APPROVED, self::DENIED, self::NEEDS_INFORMATION],
        self::NEEDS_INFORMATION => [self::UNDER_AGENCY_REVIEW, self::READY_FOR_SUBMISSION],
        self::APPROVED => [self::GUARANTEE_LETTER_ISSUED],
        self::PARTIALLY_APPROVED => [self::GUARANTEE_LETTER_ISSUED],
        self::GUARANTEE_LETTER_ISSUED => [self::PARTIALLY_UTILIZED, self::FULLY_UTILIZED],
        self::PARTIALLY_UTILIZED => [self::FULLY_UTILIZED],
        self::FULLY_UTILIZED => [],
        self::DENIED => [],
    ];

    public function canTransition(string $currentStatus, string $newStatus): bool
    {
        if ($currentStatus === $newStatus) {
            return true;
        }

        $allowed = $this->allowedTransitions[$currentStatus] ?? [];
        return in_array($newStatus, $allowed, true);
    }

    public function transition(MedicalCase $medicalCase, string $newStatus, ?User $actor = null): MedicalCase
    {
        if (!$this->canTransition($medicalCase->status, $newStatus)) {
            throw new InvalidArgumentException("Invalid case state transition from {$medicalCase->status} to {$newStatus}.");
        }

        $oldStatus = $medicalCase->status;
        $medicalCase->status = $newStatus;
        $medicalCase->save();

        try {
            app(\App\Services\EGov\EGovChainService::class)->anchorCaseTransition($medicalCase, $oldStatus, $newStatus, $actor);
        } catch (\Exception $e) {
            // Chain anchoring failure should not block state transition
        }

        return $medicalCase;
    }
}
