<?php

declare(strict_types=1);

namespace App\DataObjects;

/**
 * A freshly minted secret and the hash of it that is stored. Only ever held together here, in the
 * one request that mints it: the value goes into an email and the hash goes into the database.
 */
final readonly class SecretToken
{
    public function __construct(
        public string $value,
        public string $hash,
    ) {}
}
