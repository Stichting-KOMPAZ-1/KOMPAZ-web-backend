<?php

declare(strict_types=1);

namespace App\Nova\Concerns;

use App\Models\User;
use App\Support\Access\OrganizationAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Content the platform writes, which nobody else in the panel reads or touches.
 *
 * An organization administrator is let into the panel too, so refusing only the listing is not
 * enough: without a policy Nova answers yes to reading a single record, and a detail page is
 * reachable by its key. Every question is answered here, reading included. A resource with a
 * reason to refuse one of them outright — a delete whose confirmation the product wrote — says so
 * itself, and its own method wins over this one.
 */
trait AuthoredByThePlatform
{
    public static function authorizedToViewAny(Request $request): bool
    {
        return self::operatorIsPlatformAdministrator();
    }

    public function authorizedToView(Request $request): bool
    {
        return self::operatorIsPlatformAdministrator();
    }

    public static function authorizedToCreate(Request $request): bool
    {
        return self::operatorIsPlatformAdministrator();
    }

    public function authorizedToUpdate(Request $request): bool
    {
        return self::operatorIsPlatformAdministrator();
    }

    public function authorizedToDelete(Request $request): bool
    {
        return self::operatorIsPlatformAdministrator();
    }

    private static function operatorIsPlatformAdministrator(): bool
    {
        $operator = Auth::user();

        return $operator instanceof User && OrganizationAccess::isPlatformAdministrator($operator);
    }
}
