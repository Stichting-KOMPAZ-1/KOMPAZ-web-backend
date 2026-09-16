<?php

declare(strict_types=1);

namespace App\DataObjects;

use Illuminate\Support\Carbon;

/** A signed bearer token and the moment it stops being accepted. */
final readonly class AccessToken
{
    public function __construct(
        public string $value,
        public Carbon $expiresAt,
    ) {}
}
