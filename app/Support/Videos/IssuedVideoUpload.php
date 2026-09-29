<?php

declare(strict_types=1);

namespace App\Support\Videos;

use App\Models\VideoUpload;

/** An upload just issued, and the link its bytes are written to. */
final readonly class IssuedVideoUpload
{
    /** @param  array<string, string>  $headers  what every write to the link has to carry */
    public function __construct(
        public VideoUpload $upload,
        public string $url,
        public array $headers,
    ) {}
}
