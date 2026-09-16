<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\MagicLinkIssued;
use App\Mail\MagicLinkMail;
use App\Support\Auth\SignInLink;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers the sign-in link whose issue was recorded on the user.
 *
 * A failure here must not reach the caller. An exception would surface as a 500 from
 * `POST /api/auth/magic-link` — but only for an address that has an account, because an unknown
 * address never gets this far. A degraded relay would therefore turn the endpoint into an
 * account-existence oracle and break the promise that the same answer comes back either way. The
 * link is already in the database and asking for another one costs nothing, so the user losing
 * this one is the cheaper failure.
 *
 * Deliberately not done for {@see SendInvitationEmail}: an invitation is an administrator's action
 * against an address they typed themselves, there is nothing to conceal from them, and they are
 * the one person who can usefully act on "that did not send".
 */
final class SendMagicLinkEmail
{
    public function handle(MagicLinkIssued $event): void
    {
        try {
            Mail::to($event->email, $event->name)->send(
                new MagicLinkMail($event->name, SignInLink::for($event->token)),
            );
        } catch (Throwable $exception) {
            // The address is not logged: this line fires precisely when somebody is asking about
            // an address that does have an account, so the log would become the enumeration answer
            // the endpoint refuses to give.
            Log::error('Failed to deliver a sign-in link. The link remains valid and can be requested again.', [
                'user_id' => $event->userId,
                'exception' => $exception,
            ]);
        }
    }
}
