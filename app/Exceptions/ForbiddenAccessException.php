<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Exceptions\Contracts\ProvidesProblemDetail;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The caller is who they say they are and still may not do this.
 */
final class ForbiddenAccessException extends RuntimeException implements ProvidesProblemDetail
{
    public function __construct(string $message = 'Toegang tot het opgevraagde onderdeel is niet toegestaan.')
    {
        parent::__construct($message);
    }

    public function problemStatus(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
