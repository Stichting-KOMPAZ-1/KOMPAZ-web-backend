<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\DiscardsStoredFiles;
use App\Models\Concerns\StampsAuditor;
use Database\Factories\ChapterFactory;
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
 * A section of a course, and the steps it is made of.
 *
 * `is_summary` marks the chapter the front end draws differently. Several are allowed for now by
 * product decision, so nothing here refuses a second one — if that changes it becomes a rule in an
 * action, not a unique index, because the answer is about a course rather than about a row.
 *
 * @property string $id
 * @property string $e_learning_id
 * @property string $name
 * @property string|null $description
 * @property bool $is_summary
 * @property int $position
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read ELearning $eLearning
 * @property-read Collection<int, Step> $steps
 */
class Chapter extends Model implements Sortable
{
    use DiscardsStoredFiles, HasUuids, SortableTrait, StampsAuditor;

    /** @use HasFactory<ChapterFactory> */
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
        'e_learning_id',
        'name',
        'description',
        'is_summary',
        'position',
    ];

    /** @return BelongsTo<ELearning, $this> */
    public function eLearning(): BelongsTo
    {
        return $this->belongsTo(ELearning::class);
    }

    /**
     * The screens, in the order an operator arranged them.
     *
     * @return HasMany<Step, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(Step::class)->orderBy('position');
    }

    /**
     * A chapter written in the panel goes at the end of its course. The form does not ask for a
     * place, and the column has no default because the right number depends on the siblings.
     */
    protected static function booted(): void
    {
        static::creating(function (self $chapter): void {
            $chapter->position ??= self::query()
                ->where('e_learning_id', $chapter->e_learning_id)
                ->count();
        });
    }

    /**
     * The files of every block of every step, which the cascade below destroys without Eloquent
     * seeing one of them.
     *
     * @return list<string>
     */
    public function discardableKeys(): array
    {
        $keys = ContentBlock::query()
            ->whereIn('step_id', Step::query()->where('chapter_id', $this->getKey())->select('id'))
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
     * The rows this one is ordered among: its own course's, never the whole table.
     *
     * @return Builder<self>
     */
    public function buildSortQuery(): Builder
    {
        return self::query()->where('e_learning_id', $this->e_learning_id);
    }

    protected function casts(): array
    {
        return [
            'is_summary' => 'boolean',
            'position' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
