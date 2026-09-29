<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentBlockType;
use App\Models\Concerns\DiscardsStoredFiles;
use App\Models\Concerns\StampsAuditor;
use App\Models\Contracts\HoldsVideo;
use App\Support\Files\StoredFile;
use Database\Factories\ContentBlockFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A piece of a step: text, a picture or a video.
 *
 * The type is the discriminator and the columns follow from it. What a kind must have and must not
 * have is stated on the table as check constraints, so this class does not restate it — the
 * methods here are about reading a block, not about deciding whether it is a legal one.
 *
 * @property string $id
 * @property string $step_id
 * @property ContentBlockType $type
 * @property string|null $title
 * @property string|null $body
 * @property string|null $file_storage_key
 * @property string|null $file_content_type
 * @property int|null $file_byte_count
 * @property string|null $video_url
 * @property int $position
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Step $step
 */
class ContentBlock extends Model implements HoldsVideo
{
    use DiscardsStoredFiles, HasUuids, StampsAuditor;

    /** @use HasFactory<ContentBlockFactory> */
    use HasFactory;

    /** Read by the validator, by the column and by the message that quotes the number. */
    public const int MAXIMUM_TITLE_LENGTH = 200;

    /**
     * Where a picture block's picture is kept, under the default disk's root. A video block's
     * upload is on the video disk, under the key its upload was given (see VideoStorage).
     */
    public const string FILE_PREFIX = 'content-blocks';

    protected $fillable = [
        'step_id',
        'type',
        'title',
        'body',
        'video_url',
        'position',
    ];

    /** @return BelongsTo<Step, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(Step::class);
    }

    /** A video block's link, when it is one. */
    public function videoUrl(): ?string
    {
        return $this->video_url;
    }

    /** The picture or video this block holds, when it holds one of its own. */
    public function file(): ?StoredFile
    {
        if ($this->file_storage_key === null || $this->file_content_type === null) {
            return null;
        }

        return new StoredFile($this->file_storage_key, $this->file_content_type, (int) $this->file_byte_count);
    }

    /**
     * Points the block at a file, clearing the link it may have been.
     *
     * A video block is a link or an upload, never both, and setting one clears the other here as
     * well as in the constraint — a save refused by the database would be a failure the operator
     * did not cause and could not read.
     */
    public function applyFile(StoredFile $file): void
    {
        $this->file_storage_key = $file->key;
        $this->file_content_type = $file->contentType;
        $this->file_byte_count = $file->byteCount;
        $this->video_url = null;
    }

    /** Points a video block at a link, clearing the file it may have been. */
    public function applyVideoUrl(string $url): void
    {
        $this->video_url = trim($url);
        $this->file_storage_key = null;
        $this->file_content_type = null;
        $this->file_byte_count = null;
    }

    /**
     * Its own picture or video, when it holds one.
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
            'type' => ContentBlockType::class,
            'file_byte_count' => 'integer',
            'position' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
