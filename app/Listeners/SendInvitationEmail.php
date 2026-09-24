<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\InvitationIssued;
use App\Mail\InvitationMail;
use App\Support\Auth\SignInLink;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers the invitation whose issue was recorded on the user.
 *
 * The commands that issue one — inviting somebody and re-inviting them — do not send it
 * themselves, so that "an invitation was issued" and "an invitation was sent" stay separable, and
 * neither command has to know about email.
 */
final class SendInvitationEmail
{
    public function handle(InvitationIssued $event): void
    {
        Mail::to($event->email, $event->name)->send(
            new InvitationMail($event->name, $event->organizationName, SignInLink::invitation($event->token)),
        );
    }
}
