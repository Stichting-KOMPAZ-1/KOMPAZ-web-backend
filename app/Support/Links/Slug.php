<?php

declare(strict_types=1);

namespace App\Support\Links;

use Closure;
use Illuminate\Support\Str;

/**
 * The readable part of a shared e-learning's address: `/delen/{organization}/{e-learning}` (KOM-95).
 *
 * Made once, from the name, when an organization or a course is created — and never again. A slug
 * is printed: it goes out in QR codes on paper, so a rename that changed it would break every code
 * already handed to a patient. Unique on its own table, which is all an address needs: the two
 * halves of `/delen/{organization}/{e-learning}` are looked up in different tables.
 *
 * A few words are never a slug, whatever a name says. `qr` was the QR code's own segment,
 * `/delen/{organization}/qr/{e-learning}`, until KOM-110 put the scan in a query string instead;
 * codes printed before then still lead there, so it stays reserved. The rest are the frontend's
 * own paths, reserved while a shared link sat at the root of its domain, where an organization
 * called "Beheer" would have owned the panel's address. `/delen/` ended that; they stay reserved
 * because taking a word off the list would let a new slug read like one of those pages.
 */
final class Slug
{
    /** Long enough for any name worth reading, short enough to keep a QR code coarse. */
    public const int MAXIMUM_LENGTH = 80;

    /** The QR code's own segment, and paths the frontend's domain already answers. */
    public const array RESERVED = [
        'api', 'assets', 'beheer', 'nova', 'nova-api', 'nova-vendor', 'vendor', 'docs', 'storage',
        'up', 'inloggen', 'login', 'uitloggen', 'logout', 'forgot-password', 'wachtwoord-vergeten',
        'admin', 'qr', 'e-learnings', 'modules', 'organisaties', 'bibliotheek',
    ];

    /**
     * A slug for the name, made unique by a number when it is taken or reserved.
     *
     * @param  Closure(string): bool  $taken  whether a slug is in use already
     * @param  string  $fallback  for a name with nothing in it a slug can use, such as one in emoji
     */
    public static function make(string $name, Closure $taken, string $fallback): string
    {
        $base = Str::limit(Str::slug($name, '-', 'nl'), self::MAXIMUM_LENGTH - 4, '');
        $base = rtrim($base, '-');

        if ($base === '') {
            $base = $fallback;
        }

        $candidate = $base;

        for ($number = 2; self::isReserved($candidate) || $taken($candidate); $number++) {
            $candidate = $base.'-'.$number;
        }

        return $candidate;
    }

    public static function isReserved(string $slug): bool
    {
        return in_array($slug, self::RESERVED, true);
    }
}
