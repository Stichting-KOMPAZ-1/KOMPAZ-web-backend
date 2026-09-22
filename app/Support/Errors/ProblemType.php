<?php

declare(strict_types=1);

namespace App\Support\Errors;

/**
 * The status vocabulary every problem this API returns names itself from: the RFC section that
 * defines the status, and the English name of it.
 *
 * English on purpose. `title` names the status from the HTTP specification's vocabulary and is
 * read by whoever is debugging; `detail` is the sentence written for the person using the product,
 * and that one is Dutch.
 */
final class ProblemType
{
    private const string RFC_9110 = 'https://datatracker.ietf.org/doc/html/rfc9110#section-';

    private const string RFC_6585 = 'https://datatracker.ietf.org/doc/html/rfc6585#section-';

    private const string LARAVEL_CSRF = 'https://laravel.com/docs/csrf';

    /** @var array<int, array{type: string, title: string}> */
    private const array DEFAULTS = [
        400 => ['type' => self::RFC_9110.'15.5.1', 'title' => 'Bad Request'],
        401 => ['type' => self::RFC_9110.'15.5.2', 'title' => 'Unauthorized'],
        403 => ['type' => self::RFC_9110.'15.5.4', 'title' => 'Forbidden'],
        404 => ['type' => self::RFC_9110.'15.5.5', 'title' => 'Not Found'],
        405 => ['type' => self::RFC_9110.'15.5.6', 'title' => 'Method Not Allowed'],
        409 => ['type' => self::RFC_9110.'15.5.10', 'title' => 'Conflict'],
        413 => ['type' => self::RFC_9110.'15.5.14', 'title' => 'Content Too Large'],
        415 => ['type' => self::RFC_9110.'15.5.16', 'title' => 'Unsupported Media Type'],
        // Not a status the HTTP specification defines. Laravel answers a CSRF failure with it and
        // the browser application branches on it to fetch a fresh cookie and retry, so it is named
        // here rather than falling through to a bare "Error".
        419 => ['type' => self::LARAVEL_CSRF, 'title' => 'Page Expired'],
        422 => ['type' => self::RFC_9110.'15.5.21', 'title' => 'Unprocessable Content'],
        429 => ['type' => self::RFC_6585.'4', 'title' => 'Too Many Requests'],
        500 => ['type' => self::RFC_9110.'15.6.1', 'title' => 'An error occurred while processing your request.'],
        503 => ['type' => self::RFC_9110.'15.6.4', 'title' => 'Service Unavailable'],
    ];

    public static function forStatus(int $status): string
    {
        return self::DEFAULTS[$status]['type'] ?? self::RFC_9110.'15';
    }

    public static function titleForStatus(int $status): string
    {
        return self::DEFAULTS[$status]['title'] ?? 'Error';
    }
}
