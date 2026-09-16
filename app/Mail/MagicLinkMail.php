<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The sign-in link.
 *
 * The lifetime is read from configuration rather than written into the wording, so the promise the
 * email makes cannot drift from the deadline the redemption endpoint actually enforces.
 */
final class MagicLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $link,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.magic_link.subject'));
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.magic-link',
            with: [
                'name' => $this->name,
                'link' => $this->link,
                'minutes' => (int) config('kompaz.authentication.magic_link_lifetime_minutes'),
            ],
        );
    }
}
