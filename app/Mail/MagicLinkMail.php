<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The sign-in link the product sends, for somebody who asked for one from the login page.
 *
 * The counterpart to NovaSignInMail and deliberately not the same message: this link lands on the
 * zelfzorgacademie, where that one opens a session on the operator's panel.
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
            view: 'mail.magic-link-html',
            text: 'mail.magic-link',
            with: [
                'name' => $this->name,
                'link' => $this->link,
                'minutes' => (int) config('kompaz.authentication.magic_link_lifetime_minutes'),
            ],
        );
    }
}
