<?php

declare(strict_types=1);

namespace App\Support\Errors;

use App\Exceptions\Contracts\ProvidesProblemDetail;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Turns anything thrown out of the application into the problem response for it.
 *
 * `title` names the status in English, from {@see ProblemType}; `detail` carries the Dutch
 * sentence the caller reads. The one exception is a validation failure, which puts its own more
 * specific sentence in `title` — "one or more fields" says more than "Bad Request" does, and that
 * is the copy the form shows.
 */
final class ProblemDetailFactory
{
    public const string VALIDATION_TITLE = 'Een of meer velden zijn niet correct ingevuld.';

    /**
     * Validation failures answer 400, not Laravel's 422.
     *
     * This is the status the API has always returned and the one clients branch on. A payload this
     * application refuses is malformed as far as its own contract is concerned, and moving the
     * number would be a breaking change dressed up as a framework default.
     */
    private const int VALIDATION_STATUS = 400;

    public static function make(Throwable $e, bool $debug = false): ProblemDetail
    {
        return match (true) {
            $e instanceof ValidationException => self::fromValidation($e),
            $e instanceof ProvidesProblemDetail => new ProblemDetail(
                status: $e->problemStatus(),
                title: ProblemType::titleForStatus($e->problemStatus()),
                detail: $e->getMessage(),
            ),

            // Laravel's own refusals, mapped onto the same vocabulary so a client never has to
            // tell a framework failure from an application one.
            $e instanceof AuthenticationException => self::fromStatus(401, null),
            $e instanceof AuthorizationException => self::fromStatus(403, $e->getMessage()),
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => self::fromStatus(404, null),
            $e instanceof ThrottleRequestsException => self::fromStatus(429, null),
            $e instanceof HttpExceptionInterface => self::fromStatus($e->getStatusCode(), $e->getMessage()),

            // Nothing about an unexpected failure is the caller's business. The message is only
            // ever shown where somebody is debugging the application that produced it.
            default => self::fromStatus(500, $debug ? $e->getMessage() : null),
        };
    }

    private static function fromValidation(ValidationException $e): ProblemDetail
    {
        /** @var array<string, list<string>> $errors */
        $errors = $e->errors();

        return new ProblemDetail(
            status: self::VALIDATION_STATUS,
            title: self::VALIDATION_TITLE,
            detail: null,
            errors: $errors,
        );
    }

    private static function fromStatus(int $status, ?string $detail): ProblemDetail
    {
        // Symfony fills the message in with the status name for an abort() that stated none, which
        // would repeat the title back as though it were a sentence written for the reader.
        $title = ProblemType::titleForStatus($status);

        return new ProblemDetail(
            status: $status,
            title: $title,
            detail: $detail !== null && $detail !== '' && $detail !== $title ? $detail : null,
        );
    }
}
