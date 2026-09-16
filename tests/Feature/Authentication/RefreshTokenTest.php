<?php

declare(strict_types=1);

namespace Tests\Feature\Authentication;

use App\Models\RefreshToken;
use App\Models\User;
use App\Services\RefreshTokenIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RefreshTokenTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_exchanges_a_refresh_token_for_a_new_pair(): void
    {
        $user = User::factory()->create();
        $grant = app(RefreshTokenIssuer::class)->startSession($user);

        $response = $this->postJson('/api/auth/tokens/refresh', ['refreshToken' => $grant->value])
            ->assertOk();

        $this->assertNotSame(
            $grant->value,
            $response->json('refreshToken'),
            'Every use must hand back a successor; reusing the secret would defeat rotation.',
        );
        $this->assertNotEmpty($response->json('accessToken'));
    }

    #[Test]
    public function the_successor_stays_in_the_same_session(): void
    {
        $user = User::factory()->create();
        $grant = app(RefreshTokenIssuer::class)->startSession($user);

        $this->postJson('/api/auth/tokens/refresh', ['refreshToken' => $grant->value])->assertOk();

        $this->assertSame(
            1,
            RefreshToken::query()->distinct()->count('session_id'),
            'A successor keeps the session of the token it replaced, which is what lets a replay '
            .'revoke the whole chain.',
        );
    }

    #[Test]
    public function replaying_a_spent_token_ends_the_whole_session(): void
    {
        $user = User::factory()->create();
        $first = app(RefreshTokenIssuer::class)->startSession($user);

        $second = $this->postJson('/api/auth/tokens/refresh', ['refreshToken' => $first->value])
            ->assertOk()
            ->json('refreshToken');

        // The secret is loose, so presenting the spent one revokes everything in the chain.
        $this->postJson('/api/auth/tokens/refresh', ['refreshToken' => $first->value])
            ->assertUnauthorized()
            ->assertJsonPath('detail', 'Dit vernieuwingstoken is al gebruikt. De sessie is beëindigd.');

        $this->assertSame(
            0,
            RefreshToken::query()->whereNull('revoked_at')->count(),
            'A replay must revoke the successor too, not only the token that was presented.',
        );

        $this->postJson('/api/auth/tokens/refresh', ['refreshToken' => $second])
            ->assertUnauthorized();
    }

    #[Test]
    public function an_expired_token_is_reported_as_expired_rather_than_as_a_replay(): void
    {
        $user = User::factory()->create();
        $grant = app(RefreshTokenIssuer::class)->startSession($user);

        RefreshToken::query()->update(['expires_at' => Carbon::now()->subMinute()]);

        $this->postJson('/api/auth/tokens/refresh', ['refreshToken' => $grant->value])
            ->assertUnauthorized()
            ->assertJsonPath('detail', 'Dit vernieuwingstoken is verlopen.');

        $this->assertSame(
            0,
            RefreshToken::query()->whereNotNull('revoked_at')->count(),
            'Expiry is not a replay: it must not revoke the session.',
        );
    }

    #[Test]
    public function the_sliding_window_never_passes_the_absolute_ceiling(): void
    {
        $user = User::factory()->create();
        $grant = app(RefreshTokenIssuer::class)->startSession($user);

        // A session near its hard end: the next rotation's sliding window would run past it.
        RefreshToken::query()->update(['absolute_expires_at' => Carbon::now()->addDay()]);

        $this->postJson('/api/auth/tokens/refresh', ['refreshToken' => $grant->value])->assertOk();

        $successor = RefreshToken::query()->whereNull('consumed_at')->sole();

        $this->assertTrue(
            $successor->expires_at->lessThanOrEqualTo($successor->absolute_expires_at),
            'The slide is capped by the ceiling, so a session still has a hard end.',
        );
    }

    #[Test]
    public function an_unknown_token_is_refused(): void
    {
        $this->postJson('/api/auth/tokens/refresh', ['refreshToken' => 'nope'])
            ->assertUnauthorized()
            ->assertJsonPath('detail', 'Dit vernieuwingstoken is niet geldig.');
    }

    #[Test]
    public function signing_out_ends_the_session_and_succeeds_twice(): void
    {
        $user = User::factory()->create();
        $grant = app(RefreshTokenIssuer::class)->startSession($user);

        $this->postJson('/api/auth/tokens/revoke', ['refreshToken' => $grant->value])->assertNoContent();

        $this->assertSame(0, RefreshToken::query()->whereNull('revoked_at')->count());

        // A client must always be able to clear its credentials without interpreting an error.
        $this->postJson('/api/auth/tokens/revoke', ['refreshToken' => $grant->value])->assertNoContent();
        $this->postJson('/api/auth/tokens/revoke', ['refreshToken' => 'never-existed'])->assertNoContent();
    }

    #[Test]
    public function signing_in_elsewhere_does_not_end_an_existing_session(): void
    {
        $user = User::factory()->create();
        $issuer = app(RefreshTokenIssuer::class);

        $phone = $issuer->startSession($user);
        $laptop = $issuer->startSession($user);

        $this->postJson('/api/auth/tokens/refresh', ['refreshToken' => $phone->value])->assertOk();
        $this->postJson('/api/auth/tokens/refresh', ['refreshToken' => $laptop->value])->assertOk();
    }
}
