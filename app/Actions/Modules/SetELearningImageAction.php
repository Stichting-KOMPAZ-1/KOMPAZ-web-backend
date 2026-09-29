<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Models\ELearning;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use App\Support\Files\StoredImage;
use Illuminate\Http\UploadedFile;

/** Replaces a course's picture. There is no removing one: a course always has a picture. */
final readonly class SetELearningImageAction
{
    public function execute(User $actor, ELearning $eLearning, UploadedFile $upload): ELearning
    {
        ModuleAccess::ensureCanManageContent($actor);

        $eLearning->applyImage(StoredImage::store($upload, ELearning::IMAGE_PREFIX, (string) $eLearning->getKey()));
        $eLearning->save();

        return $eLearning;
    }
}
