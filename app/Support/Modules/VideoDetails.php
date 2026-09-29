<?php

declare(strict_types=1);

namespace App\Support\Modules;

/**
 * One entry of a module's "Video's": a title, and a link or an upload.
 *
 * At most one of `url` and `uploadId` is set. Neither is how an entry that already has an upload
 * keeps it — an edit does not send the video again — and whether that is acceptable is the
 * writer's to decide, because only the row knows whether it has a file. The key works as
 * {@see LinkDetails}'s does.
 *
 * The two field names are where in the request each half came from, or would have: a refusal is
 * answered under the one it is about, and the panel's and the API's are different paths to the
 * same mistake.
 */
final readonly class VideoDetails
{
    public function __construct(
        public string $title,
        public string $urlField,
        public string $uploadField,
        public ?string $url = null,
        public ?string $uploadId = null,
        public ?string $id = null,
    ) {}
}
