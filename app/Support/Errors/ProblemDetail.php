<?php

declare(strict_types=1);

namespace App\Support\Errors;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An RFC 9457 problem response.
 *
 * The member order and names are the contract clients already read, so they are written out
 * explicitly rather than left to whatever order an array happens to have.
 */
final readonly class ProblemDetail implements Responsable
{
    /** @param array<string, list<string>> $errors */
    public function __construct(
        public int $status,
        public string $title,
        public ?string $detail = null,
        public array $errors = [],
    ) {}

    public function toResponse($request): JsonResponse
    {
        /** @var Request $request */
        $payload = [
            'type' => ProblemType::forStatus($this->status),
            'title' => $this->title,
            'status' => $this->status,
        ];

        if ($this->detail !== null && $this->detail !== '') {
            $payload['detail'] = $this->detail;
        }

        // RFC 9457 asks for a URI reference here, so the path alone. The method is already known
        // to whoever sent the request.
        $payload['instance'] = '/'.ltrim($request->path(), '/');

        if ($this->errors !== []) {
            $payload['errors'] = $this->errors;
        }

        $payload['traceId'] = (string) ($request->attributes->get('trace_id') ?? $request->header('X-Request-Id', ''));

        return new JsonResponse(
            $payload,
            $this->status,
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
