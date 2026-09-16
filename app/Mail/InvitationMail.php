<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/** The invitation, which both accepts the invitation and signs the recipient in. */
final class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $organizationName,
        public string $link,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.invitation.subject', ['organization' => $this->organizationName]),
        );
    }

    public function content(): Content
    {
        $days = (int) config('kompaz.authentication.invitation_lifetime_days');

        return new Content(
            view: 'mail.invitation-html',
            text: 'mail.invitation',
            with: [
                'name' => $this->name,
                'organization' => $this->organizationName,
                'link' => $this->link,
                'days' => $days,
                'expiresAt' => Carbon::now()
                    ->addDays($days)
                    ->setTimezone((string) config('kompaz.display_timezone'))
                    ->format('d-m-Y H:i'),
            ],
        );
    }
}
