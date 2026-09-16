<?php

declare(strict_types=1);

namespace App\Actions\Authentication;

use App\Enums\LoginTokenPurpose;
use App\Events\MagicLinkIssued;
use App\Models\User;
use App\Services\LoginTokenIssuer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Emails a sign-in link to the address supplied.
 *
 * Succeeds whether or not the address belongs to a user, so the endpoint cannot be used to discover
 * who has an account. A deleted user is treated exactly like an address nobody has: no link, and
 * the same answer either way.
 */
final readonly class RequestMagicLinkAction
{
    public function __construct(private LoginTokenIssuer $tokenIssuer) {}

    public function execute(string $email): void
    {
        $user = User::query()
            ->where('normalized_email', User::normalize($email))
            ->whereNull('deleted_at')
            ->first();

        if ($user === null) {
            // Logged without the address, for the same reason the delivery failure is: this line
            // fires for an address that has no account, and its counterpart fires for one that
            // does, so between them they would answer the question the endpoint refuses to.
            Log::info('Magic link requested for an address without an account.');

            return;
        }

        DB::transaction(function () use ($user): void {
            // Always a magic link, even for somebody who has not accepted their invitation yet.
            // Redeeming one activates them just the same, and minting an invitation here would let
            // anybody who knows the address retire the invitation an administrator sent, over and
            // over. Reissuing that one is the administrator's endpoint.
            $token = $this->tokenIssuer->issue($user, LoginTokenPurpose::MagicLink);

            MagicLinkIssued::dispatch((string) $user->getKey(), $user->email, $user->name, $token);
        });
    }
}
