<?php

declare(strict_types=1);

namespace App\Actions\Authentication;

use App\Enums\LoginTokenPurpose;
use App\Events\MagicLinkRequested;
use App\Models\User;
use App\Services\LoginTokenIssuer;
use Illuminate\Support\Facades\DB;

/**
 * Issues a sign-in link for whoever holds an email address, and says nothing about whether anybody
 * does.
 *
 * Unauthenticated and reachable by anybody who finds the endpoint, which decides the two things
 * that look like omissions here. An address nobody holds is a silent no-op rather than a refusal,
 * because answering differently would turn the endpoint into a list of who has an account. And a
 * deleted user is passed over for the same reason they cannot sign in at all — their row survives
 * only to hold the address and the audit trail.
 *
 * Whether the recipient has accepted their invitation yet does not matter: claiming any link
 * activates an invited user, so somebody who mislaid an invitation can ask for a link instead of
 * waiting to be invited again.
 */
final readonly class RequestMagicLinkAction
{
    public function __construct(private LoginTokenIssuer $tokenIssuer) {}

    public function execute(string $email): void
    {
        $user = User::query()
            ->where('normalized_email', User::normalize($email))
            ->first();

        if ($user === null) {
            return;
        }

        DB::transaction(function () use ($user): void {
            $token = $this->tokenIssuer->issue($user, LoginTokenPurpose::MagicLink);

            MagicLinkRequested::dispatch(
                (string) $user->getKey(),
                $user->email,
                $user->name,
                $token,
            );
        });
    }
}
