<?php

declare(strict_types=1);

namespace App\Models;

use App\Events\OrganizationLogoDiscarded;
use App\Models\Concerns\StampsAuditor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The image an organization is shown with. At most one per organization, replaced rather than
 * added to.
 *
 * The row is a pointer, not the picture: the bytes live on the file disk and this records where,
 * together with what they were recognized as. Keeping the two apart is what lets the database stay
 * small while the disk holds whatever size an upload turns out to be.
 *
 * The cost of that split is that letting go of a file is no longer one transaction. Every path
 * that stops pointing at an image raises {@see OrganizationLogoDiscarded} and the file
 * is removed after the database commits, which means a failure there leaves a file nothing points
 * at rather than a row pointing at nothing. That is the way round it has to be: an orphan costs
 * storage and is logged, while a dangling pointer would be a broken image on somebody's screen.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $storage_key
 * @property string $content_type
 * @property int $byte_count
 */
class OrganizationLogo extends Model
{
    use HasUuids, StampsAuditor;

    protected $fillable = [
        'organization_id',
        'storage_key',
        'content_type',
        'byte_count',
    ];

    /**
     * Mints a key for an organization's logo.
     *
     * A fresh identifier every time, so replacing an image writes a new file rather than
     * overwriting the one still being served: the old key stays valid until the new row is
     * committed, and only then is it discarded. Never accepted from a caller — it names the
     * organization, so nothing an upload says can reach another organization's file.
     */
    public static function mintKey(string $organizationId, string $extension): string
    {
        return sprintf('organizations/%s/logo-%s.%s', $organizationId, Str::orderedUuid()->getHex(), $extension);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected function casts(): array
    {
        return [
            'byte_count' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
