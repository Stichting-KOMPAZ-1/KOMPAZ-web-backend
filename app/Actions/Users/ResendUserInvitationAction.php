<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\LoginTokenPurpose;
use App\Enums\UserStatus;
use App\Events\InvitationIssued;
use App\Exceptions\ConflictException;
use App\Models\User;
use App\Services\LoginTokenIssuer;
use App\Support\Access\OrganizationAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Sends a fresh invitation link, retiring the previous one. */
final readonly class ResendUserInvitationAction
{
    public function __construct(private LoginTokenIssuer $tokenIssuer) {}

    public function execute(User $actor, User $user): void
    {
        OrganizationAccess::ensureCanManage($actor, $user->organization_id);
        OrganizationAccess::ensureCanManageRole($actor, $user->role);

        if ($user->status === UserStatus::Active) {
            throw new ConflictException('De uitnodiging is al geaccepteerd.');
        }

        DB::transaction(function () use ($user): void {
            $token = $this->tokenIssuer->issue($user, LoginTokenPurpose::Invitation);

            $user->invited_at = Carbon::now();
            $user->save();

            InvitationIssued::dispatch(
                (string) $user->getKey(),
                $user->email,
                $user->name,
                $user->organization->name,
                $token,
            );
        });
    }
}
