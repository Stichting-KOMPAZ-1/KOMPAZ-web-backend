<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The notice that an account is gone.
 *
 * Only ever sent to somebody who could sign in. Every line of it is untrue for a user who is still
 * invited — they never had an account — so revoking an invitation is silent instead.
 */
final class AccountDeletedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.account_deleted.subject'));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.account-deleted');
    }
}
