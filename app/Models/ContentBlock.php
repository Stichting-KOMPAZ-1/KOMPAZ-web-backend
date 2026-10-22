<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentBlockType;
use App\Models\Concerns\DiscardsStoredFiles;
use App\Models\Concerns\StampsAuditor;
use App\Models\Contracts\HoldsVideo;
use App\Support\Files\StoredFile;
use App\Support\Html\SanitizedHtml;
use App\Support\Links\WebAddress;
use App\Support\Modules\BlockCheckboxes;
use App\Support\Modules\BlockCheckboxesCast;
use App\Support\Modules\BlockTable;
use App\Support\Modules\BlockTableCast;
use Database\Factories\ContentBlockFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A piece of a step: text, a picture, a video, a table, a list of checkboxes or one numbered step.
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
 * @property BlockTable|null $table_content
 * @property BlockCheckboxes|null $checkbox_options
 * @property int $position
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Step $step
 * @property-read Collection<int, CarouselSlide> $slides
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

    /**
     * A carousel's slides, in the order a reader pages through them (KOM-100). Empty for every
     * other kind.
     *
     * @return HasMany<CarouselSlide, $this>
     */
    public function slides(): HasMany
    {
        return $this->hasMany(CarouselSlide::class)->orderBy('position');
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
     * Its own picture or video, and the pictures of its slides.
     *
     * The slides go on the cascade, which Eloquent never sees, so their keys are collected here
     * while the rows are still there to ask (rule 24).
     *
     * @return list<string>
     */
    public function discardableKeys(): array
    {
        $keys = $this->file_storage_key === null ? [] : [$this->file_storage_key];

        foreach ($this->slides()->pluck('file_storage_key') as $key) {
            if (is_string($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Every file under a set of blocks — the blocks' own and their carousels' slides' — for each
     * ancestor that cascades down to them: a part, a chapter, a course. So that a block may own rows
     * that own files is known here and not restated at every level.
     *
     * @param  Builder<self>  $blocks  the blocks to look under, unexecuted
     * @return list<string>
     */
    public static function discardableKeysUnder(Builder $blocks): array
    {
        $own = (clone $blocks)->whereNotNull('file_storage_key')->pluck('file_storage_key')->all();

        $slides = CarouselSlide::query()
            ->whereIn('content_block_id', (clone $blocks)->select('id'))
            ->pluck('file_storage_key')
            ->all();

        /** @var list<string> */
        return array_values(array_filter(array_merge($own, $slides), 'is_string'));
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
            'body' => SanitizedHtml::class,
            'video_url' => WebAddress::class,
            'table_content' => BlockTableCast::class,
            'checkbox_options' => BlockCheckboxesCast::class,
            'file_byte_count' => 'integer',
            'position' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
