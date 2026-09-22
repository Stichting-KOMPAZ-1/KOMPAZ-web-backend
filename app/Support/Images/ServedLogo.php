<?php

declare(strict_types=1);

namespace App\Support\Images;

use App\Models\Organization;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * An organization's logo on its way out: the bytes, what they are called, and the headers that
 * make handing them to a browser safe.
 *
 * Two places answer with a logo — the API, which checks the bearer token, and the panel, which
 * checks the operator's session — and what they differ in is the guard, never the picture. Which
 * is also why neither of them decides what an organization without a usable image looks like.
 */
final readonly class ServedLogo
{
    private function __construct(public string $content, public string $contentType) {}

    /**
     * The logo to answer with, which is the placeholder unless the organization has an image whose
     * file is still on the disk.
     */
    public static function for(Organization $organization): self
    {
        $logo = $organization->logo;

        if ($logo !== null) {
            try {
                $content = Storage::get($logo->storage_key);
            } catch (Throwable $exception) {
                $content = null;

                Log::warning('A logo row names a file the disk does not have.', [
                    'organization_id' => $organization->getKey(),
                    'storage_key' => $logo->storage_key,
                    'exception' => $exception,
                ]);
            }

            if ($content !== null) {
                return new self($content, $logo->content_type);
            }
        }

        // A row naming a file the disk does not have is reachable rather than theoretical — an
        // upload commits its row and a cleanup can fail the other way — so it is answered with the
        // placeholder, which is what an organization without a usable logo should look like. The
        // alternative, a 500, would turn one lost file into a page that will not render.
        return new self(PlaceholderLogo::content(), PlaceholderLogo::contentType());
    }

    /**
     * The headers the bytes go out under.
     *
     * An SVG is a document rather than a picture: served as itself it can carry script, and this
     * application's own origin is where that script would run. These two headers are what make it
     * harmless — the document may load nothing, and the browser may not decide for itself that the
     * bytes are something more interesting than the media type says. Set for every format, because
     * the upload chose which one this is.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [
            'Content-Type' => $this->contentType,
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
