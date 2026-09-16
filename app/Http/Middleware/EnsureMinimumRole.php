<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Exceptions\ForbiddenAccessException;
use App\Models\User;
use App\Support\Access\OrganizationAccess;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A floor on the caller's role, and only that.
 *
 * It is never a tenant check: seniority says what kind of thing somebody may do, and
 * {@see OrganizationAccess} says whose. Every route that states a role here
 * still asks that question in the action behind it.
 */
final class EnsureMinimumRole
{
    public function handle(Request $request, Closure $next, string $minimumRole): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $required = UserRole::from($minimumRole);

        if (! $user->role->atLeast($required)) {
            throw new ForbiddenAccessException(sprintf(
                'Deze actie vereist minimaal de rol %s.',
                $required->value,
            ));
        }

        return $next($request);
    }
}
