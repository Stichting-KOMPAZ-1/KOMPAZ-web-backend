<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Models\ELearning;
use App\Models\Organization;

/** A course as an organization shares it: what `/delen/{organization}/{e-learning}` resolved to (KOM-95). */
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

    /**
     * Where the sharing organization's logo is read on this door, for the page's header (KOM-110).
     * Under the course rather than the organization, so it answers exactly while the course does.
     */
    public function organizationLogoPath(): string
    {
        return $this->path().'/organization/logo';
    }
}
