<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Auth;

/**
 * Records who created and who last changed a row.
 *
 * Nothing sets these by hand. They are stamped while saving, so a new write cannot forget them
 * and two writes cannot disagree. Only the identifiers are stamped here — Eloquent already owns
 * the timestamps — and only when a request is behind the change: the seeder and the sign-in flow
 * both write with nobody signed in, and a fabricated author would be worse than an absent one.
 */
trait StampsAuditor
{
    public static function bootStampsAuditor(): void
    {
        static::creating(function (self $model): void {
            $actor = Auth::id();

            if ($actor !== null) {
                $model->created_by ??= $actor;
                $model->updated_by ??= $actor;
            }
        });

        static::updating(function (self $model): void {
            $actor = Auth::id();

            if ($actor !== null) {
                $model->updated_by = $actor;
            }
        });
    }
}
