<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Models\ELearning;
use App\Models\Module;
use App\Models\User;
use App\Support\Access\ModuleAccess;
use App\Support\Files\StoredImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Creates a course with its picture, or renames one and says which modules show it.
 *
 * A course is created *with* its picture because its image columns are not nullable: a course is
 * what somebody works through, and it has no placeholder. The key is minted before the insert so
 * the picture is filed under the course's own folder, and the bytes go to the disk before the row
 * that names them (rule 13).
 *
 * The modules list is null when it was not stated, which leaves the links as they are.
 */
final readonly class SaveELearningAction
{
    /** @param  list<string>|null  $moduleIds */
    public function execute(
        User $actor,
        ELearning $eLearning,
        string $name,
        ?array $moduleIds = null,
        ?UploadedFile $image = null,
    ): ELearning {
        ModuleAccess::ensureCanManageContent($actor);

        if (! $eLearning->exists) {
            if ($image === null) {
                throw new LogicException('A course is created with its picture; the request should have refused this.');
            }

            $eLearning->setAttribute('id', $eLearning->newUniqueId());
        }

        if ($image !== null) {
            $eLearning->applyImage(StoredImage::store($image, ELearning::IMAGE_PREFIX, (string) $eLearning->getKey()));
        }

        DB::transaction(function () use ($eLearning, $name, $moduleIds): void {
            $eLearning->name = trim($name);
            $eLearning->save();

            if ($moduleIds !== null) {
                $eLearning->modules()->sync(Module::query()->whereKey($moduleIds)->pluck('id')->all());
            }
        });

        return $eLearning;
    }
}
