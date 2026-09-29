<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\StampsAuditor;
use Database\Factories\StepFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
class Step extends Model
{
    /** @use HasFactory<StepFactory> */
    use HasFactory;

    use HasUuids, StampsAuditor;

    /** Read by the validator, by the column and by the message that quotes the number. */
    public const int MAXIMUM_NAME_LENGTH = 200;

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

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
