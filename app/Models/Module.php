<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ModuleStatus;
use App\Models\Concerns\DiscardsStoredFiles;
use App\Models\Concerns\StampsAuditor;
use App\Support\Files\StoredFile;
use Database\Factories\ModuleFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A unit of instruction, written once by the platform and switched on per organization.
 *
 * The thing to keep straight about a module is which of its parts are shared and which are not.
 * The name, the picture, the description, the courses, the platform's own videos and links: shared,
 * identical for everybody who can see it. An organization's own videos, its own links and its
 * contact details: not shared, and reached through {@see ModuleActivation} rather than from here.
 * A method on this model that returned "the videos" without saying whose would be the bug that
 * shows one organization another one's phone number.
 *
 * @property string $id
 * @property string $name
 * @property string $category_id
 * @property string $description
 * @property string|null $source_attribution
 * @property ModuleStatus $status
 * @property string|null $image_storage_key
 * @property string|null $image_content_type
 * @property int|null $image_byte_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read ModuleCategory $category
 * @property-read Collection<int, ELearning> $eLearnings
 * @property-read Collection<int, ModuleActivation> $activations
 * @property-read Collection<int, ModuleVideo> $videos
 * @property-read Collection<int, ModuleLink> $links
 */
class Module extends Model
{
    use DiscardsStoredFiles, HasUuids, StampsAuditor;

    /** @use HasFactory<ModuleFactory> */
    use HasFactory;

    /** Read by the validator, by the column and by the message that quotes the number. */
    public const int MAXIMUM_NAME_LENGTH = 200;

    /** Where the module's own picture is kept, under the disk's root. */
    public const string IMAGE_PREFIX = 'modules';

    protected $fillable = [
        'name',
        'category_id',
        'description',
        'source_attribution',
        'status',
    ];

    /** @return BelongsTo<ModuleCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ModuleCategory::class, 'category_id');
    }

    /**
     * The courses this module shows. Attaching and detaching here changes the link and nothing
     * else — a course removed from a module goes on existing, and is what the deletion warning
     * promises an operator.
     *
     * @return BelongsToMany<ELearning, $this>
     */
    public function eLearnings(): BelongsToMany
    {
        return $this->belongsToMany(ELearning::class);
    }

    /**
     * The organizations this module is switched on for, one row each.
     *
     * @return HasMany<ModuleActivation, $this>
     */
    public function activations(): HasMany
    {
        return $this->hasMany(ModuleActivation::class);
    }

    /**
     * Those organizations themselves, for the pickers and the counts.
     *
     * @return BelongsToMany<Organization, $this>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'module_activations')
            ->withPivot('activated_at');
    }

    /**
     * The platform's own videos on this module — the ones everybody sees. Not an organization's.
     *
     * @return HasMany<ModuleVideo, $this>
     */
    public function videos(): HasMany
    {
        return $this->hasMany(ModuleVideo::class)->orderBy('position')->orderBy('id');
    }

    /**
     * The platform's own extra links. Not an organization's.
     *
     * @return HasMany<ModuleLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(ModuleLink::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Where the module's picture is, and what it was recognized as. Null when it has none: a
     * module is created with one now (KOM-41), but the columns are nullable because modules
     * written before that rule exist without one, and nothing can take a picture away again.
     */
    public function image(): ?StoredFile
    {
        if ($this->image_storage_key === null || $this->image_content_type === null) {
            return null;
        }

        return new StoredFile(
            $this->image_storage_key,
            $this->image_content_type,
            (int) $this->image_byte_count,
        );
    }

    /** Points the module at a picture. The file it stopped pointing at is the caller's to discard. */
    public function applyImage(StoredFile $image): void
    {
        $this->image_storage_key = $image->key;
        $this->image_content_type = $image->contentType;
        $this->image_byte_count = $image->byteCount;
    }

    /** This module's activation for one organization, or null when it is not switched on there. */
    public function activationFor(string $organizationId): ?ModuleActivation
    {
        return $this->activations()->where('organization_id', $organizationId)->first();
    }

    /**
     * The picture, plus every uploaded video the cascades are about to destroy — the platform's own
     * and every organization's, since both hang below this row.
     *
     * Asked before the delete, because a foreign key removes those rows without Eloquent seeing
     * them and afterwards nothing knows where their bytes were.
     *
     * @return list<string>
     */
    public function discardableKeys(): array
    {
        $keys = $this->image() === null ? [] : [$this->image_storage_key];

        $videos = ModuleVideo::query()
            ->where('module_id', $this->getKey())
            ->orWhereIn(
                'module_activation_id',
                ModuleActivation::query()->where('module_id', $this->getKey())->select('id'),
            )
            ->whereNotNull('file_storage_key')
            ->pluck('file_storage_key')
            ->all();

        /** @var list<string> */
        return array_values(array_filter(array_merge($keys, $videos), 'is_string'));
    }

    /** @return list<string> */
    protected function storedFileColumns(): array
    {
        return ['image_storage_key'];
    }

    protected function casts(): array
    {
        return [
            'status' => ModuleStatus::class,
            'image_byte_count' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
