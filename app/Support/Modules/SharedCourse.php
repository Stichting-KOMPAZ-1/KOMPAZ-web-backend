<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Models\ELearning;
use App\Models\ModuleActivation;
use App\Models\ModuleContact;
use App\Models\Organization;
use Illuminate\Support\Collection;

/** A course as an organization shares it, through a current module link or a legacy link. */
final readonly class SharedCourse
{
    public function __construct(
        public Organization $organization,
        public ELearning $eLearning,
        public ?ModuleActivation $activation = null,
        /** @var Collection<int, ModuleContact> */
        public ?Collection $legacyContacts = null,
    ) {}

    /** Where this course is read from in the API, and what every address in its response hangs off. */
    public function path(): string
    {
        return CoursePath::shared($this->organization, $this->eLearning, $this->activation?->module);
    }

    /** @return Collection<int, ModuleContact> */
    public function contacts(): Collection
    {
        if ($this->activation !== null) {
            return $this->activation->contacts;
        }

        return $this->legacyContacts ?? new Collection;
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
