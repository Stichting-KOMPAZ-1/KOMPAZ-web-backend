<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Mail\InvitationMail;
use App\Mail\NovaSignInMail;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MailDesignTest extends TestCase
{
    #[Test]
    public function the_nova_magic_link_uses_the_zelfzorg_email_design(): void
    {
        config(['app.locale' => 'nl']);

        $html = (new NovaSignInMail(
            'David van Dommelen',
            'https://backend.kompaz.test/beheer/sessie?token=secret',
        ))->render();

        $this->assertStringContainsString('Hi David van Dommelen', $html);
        $this->assertStringContainsString('Inloggen op het ZelfZorg platform', $html);
        $this->assertStringContainsString('background-color:#f4f7f9', $html);
        $this->assertStringContainsString('background-color:#ffffff; border-radius:12px', $html);
        $this->assertStringContainsString('bgcolor="#b45d7b"', $html);
        $this->assertStringContainsString('Logo_mark_blauw.png', $html);
    }

    #[Test]
    public function the_invitation_uses_the_zelfzorg_email_design(): void
    {
        config(['app.locale' => 'nl']);
        Carbon::setTestNow('2026-09-16 12:00:00');

        $html = (new InvitationMail(
            'David van Dommelen',
            'Regio Midden',
            'https://kompaz.test/inloggen?token=secret',
        ))->render();

        $this->assertStringContainsString('Hi David van Dommelen,', $html);
        $this->assertStringContainsString(
            'Je bent uitgenodigd voor de ZelfZorgacademie-omgeving van Regio Midden.',
            $html,
        );
        $this->assertStringContainsString('Accepteer uitnodiging', $html);
        $this->assertStringContainsString('Deze link is geldig tot 23-09-2026 14:00', $html);
        $this->assertStringContainsString('Stichting KOMPAZ', $html);
    }
}
