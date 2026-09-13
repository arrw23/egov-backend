<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to one or more user roles.
 *
 * Usage: ->middleware('role:hospital_staff') or 'role:applicant,hospital_staff'.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! in_array($user->role, $roles, true)) {
            return response()->json([
                'message' => 'This action requires the ' . implode(' or ', $roles) . ' role.',
            ], 403);
        }

        return $next($request);
    }
}
