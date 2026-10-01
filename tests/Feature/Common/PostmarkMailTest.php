<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Providers\AppServiceProvider;
use CraigPaul\Mail\PostmarkTransport;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class PostmarkMailTest extends TestCase
{
    #[Test]
    public function the_postmark_mailer_sends_through_postmark_with_the_token_the_apps_carry(): void
    {
        // Staging set MAIL_MAILER=postmark and POSTMARK_TOKEN, as the other backends do, and nothing
        // here could send with either: no transport was installed and the key was read from
        // POSTMARK_API_KEY. The application started and every sign-in link failed to go out.
        config()->set('services.postmark.token', 'server-token');

        $transport = Mail::mailer('postmark')->getSymfonyTransport();

        $this->assertInstanceOf(PostmarkTransport::class, $transport);
    }

    #[Test]
    public function startup_refuses_postmark_without_a_token(): void
    {
        $this->bootAsProduction(mailer: 'postmark', token: null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('POSTMARK_TOKEN must be set');

        (new AppServiceProvider($this->app))->boot();
    }

    #[Test]
    public function startup_accepts_postmark_with_a_token(): void
    {
        $this->bootAsProduction(mailer: 'postmark', token: 'server-token');

        (new AppServiceProvider($this->app))->boot();

        $this->assertSame('postmark', config('mail.default'));
    }

    #[Test]
    public function another_transport_needs_no_postmark_token(): void
    {
        $this->bootAsProduction(mailer: 'smtp', token: null);

        (new AppServiceProvider($this->app))->boot();

        $this->assertSame('smtp', config('mail.default'));
    }

    /** The checks only apply outside local development and the test suite. */
    private function bootAsProduction(string $mailer, ?string $token): void
    {
        $this->app->instance('env', 'production');

        config()->set('mail.default', $mailer);
        config()->set('services.postmark.token', $token);
        config()->set('filesystems.disks.videos.connection_string', 'UseDevelopmentStorage=true');
    }
}
