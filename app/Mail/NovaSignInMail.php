<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The sign-in link for the admin panel.
 *
 * A separate message from the one the product sends, because it points somewhere else: this link
 * opens a session on the operator's panel, and sending the product's link would drop an operator
 * on the customer-facing application instead.
 */
final class NovaSignInMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $link,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.nova_sign_in.subject'));
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.nova-sign-in',
            with: [
                'name' => $this->name,
                'link' => $this->link,
                'minutes' => (int) config('kompaz.authentication.magic_link_lifetime_minutes'),
            ],
        );
    }
}
