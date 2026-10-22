<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\DiscardsStoredFiles;
use App\Models\Concerns\StampsAuditor;
use App\Support\Files\StoredFile;
use App\Support\Html\SanitizedHtml;
use Database\Factories\CarouselSlideFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One slide of a "Carrousel" block (KOM-100): a picture, a title beside it, and a body it may have.
 *
 * A row under its block rather than a block of its own, because one carousel holds many slides and
 * a reader pages through them in one place. The picture is a file a row points at, which is why
 * the slides are rows and not a JSON column: bytes inside a JSON document could never be let go of,
 * because nothing would know where they were (rule 24). The order is `position`; the "1/12" a
 * reader sees is counted from it, not stored.
 *
 * @property string $id
 * @property string $content_block_id
 * @property string $title
 * @property string|null $body
 * @property string $file_storage_key
 * @property string $file_content_type
 * @property int $file_byte_count
 * @property int $position
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read ContentBlock $block
 */
class CarouselSlide extends Model
{
    use DiscardsStoredFiles, HasUuids, StampsAuditor;

    /** @use HasFactory<CarouselSlideFactory> */
    use HasFactory;

    /** Read by the validator and by the column, as a block's own title is. */
    public const int MAXIMUM_TITLE_LENGTH = 200;

    /** How many slides one carousel holds. A count is the one rule a row cannot state (rule 26). */
    public const int MAXIMUM_PER_CAROUSEL = 25;

    /** Where a slide's picture is kept, under the default disk's root. */
    public const string FILE_PREFIX = 'carousel-slides';

    protected $fillable = [
        'content_block_id',
        'title',
        'body',
        'position',
    ];

    /** @return BelongsTo<ContentBlock, $this> */
    public function block(): BelongsTo
    {
        return $this->belongsTo(ContentBlock::class, 'content_block_id');
    }

    /** The slide's picture. Null only on a slide that has not been saved yet. */
    public function file(): ?StoredFile
    {
        if (! isset($this->attributes['file_storage_key'], $this->attributes['file_content_type'])) {
            return null;
        }

        return new StoredFile($this->file_storage_key, $this->file_content_type, $this->file_byte_count);
    }

    /** Points the slide at a picture. The three columns are only ever true together. */
    public function applyFile(StoredFile $file): void
    {
        $this->file_storage_key = $file->key;
        $this->file_content_type = $file->contentType;
        $this->file_byte_count = $file->byteCount;
    }

    /**
     * Its picture.
     *
     * @return list<string>
     */
    public function discardableKeys(): array
    {
        return $this->file() === null ? [] : [$this->file_storage_key];
    }

    /** @return list<string> */
    protected function storedFileColumns(): array
    {
        return ['file_storage_key'];
    }

    protected function casts(): array
    {
        return [
            'body' => SanitizedHtml::class,
            'file_byte_count' => 'integer',
            'position' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
