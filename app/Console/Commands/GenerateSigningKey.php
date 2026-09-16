<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Prints a signing key of the right size.
 *
 * Prints rather than writes: the key belongs in the environment a deployment supplies, not in a
 * file in the repository, and a command that edited `.env` would teach the opposite habit.
 */
final class GenerateSigningKey extends Command
{
    protected $signature = 'kompaz:generate-signing-key';

    protected $description = 'Print a new access-token signing key for AUTH_SIGNING_KEY';

    public function handle(): int
    {
        $this->line(base64_encode(random_bytes(48)));

        return self::SUCCESS;
    }
}
