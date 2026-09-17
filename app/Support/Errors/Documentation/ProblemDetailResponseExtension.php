<?php

declare(strict_types=1);

namespace App\Support\Errors\Documentation;

use App\Providers\AppServiceProvider;
use App\Support\Errors\ProblemDetail;
use App\Support\Errors\ProblemDetailFactory;
use App\Support\Errors\ProblemType;
use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApi;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Documents a refusal the way this API actually answers one.
 *
 * Scramble ships an extension per framework exception, and every one of them describes Laravel's
 * defaults: 422 with `{message, errors}` for a validation failure, `{message}` for the rest. This
 * API answers none of those. {@see ProblemDetailFactory} turns every throwable into
 * {@see ProblemDetail}, which is RFC 9457 `application/problem+json` — and validation answers 400,
 * not 422. A generated document that says otherwise is a contract clients are entitled to believe.
 *
 * Registered last in {@see AppServiceProvider}, because Scramble gives the
 * latest-registered matching extension priority over its own.
 */
final class ProblemDetailResponseExtension extends ExceptionToResponseExtension
{
    /**
     * The exceptions Scramble attaches to an operation on its own, and the status
     * {@see ProblemDetailFactory} answers each one with.
     *
     * Nothing else belongs here. This application's own refusals carry their status at runtime
     * through `ProvidesProblemDetail::problemStatus()`, which no static analysis can read.
     *
     * @var array<class-string, int>
     */
    private const array STATUSES = [
        ValidationException::class => 400,
        AuthenticationException::class => 401,
        AuthorizationException::class => 403,
        ModelNotFoundException::class => 404,
        NotFoundHttpException::class => 404,
    ];

    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType && $this->statusFor($type) !== null;
    }

    public function toResponse(Type $type): ?Response
    {
        if (! $type instanceof ObjectType) {
            return null;
        }

        $status = $this->statusFor($type);

        if ($status === null) {
            return null;
        }

        $isValidation = $type->isInstanceOf(ValidationException::class);

        return Response::make($status)
            ->setDescription(ProblemType::titleForStatus($status))
            ->setContent(
                'application/problem+json',
                Schema::fromType($this->body($status, $isValidation)),
            );
    }

    public function reference(ObjectType $type): Reference
    {
        return new Reference('responses', Str::start($type->name, '\\'), $this->components);
    }

    /** The members {@see ProblemDetail::toResponse()} writes, in the order it writes them. */
    private function body(int $status, bool $isValidation): OpenApi\ObjectType
    {
        $body = (new OpenApi\ObjectType)
            ->addProperty(
                'type',
                (new OpenApi\StringType)
                    ->format('uri')
                    ->setDescription('The section of the HTTP specification that defines this status.')
                    ->example(ProblemType::forStatus($status)),
            )
            ->addProperty(
                'title',
                (new OpenApi\StringType)
                    ->setDescription($isValidation
                        ? 'The sentence the form shows. Dutch, because a validation failure is the one problem whose title is read by the person filling the form in rather than by whoever is debugging.'
                        : 'The English name of the status, from the HTTP specification\'s own vocabulary.')
                    ->example($isValidation ? ProblemDetailFactory::VALIDATION_TITLE : ProblemType::titleForStatus($status)),
            )
            ->addProperty(
                'status',
                (new OpenApi\IntegerType)
                    ->setDescription('The HTTP status, repeated in the body so a stored problem still names it.')
                    ->const($status),
            );

        $required = ['type', 'title', 'status'];

        if (! $isValidation) {
            // Absent whenever there is nothing to add to the status: a 401 never explains itself,
            // and neither does a 404.
            $body->addProperty(
                'detail',
                (new OpenApi\StringType)
                    ->setDescription('What went wrong, in Dutch, written for the person using the product. Omitted when the status says everything.'),
            );
        }

        $body->addProperty(
            'instance',
            (new OpenApi\StringType)
                ->format('uri-reference')
                ->setDescription('The path of the request that was refused.'),
        );

        $required[] = 'instance';

        if ($isValidation) {
            $body->addProperty(
                'errors',
                (new OpenApi\ObjectType)
                    ->setDescription('Every field that failed, and the Dutch messages it failed with.')
                    ->additionalProperties((new OpenApi\ArrayType)->setItems(new OpenApi\StringType)),
            );

            $required[] = 'errors';
        }

        $body->addProperty(
            'traceId',
            (new OpenApi\StringType)
                ->setDescription('The identifier of this request, echoed in the X-Request-Id response header. A caller-supplied X-Request-Id is kept.'),
        );

        $required[] = 'traceId';

        return $body->setRequired($required);
    }

    private function statusFor(ObjectType $type): ?int
    {
        foreach (self::STATUSES as $exception => $status) {
            if ($type->isInstanceOf($exception)) {
                return $status;
            }
        }

        return null;
    }
}
