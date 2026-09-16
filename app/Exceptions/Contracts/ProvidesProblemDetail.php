<?php

declare(strict_types=1);

namespace App\Exceptions\Contracts;

/**
 * Implemented by the application's own failures, which carry the status they mean and a Dutch
 * sentence for the person who caused them, instead of falling back to the generic envelope.
 */
interface ProvidesProblemDetail
{
    public function problemStatus(): int;
}
