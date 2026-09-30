<?php

namespace App\Modules\Branch\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards routes carrying a branch route parameter (default: {branch}).
 * Usage: ->middleware('branch.access') or 'branch.access:branchId'.
 */
class EnsureBranchAccess
{
    public function handle(Request $request, Closure $next, string $parameter = 'branch'): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->canAccessBranch($request->route($parameter))) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}
