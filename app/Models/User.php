<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoginTokenPurpose;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Concerns\StampsAuditor;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * A person who can sign in. Users authenticate with an emailed single-use link; there are no
 * passwords anywhere in this application.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $email
 * @property string $normalized_email
 * @property string $name
 * @property UserRole $role
 * @property UserStatus $status
 * @property Carbon|null $invited_at
 * @property Carbon|null $activated_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Organization $organization
 */
class User extends Authenticatable
{
    use HasApiTokens, HasUuids, Notifiable, SoftDeletes, StampsAuditor;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    public const int MAXIMUM_NAME_LENGTH = 200;

    public const int MAXIMUM_EMAIL_LENGTH = 320;

    protected $fillable = [
        'organization_id',
        'email',
        'normalized_email',
        'name',
        'role',
        'status',
        'invited_at',
        'activated_at',
        'last_login_at',
    ];

    /** Folds an email address for storage and lookup. */
    public static function normalize(string $email): string
    {
        return mb_strtoupper(trim($email));
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<LoginToken, $this> */
    public function loginTokens(): HasMany
    {
        return $this->hasMany(LoginToken::class);
    }

    /**
     * The outstanding invitation, if one is still unspent. At most one is: issuing a new one
     * retires the last.
     *
     * @return HasMany<LoginToken, $this>
     */
    public function outstandingInvitations(): HasMany
    {
        return $this->loginTokens()
            ->where('purpose', LoginTokenPurpose::Invitation)
            ->whereNull('consumed_at');
    }

    /**
     * Limits a query to the people an organization's roster shows.
     *
     * @param  Builder<User>  $query
     */
    public function scopeAdministrators(Builder $query): void
    {
        $query->whereIn('role', [UserRole::Administrator->value, UserRole::PlatformAdministrator->value]);
    }

    /** Whether an administrator has deleted this user. */
    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    /** Records that the user proved ownership of their email address. Activating twice is a no-op. */
    public function activate(Carbon $now): void
    {
        if ($this->status === UserStatus::Active) {
            return;
        }

        $this->status = UserStatus::Active;
        $this->activated_at = $now;
    }

    /**
     * Points the account at a different address.
     *
     * This is the sign-in identity, not a contact detail, so whoever holds the new inbox can sign
     * in as this person from now on. Nothing here proves they asked for it — an administrator is
     * trusted to have checked — which is why the caller also retires any link already sent to the
     * old address.
     */
    public function changeEmail(string $email): void
    {
        $this->email = trim($email);
        $this->normalized_email = self::normalize($email);
    }

    /**
     * Moves the user into another organization, which costs them their role.
     *
     * A role is held within an organization and says nothing about the next one, so carrying it
     * across would hand somebody rights over people who never appointed them. They arrive as a
     * member and are promoted there if the new organization wants that.
     */
    public function moveTo(string $organizationId): void
    {
        $this->organization_id = $organizationId;
        $this->role = UserRole::Member;
    }

    /**
     * Brings a deleted user back as a fresh invitation, under whatever name and role the new
     * invitation names.
     *
     * This is what stops deleting somebody from burning their email address for good. The address
     * is the unique key and the row outlives the deletion, so inviting it again reuses that row —
     * which also keeps every log and audit entry pointing at the same person. `activated_at` and
     * `last_login_at` are left alone on purpose: they record what did happen, and this invitation
     * has not been accepted yet.
     */
    public function reviveAsInvited(string $name, UserRole $role, Carbon $now): void
    {
        $this->deleted_at = null;
        $this->name = trim($name);
        $this->role = $role;
        $this->status = UserStatus::Invited;
        $this->invited_at = $now;
    }

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'invited_at' => 'datetime',
            'activated_at' => 'datetime',
            'last_login_at' => 'datetime',
            'deleted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
