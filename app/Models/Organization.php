<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\StampsAuditor;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A tenant. Every user belongs to exactly one organization.
 *
 * @property string $id
 * @property string $name
 * @property string $normalized_name
 * @property bool $is_platform
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read OrganizationLogo|null $logo
 * @property-read Collection<int, User> $users
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use HasUuids, StampsAuditor;

    /**
     * The longest name an organization may have. Read by the validator, by the column and by the
     * message that quotes the number, so the three cannot disagree about it.
     */
    public const int MAXIMUM_NAME_LENGTH = 200;

    protected $fillable = [
        'name',
        'normalized_name',
        'is_platform',
    ];

    /**
     * Folds a name for storage and lookup. Comparisons are made against the folded column, never
     * against the name as typed.
     */
    public static function normalize(string $name): string
    {
        return mb_strtoupper(trim($name));
    }

    /** @return HasOne<OrganizationLogo, $this> */
    public function logo(): HasOne
    {
        return $this->hasOne(OrganizationLogo::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Whether this organization is out of service. Nobody who belongs to one can sign in. */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** Applies a name, keeping the folded form it is indexed by in step. */
    public function applyName(string $name): void
    {
        $this->name = trim($name);
        $this->normalized_name = self::normalize($name);
    }

    /**
     * Whether somebody in this organization may hold the given role. Platform administration is a
     * job at the organization that runs the platform, so that role does not travel to a tenant;
     * every other role does.
     */
    public function canHold(UserRole $role): bool
    {
        return $this->is_platform || $role !== UserRole::PlatformAdministrator;
    }

    protected function casts(): array
    {
        return [
            'is_platform' => 'boolean',
            'archived_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
