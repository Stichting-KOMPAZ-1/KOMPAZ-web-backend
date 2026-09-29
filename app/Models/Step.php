<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\DiscardsStoredFiles;
use App\Models\Concerns\StampsAuditor;
use Database\Factories\StepFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

/**
 * One screen of a chapter.
 *
 * Holds a name and nothing else, because everything a step *is* lives in its blocks. A step must
 * have at least one of those — a rule an action enforces rather than the database, since it is
 * about a set of rows and not about any one of them.
 *
 * @property string $id
 * @property string $chapter_id
 * @property string $name
 * @property int $position
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Chapter $chapter
 * @property-read Collection<int, ContentBlock> $blocks
 */
class Step extends Model implements Sortable
{
    use DiscardsStoredFiles, HasUuids, SortableTrait, StampsAuditor;

    /** @use HasFactory<StepFactory> */
    use HasFactory;

    /** Read by the validator, by the column and by the message that quotes the number. */
    public const int MAXIMUM_NAME_LENGTH = 200;

    /**
     * How the panel's drag-and-drop reads this model's order.
     *
     * Not written on create: {@see booted()} appends, and the package's own version would
     * overwrite a position that was set on purpose.
     *
     * @var array<string, bool|string>
     */
    public array $sortable = [
        'order_column_name' => 'position',
        'sort_when_creating' => false,
        'sort_on_has_many' => true,
    ];

    protected $fillable = [
        'chapter_id',
        'name',
        'position',
    ];

    /** @return BelongsTo<Chapter, $this> */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    /**
     * What is on the screen, in the order it is drawn.
     *
     * @return HasMany<ContentBlock, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(ContentBlock::class)->orderBy('position');
    }

    /** A step written in the panel goes at the end of its chapter, for the reason a chapter does. */
    protected static function booted(): void
    {
        static::creating(function (self $step): void {
            $step->position ??= self::query()
                ->where('chapter_id', $step->chapter_id)
                ->count();
        });
    }

    /**
     * The files of its blocks, which the cascade destroys without Eloquent seeing them.
     *
     * @return list<string>
     */
    public function discardableKeys(): array
    {
        $keys = ContentBlock::query()
            ->where('step_id', $this->getKey())
            ->whereNotNull('file_storage_key')
            ->pluck('file_storage_key')
            ->all();

        return array_values(array_filter($keys, 'is_string'));
    }

    /** @return list<string> */
    protected function storedFileColumns(): array
    {
        return [];
    }

    /**
     * The rows this one is ordered among: its own chapter's, never the whole table.
     *
     * @return Builder<self>
     */
    public function buildSortQuery(): Builder
    {
        return self::query()->where('chapter_id', $this->chapter_id);
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
