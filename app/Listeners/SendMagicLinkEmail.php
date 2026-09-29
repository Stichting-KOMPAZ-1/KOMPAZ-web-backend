<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\MagicLinkRequested;
use App\Mail\MagicLinkMail;
use App\Support\Auth\SignInLink;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers the sign-in link somebody asked for.
 *
 * The link points at the frontend, unlike an invitation or the panel's own: this one is asked for
 * from the product's login page, so its recipient belongs there.
 */
final class SendMagicLinkEmail
{
    public function handle(MagicLinkRequested $event): void
    {
        Mail::to($event->email, $event->name)->send(
            new MagicLinkMail($event->name, SignInLink::frontend($event->token)),
        );
    }
}
