<?php

declare(strict_types=1);

namespace App\Nova\Fields;

use App\Support\Links\AcceptableWebAddress;
use App\Support\Links\WebAddress;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\URL;

/**
 * The panel's input for a link or a video's address.
 *
 * Nova's own {@see URL} field is an `<input type="url">`, so the browser refuses `www.voorbeeld.nl`
 * before the form is even sent, in its own words and its own language — which is the message
 * KOM-73 found nobody would understand. This is a plain text input instead: the server completes
 * the address ({@see WebAddress}) and refuses what cannot be completed in the product's words
 * ({@see AcceptableWebAddress}). Read back, it is still a link, as Nova's own field draws one.
 */
final class WebAddressInput extends Text
{
    protected function configureDefaults(): void
    {
        parent::configureDefaults();

        $this->withMeta(['extraAttributes' => [
            'placeholder' => 'www.voorbeeld.nl',
            'inputmode' => 'url',
            'autocomplete' => 'url',
        ]]);

        $this->displayUsing(static fn (mixed $value): ?string => is_string($value) && $value !== ''
            ? sprintf('<a class="link-default" href="%1$s" target="_blank" rel="noopener noreferrer">%1$s</a>', e($value))
            : null);

        $this->asHtml();
    }
}
