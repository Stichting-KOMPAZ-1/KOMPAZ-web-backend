<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EntryPointsTest extends TestCase
{
    #[Test]
    public function the_health_check_answers(): void
    {
        $this->get('/up')->assertOk();
    }

    #[Test]
    public function the_root_sends_a_browser_to_the_admin_sign_in(): void
    {
        // There is no browser-facing application here: the frontend is served separately, and the
        // only thing this deployment shows a person is the operator's panel.
        $this->get('/')->assertRedirect(route('nova.sign-in'));
    }
}
