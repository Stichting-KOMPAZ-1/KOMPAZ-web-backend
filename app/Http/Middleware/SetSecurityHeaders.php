<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers the frontend's nginx used to add to everything it forwarded here. The shared proxy
 * that replaced it adds `Strict-Transport-Security` and `X-Content-Type-Options` itself, so these
 * two are what is left for this application to say: the panel is never framed, and a link out of
 * it does not carry its path along.
 *
 * A header a response sets for itself wins — the sign-in page sends no referrer at all.
 */
final class SetSecurityHeaders
{
    private const HEADERS = [
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
