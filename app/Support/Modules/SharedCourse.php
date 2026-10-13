<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Models\ELearning;
use App\Models\Organization;

/** A course as an organization shares it: what `/{organization}/{e-learning}` resolved to (KOM-95). */
final readonly class SharedCourse
{
    public function __construct(
        public Organization $organization,
        public ELearning $eLearning,
    ) {}

    /** Where this course is read from in the API, and what every address in its response hangs off. */
    public function path(): string
    {
        return CoursePath::shared($this->organization, $this->eLearning);
    }
}
