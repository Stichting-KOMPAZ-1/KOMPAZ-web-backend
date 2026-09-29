<?php

declare(strict_types=1);

namespace App\Support\Modules;

final readonly class ChapterDetails
{
    public function __construct(
        public string $name,
        public ?string $description,
        public bool $isSummary,
    ) {}
}
