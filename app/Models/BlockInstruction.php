<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\DiscardsStoredFiles;
use App\Models\Concerns\StampsAuditor;
use App\Support\Files\StoredFile;
use App\Support\Html\SanitizedHtml;
use Database\Factories\BlockInstructionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One instruction in a "Stap" block (KOM-99): a heading, its text, and a picture of its own.
 *
 * An instruction rather than a step, because a step in this code is a *part* — the thing the block
 * it belongs to sits on. The number the reader sees is its `position`, not a column: renumbering a
 * list is reordering it, and storing the number as well would be two answers to one question.
 *
 * @property string $id
 * @property string $content_block_id
 * @property string|null $title
 * @property string $body
 * @property string|null $file_storage_key
 * @property string|null $file_content_type
 * @property int|null $file_byte_count
 * @property int $position
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read ContentBlock $block
 */
class BlockInstruction extends Model
{
    use DiscardsStoredFiles, HasUuids, StampsAuditor;

    /** @use HasFactory<BlockInstructionFactory> */
    use HasFactory;

    /** Read by the validator and by the column, as a block's own title is. */
    public const int MAXIMUM_TITLE_LENGTH = 200;

    /** Where an instruction's picture is kept, under the default disk's root. */
    public const string FILE_PREFIX = 'block-instructions';

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

    /** The picture beside this instruction, when it has one. */
    public function file(): ?StoredFile
    {
        if ($this->file_storage_key === null || $this->file_content_type === null) {
            return null;
        }

        return new StoredFile($this->file_storage_key, $this->file_content_type, (int) $this->file_byte_count);
    }

    /** Points the instruction at a picture. The three columns are only ever true together. */
    public function applyFile(StoredFile $file): void
    {
        $this->file_storage_key = $file->key;
        $this->file_content_type = $file->contentType;
        $this->file_byte_count = $file->byteCount;
    }

    /** Lets go of the picture it had, for an instruction whose picture is taken away. */
    public function clearFile(): void
    {
        $this->file_storage_key = null;
        $this->file_content_type = null;
        $this->file_byte_count = null;
    }

    /**
     * Its own picture, when it has one.
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
            'body' => SanitizedHtml::class,
            'file_byte_count' => 'integer',
            'position' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
