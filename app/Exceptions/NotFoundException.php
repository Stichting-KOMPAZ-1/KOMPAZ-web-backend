<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Exceptions\Contracts\ProvidesProblemDetail;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The thing a request names does not exist, or does not exist as far as this caller is concerned.
 */
final class NotFoundException extends RuntimeException implements ProvidesProblemDetail
{
    public function __construct(string $message = 'Het opgevraagde onderdeel is niet gevonden.')
    {
        parent::__construct($message);
    }

    /** Names what was looked for, the way every other not-found message in this API reads. */
    public static function for(string $name, string|int $key): self
    {
        return new self(sprintf('"%s" (%s) is niet gevonden.', $name, $key));
    }

    public function problemStatus(): int
    {
        return Response::HTTP_NOT_FOUND;
    }
}
