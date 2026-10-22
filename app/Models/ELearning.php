<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\DiscardsStoredFiles;
use App\Models\Concerns\StampsAuditor;
use App\Support\Files\StoredFile;
use App\Support\Links\Slug;
use Database\Factories\ELearningFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A course: a name, a picture, and a tree of chapters and steps.
 *
 * Independent of any module. One course can be shown by several modules, by one, or by none, and a
 * deleted module only unlinks it. That is what the pivot buys and why the relation below is
 * `belongsToMany` rather than a column.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $image_storage_key
 * @property string $image_content_type
 * @property int $image_byte_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, Chapter> $chapters
 * @property-read Collection<int, Module> $modules
 */
class ELearning extends Model
{
    use DiscardsStoredFiles, HasUuids, StampsAuditor;

    /** @use HasFactory<ELearningFactory> */
    use HasFactory;

    /** Read by the validator, by the column and by the message that quotes the number. */
    public const int MAXIMUM_NAME_LENGTH = 200;

    /** Where the course's own picture is kept, under the disk's root. */
    public const string IMAGE_PREFIX = 'e-learnings';

    protected $fillable = [
        'name',
    ];

    /**
     * The course's half of its shared address, made from its name when it is created and kept
     * through every rename after, because the address is printed in QR codes ({@see Slug}).
     */
    protected static function booted(): void
    {
        static::creating(function (self $eLearning): void {
            $eLearning->slug ??= Slug::make(
                $eLearning->name,
                static fn (string $slug): bool => self::query()->where('slug', $slug)->exists(),
                'e-learning',
            );
        });
    }

    /**
     * The sections, in the order the front end reads them: the numbered chapters as an operator
     * arranged them, then the summaries as an operator arranged those.
     *
     * A summary is always shown last, by product decision, so that is the order and not a way of
     * drawing it — the panel, the API and the reorder endpoints all read it from here. Dragging a
     * summary above a numbered chapter therefore moves it among the summaries and nowhere else.
     *
     * @return HasMany<Chapter, $this>
     */
    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('is_summary')->orderBy('position');
    }

    /**
     * The modules that show this course. Written from either form in the panel, since both edit
     * the same pivot.
     *
     * @return BelongsToMany<Module, $this>
     */
    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class);
    }

    /** Where the course's picture is, and what it was recognized as. */
    public function image(): StoredFile
    {
        return new StoredFile(
            $this->image_storage_key,
            $this->image_content_type,
            $this->image_byte_count,
        );
    }

    /** Points the course at a picture. The file it stopped pointing at is the caller's to discard. */
    public function applyImage(StoredFile $image): void
    {
        $this->image_storage_key = $image->key;
        $this->image_content_type = $image->contentType;
        $this->image_byte_count = $image->byteCount;
    }

    /**
     * The picture, plus every file the cascades below are about to destroy — the blocks of every
     * step of every chapter, none of which Eloquent will see go.
     *
     * @return list<string>
     */
    public function discardableKeys(): array
    {
        $under = ContentBlock::discardableKeysUnder(
            ContentBlock::query()->whereIn(
                'step_id',
                Step::query()->whereIn(
                    'chapter_id',
                    Chapter::query()->where('e_learning_id', $this->getKey())->select('id'),
                )->select('id'),
            ),
        );

        /** @var list<string> */
        return array_values(array_filter(array_merge([$this->image_storage_key], $under), 'is_string'));
    }

    /** @return list<string> */
    protected function storedFileColumns(): array
    {
        return ['image_storage_key'];
    }

    protected function casts(): array
    {
        return [
            'image_byte_count' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
