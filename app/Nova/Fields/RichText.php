<?php

declare(strict_types=1);

namespace App\Nova\Fields;

use App\Support\Html\SanitizedHtml;
use Laravel\Nova\Fields\Trix;

/**
 * The panel's editor for a prose column that holds markup.
 *
 * Nova's own {@see Trix}, configured once so every such field in the panel is the same one. Two
 * things are worth stating rather than repeating at each site:
 *
 * Attachments stay off. Trix only offers them when {@see Trix::withFiles()} is called, and turning
 * them on would be a second file story next to the one every stored file here already follows — a
 * row pointing at a private disk, discarded through an event (rules 12 and 13). A picture inside a
 * step is an image block, which is a row that can be found again.
 *
 * Trix's own sanitizing is off because {@see SanitizedHtml} is the one place markup is cleaned.
 * The panel is only one of the two doors content is written through, and the other one never
 * reaches a field at all; a field that cleaned as well would be a second allowlist to keep in step
 * with the first (rule 25).
 */
final class RichText extends Trix
{
    protected function configureDefaults(): void
    {
        parent::configureDefaults();

        $this->sanitizesHtml = false;

        // An editor a form hides behind a "show content" link reads as an empty field, which is
        // what every prose field here was already saying with `alwaysShow()` as a Textarea.
        $this->alwaysShow();
    }
}
