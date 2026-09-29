<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Models\Chapter;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use App\Support\Modules\ChapterDetails;

/**
 * Creates a chapter at the end of its course, or edits one.
 *
 * The place a new chapter takes is the model's to decide ({@see Chapter}'s `booted()`), so the
 * panel's form and the API append the same way.
 */
final readonly class SaveChapterAction
{
    public function execute(User $actor, Chapter $chapter, ChapterDetails $details): Chapter
    {
        ModuleAccess::ensureCanManageContent($actor);

        $chapter->name = trim($details->name);
        $chapter->description = $details->description;
        $chapter->is_summary = $details->isSummary;
        $chapter->save();

        return $chapter;
    }
}
