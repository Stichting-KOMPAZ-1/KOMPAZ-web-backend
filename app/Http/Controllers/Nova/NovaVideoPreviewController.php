<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nova;

use App\Enums\ContentBlockType;
use App\Exceptions\NotFoundException;
use App\Models\ContentBlock;
use App\Models\ModuleVideo;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use App\Support\Access\OrganizationAccess;
use App\Support\Files\StoredFile;
use App\Support\Videos\VideoPlayback;
use Illuminate\Http\Request;

/**
 * An uploaded video as the panel shows it back to whoever is editing it.
 *
 * The API's own addresses are for readers, and they are reached through a module the reader was
 * given — which an operator writing that module, or writing an organization's copy of it, is not
 * necessarily. So the panel asks the question an edit asks instead: the platform's videos and a
 * step's blocks are the platform's (`ModuleAccess`), an organization's own videos are that
 * organization's (`OrganizationAccess`), exactly as saving them is. The answer is the same
 * redirect to a short-lived, read-only link a reader gets.
 */
final readonly class NovaVideoPreviewController
{
    public function moduleVideo(Request $request, ModuleVideo $moduleVideo): VideoPlayback
    {
        $actor = self::actor($request);

        if ($moduleVideo->module_activation_id === null) {
            ModuleAccess::ensureCanManageContent($actor);
        } else {
            OrganizationAccess::ensureCanManage($actor, (string) $moduleVideo->activation?->organization_id);
        }

        return VideoPlayback::for(self::uploaded($moduleVideo->file()));
    }

    public function contentBlock(Request $request, ContentBlock $contentBlock): VideoPlayback
    {
        ModuleAccess::ensureCanManageContent(self::actor($request));

        if ($contentBlock->type !== ContentBlockType::Video) {
            throw new NotFoundException('Dit blok is geen video.');
        }

        return VideoPlayback::for(self::uploaded($contentBlock->file()));
    }

    private static function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }

    private static function uploaded(?StoredFile $file): StoredFile
    {
        if ($file === null) {
            throw new NotFoundException('Deze video is een link en heeft geen bestand.');
        }

        return $file;
    }
}
