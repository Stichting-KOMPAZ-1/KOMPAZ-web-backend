<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\DiscardsStoredFiles;
use App\Models\Concerns\StampsAuditor;
use App\Support\Files\StoredFile;
use Database\Factories\ModuleVideoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A video on a module: either the platform's, or one an organization added for its own people.
 *
 * Exactly one of the two owners is set, and the database says so rather than this class — see the
 * check constraint on the table. The same goes for the second rule: a video is a link or a file,
 * never both and never neither.
 *
 * @property string $id
 * @property string|null $module_id
 * @property string|null $module_activation_id
 * @property string $title
 * @property string|null $url
 * @property string|null $file_storage_key
 * @property string|null $file_content_type
 * @property int|null $file_byte_count
 * @property int $position
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Module|null $module
 * @property-read ModuleActivation|null $activation
 */
class ModuleVideo extends Model
{
    use DiscardsStoredFiles, HasUuids, StampsAuditor;

    /** @use HasFactory<ModuleVideoFactory> */
    use HasFactory;

    /** Read by the validator, by the column and by the message that quotes the number. */
    public const int MAXIMUM_TITLE_LENGTH = 200;

    /** Where an uploaded video is kept, under the disk's root. */
    public const string FILE_PREFIX = 'module-videos';

    protected $fillable = [
        'module_id',
        'module_activation_id',
        'title',
        'url',
        'position',
    ];

    /** @return BelongsTo<Module, $this> */
    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    /** @return BelongsTo<ModuleActivation, $this> */
    public function activation(): BelongsTo
    {
        return $this->belongsTo(ModuleActivation::class, 'module_activation_id');
    }

    /** Whether this entry is a link somebody pasted rather than a file they uploaded. */
    public function isLinked(): bool
    {
        return $this->url !== null;
    }

    /** The uploaded video, when this entry is one. Null when it is a link. */
    public function file(): ?StoredFile
    {
        if ($this->file_storage_key === null || $this->file_content_type === null) {
            return null;
        }

        return new StoredFile($this->file_storage_key, $this->file_content_type, (int) $this->file_byte_count);
    }

    /**
     * Points the entry at an uploaded file, clearing the link it may have been.
     *
     * The two are mutually exclusive, so setting one clears the other here as well as in the
     * constraint — a row that tripped the constraint would be a save that failed for a reason the
     * operator did not cause.
     */
    public function applyFile(StoredFile $file): void
    {
        $this->file_storage_key = $file->key;
        $this->file_content_type = $file->contentType;
        $this->file_byte_count = $file->byteCount;
        $this->url = null;
    }

    /** Points the entry at a link, clearing the file it may have been. */
    public function applyUrl(string $url): void
    {
        $this->url = trim($url);
        $this->file_storage_key = null;
        $this->file_content_type = null;
        $this->file_byte_count = null;
    }

    /**
     * Its own upload, when it had one. A video owns nothing below it.
     *
     * @return list<string>
     */
    public function discardableKeys(): array
    {
        return $this->file_storage_key === null ? [] : [$this->file_storage_key];
    }

    /** @return list<string> */
    protected function storedFileColumns(): array
    {
        return ['file_storage_key'];
    }

    protected function casts(): array
    {
        return [
            'file_byte_count' => 'integer',
            'position' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
