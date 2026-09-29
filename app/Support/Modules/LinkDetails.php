<?php

declare(strict_types=1);

namespace App\Support\Modules;

/**
 * One entry of a module's "Video's" or "Extra links": a title and an address.
 *
 * The key is the row it was read from, when it was one. An entry that names a row of the list
 * being written updates that row; anything else — a new entry, or a key from somewhere else — is a
 * new row, so a key sent by a client can only ever reach a row of the list it is writing.
 */
final readonly class LinkDetails
{
    public function __construct(
        public string $title,
        public string $url,
        public ?string $id = null,
    ) {}
}
