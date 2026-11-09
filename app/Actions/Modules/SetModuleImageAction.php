<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Models\Module;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use App\Support\Files\StoredImage;
use Illuminate\Http\UploadedFile;

/**
 * Gives a module a picture, or a new one.
 *
 * The bytes are written first and the row second (rule 13). The picture the module stops pointing
 * at is let go of by the model's own discard on save, once the new row is committed. The alt text
 * is replaced with it, because it described the picture that is going.
 */
final readonly class SetModuleImageAction
{
    public function execute(User $actor, Module $module, UploadedFile $upload, ?string $imageAlt): Module
    {
        ModuleAccess::ensureCanManageContent($actor);

        $module->applyImage(StoredImage::store($upload, Module::IMAGE_PREFIX, (string) $module->getKey()));
        $module->image_alt = $imageAlt;
        $module->save();

        return $module;
    }
}
