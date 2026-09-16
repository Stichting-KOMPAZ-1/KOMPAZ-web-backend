<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use App\Services\AccessTokenIssuer;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Uploads go to a disk that is thrown away with the test, so nothing a test writes can
        // reach the developer's own storage directory.
        Storage::fake('logos-testing');
    }

    /**
     * The headers a signed-in caller sends.
     *
     * A real token, signed and parsed the way a request's would be, rather than `actingAs`: the
     * claims on it are half of what the application checks, and a test that skipped them would not
     * exercise the comparison that makes a demotion take effect.
     *
     * @return array<string, string>
     */
    protected function tokenHeaders(User $user): array
    {
        $token = app(AccessTokenIssuer::class)->issue($user)->value;

        return ['Authorization' => 'Bearer '.$token];
    }
}
