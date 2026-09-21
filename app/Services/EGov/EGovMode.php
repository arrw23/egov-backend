<?php

namespace App\Services\EGov;

/**
 * Explicit sandbox|live switch for the eGov adapters.
 *
 * Previously each adapter decided whether to call upstream or fabricate a
 * response by sniffing whether its base URL started with "https://", and then
 * fell back to canned data on any failure. A production-looking URL therefore
 * produced invented data whenever the upstream call failed, and a "match" was
 * returned for any input. The mode is now explicit and fail-closed.
 *
 * The mode is also per provider, because the providers are not demoable in the
 * same way: eGov SSO needs a live citizen handshake that cannot be replayed on
 * stage, while eGov AI can and should be called for real. EGOV_MODE sets the
 * default for all of them; EGOV_<PROVIDER>_MODE overrides one.
 */
class EGovMode
{
    public static function mode(?string $provider = null): string
    {
        $override = $provider === null ? null : config("services.egov.modes.{$provider}");

        return strtolower((string) ($override ?: config('services.egov.mode', 'sandbox')));
    }

    public static function isLive(?string $provider = null): bool
    {
        return static::mode($provider) === 'live';
    }

    public static function isSandbox(?string $provider = null): bool
    {
        return ! static::isLive($provider);
    }
}
