<?php

declare(strict_types=1);

namespace Tests\Feature\Authentication;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Everybody in an office reaches the panel from one client address, so asking for a link is
 * counted per email address, with only a wide ceiling per client address.
 */
final class SignInRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config()->set('kompaz.rate_limits.magic_link.attempts', 2);
        config()->set('kompaz.rate_limits.magic_link.address_attempts', 4);
    }

    #[Test]
    public function colleagues_behind_one_address_do_not_use_up_each_others_allowance(): void
    {
        foreach (['a', 'b'] as $person) {
            $this->post('/beheer/inloggen', ['email' => "{$person}@example.com"])->assertRedirect();
            $this->post('/beheer/inloggen', ['email' => "{$person}@example.com"])->assertRedirect();
        }
    }

    #[Test]
    public function one_address_cannot_be_sent_more_than_its_allowance(): void
    {
        $this->post('/beheer/inloggen', ['email' => 'a@example.com'])->assertRedirect();
        $this->post('/beheer/inloggen', ['email' => 'a@example.com'])->assertRedirect();

        $this->post('/beheer/inloggen', ['email' => ' A@Example.com'])->assertTooManyRequests();
    }

    /**
     * The panel and the product's login page send the same email, so they draw on one allowance.
     */
    #[Test]
    public function the_panel_and_the_api_share_an_email_addresses_allowance(): void
    {
        $this->post('/beheer/inloggen', ['email' => 'a@example.com'])->assertRedirect();
        $this->postJson('/api/auth/magic-link', ['email' => 'a@example.com'])->assertNoContent();

        $this->postJson('/api/auth/magic-link', ['email' => 'a@example.com'])->assertTooManyRequests();
    }

    #[Test]
    public function one_client_cannot_work_through_a_list_of_addresses(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $person) {
            $this->post('/beheer/inloggen', ['email' => "{$person}@example.com"])->assertRedirect();
        }

        $this->post('/beheer/inloggen', ['email' => 'e@example.com'])->assertTooManyRequests();
    }
}
