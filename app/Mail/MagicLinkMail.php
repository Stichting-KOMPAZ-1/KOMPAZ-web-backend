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
 * The counterpart to NovaSignInMail. Both links now land in the same place and open a session on
 * the panel; the two messages stay separate because one is answering a request somebody made from
 * the product's own login page and the other from the panel's.
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
