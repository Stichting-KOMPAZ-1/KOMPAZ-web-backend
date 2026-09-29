<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Support\Modules\ContactDetails;
use App\Support\Modules\ContentRules;
use App\Support\Modules\LinkDetails;

/**
 * The lists a module and an organization's copy of one carry — videos, links, contacts — as rules
 * and as the details the actions take.
 *
 * A list left out of the request reads as null, which the actions take to mean "leave it as it
 * is". An empty list is how a client clears one.
 */
trait ReadsContentLists
{
    /** @return array<string, mixed> */
    protected static function videoRules(): array
    {
        return [
            'videos' => ['sometimes', ...ContentRules::videoList()],
            'videos.*.id' => ['nullable', 'uuid'],
            'videos.*.title' => ContentRules::videoTitle(),
            'videos.*.url' => ContentRules::url(),
        ];
    }

    /** @return array<string, mixed> */
    protected static function linkRules(): array
    {
        return [
            'links' => ['sometimes', ...ContentRules::linkList()],
            'links.*.id' => ['nullable', 'uuid'],
            'links.*.title' => ContentRules::linkTitle(),
            'links.*.url' => ContentRules::url(),
        ];
    }

    /** @return array<string, mixed> */
    protected static function contactRules(): array
    {
        return [
            'contacts' => ['sometimes', ...ContentRules::contactList()],
            'contacts.*.id' => ['nullable', 'uuid'],
            'contacts.*.name' => ContentRules::contactName(),
            'contacts.*.email' => ContentRules::contactEmail(),
            'contacts.*.phone' => ContentRules::contactPhone(),
            'contacts.*.reason' => ContentRules::contactNote(),
            'contacts.*.availability' => ContentRules::contactNote(),
        ];
    }

    /** @return array<string, string> */
    protected static function listAttributes(): array
    {
        return [
            'videos' => "video's",
            'videos.*.title' => 'titel',
            'videos.*.url' => 'URL',
            'links' => 'links',
            'links.*.title' => 'titel',
            'links.*.url' => 'URL',
            'contacts' => 'contactpersonen',
            'contacts.*.name' => 'naam',
            'contacts.*.email' => 'e-mailadres',
            'contacts.*.phone' => 'telefoonnummer',
            'contacts.*.reason' => 'reden voor contact',
            'contacts.*.availability' => 'beschikbaarheid',
        ];
    }

    /** @return list<LinkDetails>|null */
    protected function linkList(string $key): ?array
    {
        if (! $this->has($key)) {
            return null;
        }

        return array_values(array_map(
            fn (array $entry): LinkDetails => new LinkDetails(
                title: self::stringOf($entry, 'title') ?? '',
                url: self::stringOf($entry, 'url') ?? '',
                id: self::stringOf($entry, 'id'),
            ),
            array_filter($this->array($key), 'is_array'),
        ));
    }

    /** @return list<ContactDetails>|null */
    protected function contactList(): ?array
    {
        if (! $this->has('contacts')) {
            return null;
        }

        return array_values(array_map(
            fn (array $entry): ContactDetails => new ContactDetails(
                name: self::stringOf($entry, 'name') ?? '',
                email: self::stringOf($entry, 'email'),
                phone: self::stringOf($entry, 'phone'),
                reason: self::stringOf($entry, 'reason'),
                availability: self::stringOf($entry, 'availability'),
                id: self::stringOf($entry, 'id'),
            ),
            array_filter($this->array('contacts'), 'is_array'),
        ));
    }

    /** @return list<string>|null */
    protected function keyList(string $key): ?array
    {
        if (! $this->has($key)) {
            return null;
        }

        return array_values(array_filter($this->array($key), 'is_string'));
    }

    /** @param  array<mixed>  $entry */
    private static function stringOf(array $entry, string $key): ?string
    {
        $value = $entry[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
