<?php

declare(strict_types=1);

namespace App\Support\Images;

use App\Support\Organizations\OrganizationMessages;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * The two rejections an upload can meet: too large, and bytes that are not an image this accepts.
 *
 * A rule object rather than a check written out where it is needed, because three forms ask it —
 * the API's upload, the panel's upload, and the panel's create — and an upload refused in one of
 * them has to be refused in the others, in the same words. What counts as too large and what
 * counts as an image are {@see LogoImage}'s to say; this only asks.
 *
 * Deliberately says nothing about a missing file. That is `required` or `nullable`, which is the
 * one thing the three forms genuinely disagree about: creating an organization without a logo is
 * allowed, replacing its logo with nothing is not.
 */
final class AcceptableLogo implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Anything that is not a readable upload has already been refused by `file`, and saying so
        // twice would put two sentences under one field.
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            return;
        }

        if (LogoImage::exceedsMaximumSize($value->getSize() ?: 0)) {
            $fail(OrganizationMessages::logoTooLarge());

            return;
        }

        // Read out of the bytes, never taken from the upload's own `Content-Type` or its file
        // name: the stored media type is what a later response is labelled with.
        if (LogoImage::detectContentType((string) file_get_contents($value->getRealPath())) === null) {
            $fail(OrganizationMessages::logoWrongFormat());
        }
    }
}
