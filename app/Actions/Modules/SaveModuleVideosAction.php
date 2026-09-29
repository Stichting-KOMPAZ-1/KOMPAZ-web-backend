<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Actions\Videos\ApplyVideoSourceAction;
use App\Models\Module;
use App\Models\ModuleActivation;
use App\Models\ModuleVideo;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use App\Support\Access\OrganizationAccess;
use App\Support\Modules\OrderedRows;
use App\Support\Modules\VideoDetails;
use Illuminate\Support\Facades\DB;

/**
 * Makes a module's videos — the platform's, or one organization's own — the list given.
 *
 * Its own use case because three things write it: the module's two saves in the API and the
 * panel's repeater. Nova's own has-many preset used to, and it deleted every row with a query and
 * wrote the list again on each save; harmless for links, but an upload is a file, and a query
 * delete is one no model event sees (rule 24). So the panel now writes through here too.
 *
 * Who may is asked of the owner. The platform's videos are the platform's (`ModuleAccess`); an
 * organization's are that organization's (`OrganizationAccess`), and are written under its own
 * activation, so a key from anywhere else is a new row rather than a way into somebody else's.
 */
final readonly class SaveModuleVideosAction
{
    public function __construct(private ApplyVideoSourceAction $source) {}

    /** @param  list<VideoDetails>  $videos  the list as it should be afterwards */
    public function execute(User $actor, Module|ModuleActivation $owner, array $videos): void
    {
        if ($owner instanceof Module) {
            ModuleAccess::ensureCanManageContent($actor);
        } else {
            OrganizationAccess::ensureCanManage($actor, $owner->organization_id);
        }

        DB::transaction(function () use ($actor, $owner, $videos): void {
            OrderedRows::write(
                $owner->videos(),
                $videos,
                static fn (VideoDetails $video): ?string => $video->id,
                function (ModuleVideo $row, VideoDetails $video) use ($actor): void {
                    $row->title = trim($video->title);

                    $this->source->execute(
                        $actor,
                        $row,
                        $video->url,
                        $video->uploadId,
                        $video->urlField,
                        $video->uploadField,
                    );
                },
            );
        });
    }
}
