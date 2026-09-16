<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Exceptions\Contracts\ProvidesProblemDetail;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request that cannot be applied because it clashes with the current state, such as an email
 * address somebody else already has.
 *
 * A conflict rather than a validation failure: the payload was well formed, and the situation it
 * assumed is what changed or was never true.
 */
final class ConflictException extends RuntimeException implements ProvidesProblemDetail
{
    public function __construct(string $message = 'Dit verzoek gaat niet samen met de huidige situatie.')
    {
        parent::__construct($message);
    }

    public function problemStatus(): int
    {
        return Response::HTTP_CONFLICT;
    }
}
