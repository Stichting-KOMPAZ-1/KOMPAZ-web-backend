<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Enums\ModuleStatus;

/**
 * Everything a module is, as a form or a request states it.
 *
 * The four lists are null when they were not stated at all, which leaves them as they are. That
 * is the difference between "no courses" and "I did not say": a client renaming a module should
 * not unlink its courses by leaving a key out.
 */
final readonly class ModuleDetails
{
    /**
     * @param  list<string>|null  $eLearningIds  the courses it shows
     * @param  list<string>|null  $organizationIds  the organizations it is switched on for
     * @param  list<VideoDetails>|null  $videos  the platform's own videos, in order
     * @param  list<LinkDetails>|null  $links  the platform's own links, in order
     */
    public function __construct(
        public string $name,
        public string $categoryId,
        public string $description,
        public ?string $sourceAttribution,
        public ModuleStatus $status,
        public ?array $eLearningIds = null,
        public ?array $organizationIds = null,
        public ?array $videos = null,
        public ?array $links = null,
    ) {}
}
