<?php

declare(strict_types=1);

namespace App\Support\Files;

use App\Exceptions\NotFoundException;
use App\Support\Images\ServedLogo;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * A stored file on its way out: the bytes, what they are called, and the headers that make handing
 * them to a browser safe.
 *
 * The disk is private, so every file this application keeps is read back through the application —
 * which checks the token first. Shaped like {@see ServedLogo} and deliberately
 * not merged with it: a logo answers with a placeholder when there is nothing to serve, because an
 * organization always has to look like something. Content has no such fallback. A module with no
 * picture says so in its own response and its client never asks for one, so a request that gets
 * here for a file that is not there is a 404 rather than a stand-in.
 */
final readonly class ServedFile implements Responsable
{
    private function __construct(public string $content, public string $contentType) {}

    /**
     * The bytes behind a stored file.
     *
     * A row naming a file the disk does not have is reachable rather than theoretical — an upload
     * commits its row and a cleanup can fail the other way — so it is logged and answered as a
     * missing file. Not a 500: one lost file should cost one image, not the page.
     */
    public static function for(StoredFile $file): self
    {
        try {
            $content = Storage::get($file->key);
        } catch (Throwable $exception) {
            $content = null;

            Log::warning('A content row names a file the disk does not have.', [
                'storage_key' => $file->key,
                'exception' => $exception,
            ]);
        }

        if ($content === null) {
            throw new NotFoundException('Dit bestand is niet beschikbaar.');
        }

        return new self($content, $file->contentType);
    }

    public function toResponse($request): Response
    {
        return new Response($this->content, HttpResponse::HTTP_OK, $this->headers());
    }

    /**
     * The headers the bytes go out under.
     *
     * The same three a logo goes out under, and for the same reason: an SVG is a document that can
     * carry script, and this application's own origin is where that script would run. The media
     * type was read out of the bytes on the way in, and `nosniff` is what stops a browser deciding
     * for itself that they are something more interesting.
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
