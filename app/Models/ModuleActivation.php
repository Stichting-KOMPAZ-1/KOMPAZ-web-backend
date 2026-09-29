<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\StampsAuditor;
use Database\Factories\ModuleActivationFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One organization's copy of a module: the fact that it is switched on there, plus everything that
 * organization added to it.
 *
 * This is the tenant boundary for the whole module feature. A module row belongs to nobody; an
 * activation belongs to exactly one organization, and every organization-specific thing hangs off
 * it. An organization administrator reading the panel is reading activations, and the scoping
 * question they have to be asked is about `organization_id` on this row.
 *
 * @property string $id
 * @property string $module_id
 * @property string $organization_id
 * @property Carbon $activated_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Module $module
 * @property-read Organization $organization
 * @property-read Collection<int, ModuleVideo> $videos
 * @property-read Collection<int, ModuleLink> $links
 * @property-read Collection<int, ModuleContact> $contacts
 */
class ModuleActivation extends Model
{
    /** @use HasFactory<ModuleActivationFactory> */
    use HasFactory;

    use HasUuids, StampsAuditor;

    protected $fillable = [
        'module_id',
        'organization_id',
        'activated_at',
    ];

    /** @return BelongsTo<Module, $this> */
    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * This organization's own videos, shown alongside the platform's.
     *
     * @return HasMany<ModuleVideo, $this>
     */
    public function videos(): HasMany
    {
        return $this->hasMany(ModuleVideo::class)->orderBy('position')->orderBy('id');
    }

    /**
     * This organization's own extra links.
     *
     * @return HasMany<ModuleLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(ModuleLink::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Who to ring about this module at this organization.
     *
     * @return HasMany<ModuleContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(ModuleContact::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Whether this organization has said who to ring — the question the organization
     * administrator's table asks of every row, because it is the one thing they must not leave
     * undone.
     *
     * Reads a loaded relation when there is one, so a table that eager-loaded the counts does not
     * ask the database again for every line. Callers listing many rows should load
     * `contacts` or its count; a single row can afford the query.
     */
    public function hasContactDetails(): bool
    {
        if ($this->relationLoaded('contacts')) {
            return $this->contacts->isNotEmpty();
        }

        $counted = $this->getAttribute('contacts_count');

        if ($counted !== null) {
            return (int) $counted > 0;
        }

        return $this->contacts()->exists();
    }

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
