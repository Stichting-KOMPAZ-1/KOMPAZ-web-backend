<?php

declare(strict_types=1);

namespace App\Support\Videos;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Where an uploaded video lives, and how a stored key says so.
 *
 * Videos are on a disk of their own and every other file is on the default one. A row does not
 * record which: every key here is minted by this application and never accepted from a caller, so
 * the key itself can carry it — a video's key starts with {@see KEY_PREFIX} and nothing else's
 * does. That is what lets the one event that lets go of a file, and every cascade that collects
 * keys for it, go on carrying a key and nothing more.
 */
final class VideoStorage
{
    /** The disk in `config/filesystems.php`. */
    public const string DISK = 'videos';

    /** What every video's key starts with, and no other file's. */
    public const string KEY_PREFIX = 'videos/';

    public static function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /** The disk a stored key's bytes are on. */
    public static function diskHolding(string $key): Filesystem
    {
        return self::holds($key) ? self::disk() : Storage::disk();
    }

    /** Whether a stored key names a video. */
    public static function holds(string $key): bool
    {
        return str_starts_with($key, self::KEY_PREFIX);
    }

    /**
     * The key an upload is written under.
     *
     * Named by the upload rather than by what it will belong to, because the browser writes it
     * before anything does: a module being created has no key yet. Without an extension, since the
     * format is not known until the bytes have been read; the media type is kept on the row.
     */
    public static function keyFor(string $uploadId): string
    {
        return self::KEY_PREFIX.$uploadId;
    }
}
