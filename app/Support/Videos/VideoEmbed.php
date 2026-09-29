<?php

declare(strict_types=1);

namespace App\Support\Videos;

/**
 * What a video link can be shown as, worked out from the link rather than trusted from it.
 *
 * YouTube and Vimeo are embedded in their own players, and a link straight to a video file plays
 * in a `<video>`. The embed address is **built here from the identifier alone** — never the link
 * as typed — so a link that merely looks like YouTube cannot put some other page in a frame: an
 * iframe's address is ours, and only its id came from the row. Anything else is just a link.
 */
final readonly class VideoEmbed
{
    public const string YOUTUBE = 'youtube';

    public const string VIMEO = 'vimeo';

    public const string FILE = 'file';

    public const string LINK = 'link';

    private const array YOUTUBE_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'];

    private const array VIMEO_HOSTS = ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'];

    private const array FILE_EXTENSIONS = ['mp4', 'webm', 'mov'];

    private function __construct(public string $kind, public string $src) {}

    /** How a link is shown, or null when it is not a web address at all. */
    public static function fromUrl(string $url): ?self
    {
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        parse_str((string) ($parts['query'] ?? ''), $query);

        $youtube = self::youtubeId($host, $path, $query);

        if ($youtube !== null) {
            return new self(self::YOUTUBE, 'https://www.youtube-nocookie.com/embed/'.$youtube);
        }

        $vimeo = self::vimeoAddress($host, $path, $query);

        if ($vimeo !== null) {
            return new self(self::VIMEO, $vimeo);
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // Played as given, since a <video> fetches it like any image would; only https, so an
        // operator's panel never loads media over plain http.
        if ($scheme === 'https' && in_array($extension, self::FILE_EXTENSIONS, true)) {
            return new self(self::FILE, trim($url));
        }

        return new self(self::LINK, trim($url));
    }

    /**
     * An eleven-character video id from any of the shapes YouTube hands out.
     *
     * @param  array<mixed>  $query
     */
    private static function youtubeId(string $host, string $path, array $query): ?string
    {
        $candidate = null;

        if ($host === 'youtu.be') {
            $candidate = explode('/', ltrim($path, '/'))[0];
        } elseif (in_array($host, self::YOUTUBE_HOSTS, true)) {
            if ($path === '/watch') {
                $candidate = is_string($query['v'] ?? null) ? $query['v'] : null;
            } elseif (preg_match('#^/(embed|shorts|live|v)/([^/]+)#', $path, $matches) === 1) {
                $candidate = $matches[2];
            }
        }

        return is_string($candidate) && preg_match('/^[A-Za-z0-9_-]{11}$/', $candidate) === 1 ? $candidate : null;
    }

    /**
     * The player address for a Vimeo video, keeping the privacy hash an unlisted one needs.
     *
     * @param  array<mixed>  $query
     */
    private static function vimeoAddress(string $host, string $path, array $query): ?string
    {
        if (! in_array($host, self::VIMEO_HOSTS, true)) {
            return null;
        }

        $pattern = $host === 'player.vimeo.com' ? '#^/video/(\d+)(?:/([0-9a-f]+))?#' : '#^/(\d+)(?:/([0-9a-f]+))?#';

        if (preg_match($pattern, $path, $matches) !== 1) {
            return null;
        }

        $hash = $matches[2] ?? (is_string($query['h'] ?? null) ? $query['h'] : null);
        $address = 'https://player.vimeo.com/video/'.$matches[1];

        return is_string($hash) && preg_match('/^[0-9a-f]+$/', $hash) === 1 ? $address.'?h='.$hash : $address;
    }
}
