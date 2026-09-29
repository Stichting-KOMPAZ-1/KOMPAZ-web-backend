<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\StampsAuditor;
use App\Support\Files\StoredFile;
use Database\Factories\VideoUploadFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A blob somebody was given leave to write, on its way to becoming a video.
 *
 * Issued, then completed — the blob looked at and found to be a video — then claimed by the row
 * that shows it. Each step is one-way, and claiming is a conditional update rather than a read
 * followed by a write, because two saves naming the same upload must not both get it.
 *
 * @property string $id
 * @property string $issued_to
 * @property string $storage_key
 * @property int $declared_byte_count
 * @property string|null $content_type
 * @property int|null $byte_count
 * @property Carbon|null $verified_at
 * @property Carbon|null $claimed_at
 * @property Carbon $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class VideoUpload extends Model
{
    /** @use HasFactory<VideoUploadFactory> */
    use HasFactory;

    use HasUuids, StampsAuditor;

    protected $fillable = [
        'issued_to',
        'storage_key',
        'declared_byte_count',
        'expires_at',
    ];

    /** Whether the blob has been looked at and found to be a video. */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /** What was written, once it has been looked at. Null until then. */
    public function file(): ?StoredFile
    {
        if ($this->verified_at === null || $this->content_type === null || $this->byte_count === null) {
            return null;
        }

        return new StoredFile($this->storage_key, $this->content_type, $this->byte_count);
    }

    protected function casts(): array
    {
        return [
            'declared_byte_count' => 'integer',
            'byte_count' => 'integer',
            'verified_at' => 'datetime',
            'claimed_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
