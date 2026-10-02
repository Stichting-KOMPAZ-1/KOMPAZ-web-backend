<?php

declare(strict_types=1);

namespace App\Support\Errors;

use Closure;
use Spatie\FlareClient\Enums\FlareEntityType;
use Spatie\FlareClient\Senders\Sender;
use Spatie\LaravelFlare\Senders\LaravelHttpSender;

/**
 * Sends to Flare what Laravel's sender would, with every sign-in secret taken out of it first.
 *
 * A sign-in link carries its secret in the query string (`SignInLink`), and an invitation's stays
 * redeemable for a week. Flare's own censoring covers request bodies and headers but not the
 * addresses it records — the request's own, the entry point, and every span of a trace — so an
 * exception thrown while a link was being claimed would have shipped a working credential to a
 * third party. This is the one place every report, trace and log passes through on its way out,
 * so it is the one place that has to know.
 */
final readonly class RedactingFlareSender implements Sender
{
    /** The query parameter whose value is a credential wherever it appears. */
    private const string SECRET_PARAMETER = 'token';

    private const string CENSORED = '[CENSORED]';

    private LaravelHttpSender $sender;

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        $this->sender = new LaravelHttpSender($config);
    }

    /** @param array<mixed> $payload */
    public function post(
        string $endpoint,
        string $apiToken,
        array $payload,
        FlareEntityType $type,
        bool $test,
        Closure $callback,
    ): void {
        $this->sender->post($endpoint, $apiToken, self::redact($payload), $type, $test, $callback);
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public static function redact(array $payload): array
    {
        array_walk_recursive($payload, static function (mixed &$value): void {
            if (! is_string($value)) {
                return;
            }

            // A pattern that fails to run gives back null, and the value it could not read is
            // dropped whole rather than sent as it was.
            $value = preg_replace(
                '/(^|[?&])('.self::SECRET_PARAMETER.')=[^&#\s"\']*/i',
                '$1$2='.self::CENSORED,
                $value,
            ) ?? self::CENSORED;
        });

        return $payload;
    }
}
