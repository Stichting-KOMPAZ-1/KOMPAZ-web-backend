<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SecurityHeadersTest extends TestCase
{
    #[Test]
    public function the_panel_is_never_framed(): void
    {
        $this->get(route('nova.sign-in'))
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    #[Test]
    public function an_api_answer_carries_them_too(): void
    {
        $this->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
