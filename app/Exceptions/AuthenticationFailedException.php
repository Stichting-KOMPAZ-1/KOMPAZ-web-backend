<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Exceptions\Contracts\ProvidesProblemDetail;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Credentials that are missing, expired, already spent, or otherwise not accepted.
 *
 * Also what a token that no longer matches its account produces: the token is the thing that is
 * wrong rather than the request, so a client holding a refresh token exchanges it and comes
 * straight back with claims that match the row.
 */
final class AuthenticationFailedException extends RuntimeException implements ProvidesProblemDetail
{
    public function __construct(string $message = 'De opgegeven inloggegevens zijn niet geaccepteerd.')
    {
        parent::__construct($message);
    }

    public function problemStatus(): int
    {
        return Response::HTTP_UNAUTHORIZED;
    }
}
