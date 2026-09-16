<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * The claim names carried by an access token. Used verbatim on both sides: nothing maps or renames
 * them on the way in, so what the issuer writes is what the guard reads.
 */
final class KompazClaims
{
    public const string SUBJECT = 'sub';

    public const string EMAIL = 'email';

    public const string NAME = 'name';

    public const string ORGANIZATION = 'org';

    public const string ROLE = 'role';
}
