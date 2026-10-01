<?php

declare(strict_types=1);

namespace App\Support\Modules;

/** One contact card on an organization's copy of a module. The key works as {@see LinkDetails}'s does. */
final readonly class ContactDetails
{
    public function __construct(
        public string $name,
        public string $jobRole,
        public string $email,
        public ?string $phone = null,
        public ?string $reason = null,
        public ?string $availability = null,
        public ?string $id = null,
    ) {}
}
