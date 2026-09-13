<?php

namespace App\Services\EGov;

/**
 * Explicit sandbox|live switch for every eGov adapter.
 *
 * Previously each adapter decided whether to call upstream or fabricate a
 * response by sniffing whether its base URL started with "https://", and then
 * fell back to canned data on any failure. A production-looking URL therefore
 * produced invented data whenever the upstream call failed, and a "match" was
 * returned for any input. The mode is now explicit and fail-closed.
 */
class EGovMode
{
    public static function isLive(): bool
    {
        return strtolower((string) config('services.egov.mode', 'sandbox')) === 'live';
    }

    public static function isSandbox(): bool
    {
        return ! static::isLive();
    }

    /**
     * Whether canned/mock data may be substituted when an upstream call fails
     * or is not attempted. Never in live mode, where a failure must surface as
     * an error rather than as fabricated success.
     */
    public static function allowsMockFallback(): bool
    {
        return static::isSandbox() || app()->runningUnitTests();
    }
}
