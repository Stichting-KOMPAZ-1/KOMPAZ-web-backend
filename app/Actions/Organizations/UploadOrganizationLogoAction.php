<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Events\OrganizationLogoDiscarded;
use App\Models\Organization;
use App\Models\OrganizationLogo;
use App\Models\User;
use App\Support\Access\OrganizationAccess;
use App\Support\Images\LogoImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Sets or replaces the organization's logo. */
final readonly class UploadOrganizationLogoAction
{
    public function execute(User $actor, Organization $organization, string $content): Organization
    {
        OrganizationAccess::ensureCanManage($actor, (string) $organization->getKey());

        // Not null: the request has already refused anything this cannot recognize. Read again
        // rather than passed along, because the format is a property of the bytes and not of the
        // request that carried them.
        $contentType = LogoImage::detectContentType($content);

        if ($contentType === null) {
            throw new \LogicException('Unrecognized logo content reached the action past its validation.');
        }

        $key = OrganizationLogo::mintKey(
            (string) $organization->getKey(),
            LogoImage::extensionFor($contentType),
        );

        // Written before the row that names it, and to a key nothing points at yet, so the image
        // being served right now is untouched until the save below succeeds. The order matters: the
        // other way round, a row would name a file that does not exist for as long as the upload
        // takes, and a reader in that window would get the placeholder instead of the logo they had
        // a moment ago.
        //
        // What this costs is a file nobody claims if the save then fails. That is the trade the
        // whole design makes, and it is why nothing here tries to be clever about undoing it.
        Storage::put($key, $content);

        DB::transaction(function () use ($organization, $key, $contentType, $content): void {
            $logo = $organization->logo;

            if ($logo !== null) {
                // Raises the discarding of the file it stops naming, which is cleaned up once this
                // is committed.
                OrganizationLogoDiscarded::dispatch($logo->storage_key);

                $logo->storage_key = $key;
                $logo->content_type = $contentType;
                $logo->byte_count = strlen($content);
                $logo->save();

                return;
            }

            $organization->logo()->create([
                'storage_key' => $key,
                'content_type' => $contentType,
                'byte_count' => strlen($content),
            ]);
        });

        return $organization->load('logo');
    }
}
