<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\UserDeleted;
use App\Mail\AccountDeletedMail;
use Illuminate\Support\Facades\Mail;

/** Tells somebody their account is gone. Only ever raised for a user who could sign in. */
final class SendAccountDeletedEmail
{
    public function handle(UserDeleted $event): void
    {
        Mail::to($event->email, $event->name)->send(new AccountDeletedMail);
    }
}
