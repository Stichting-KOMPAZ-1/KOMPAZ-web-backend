<?php

declare(strict_types=1);

namespace App\Support\Modules;

/**
 * What an organization adds to its own copy of a module. A list left null is left as it is.
 */
final readonly class ActivationDetails
{
    /**
     * @param  list<VideoDetails>|null  $videos
     * @param  list<LinkDetails>|null  $links
     * @param  list<ContactDetails>|null  $contacts
     */
    public function __construct(
        public ?array $videos = null,
        public ?array $links = null,
        public ?array $contacts = null,
    ) {}
}
