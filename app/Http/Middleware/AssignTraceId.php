<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request one identifier that the logs and the error response both quote, so a report
 * of "it said something went wrong" can be found in a log without having to guess at the minute.
 *
 * A caller-supplied `X-Request-Id` is honoured when there is one, because a reverse proxy or a
 * frontend that already stamps its calls has the longer view of the same request.
 */
final class AssignTraceId
{
    public function handle(Request $request, Closure $next): Response
    {
        $traceId = $request->header('X-Request-Id');

        if ($traceId === null || $traceId === '' || mb_strlen($traceId) > 200) {
            $traceId = (string) Str::uuid();
        }

        $request->attributes->set('trace_id', $traceId);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Request-Id', $traceId);

        return $response;
    }
}
