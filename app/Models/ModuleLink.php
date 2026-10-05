<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\StampsAuditor;
use App\Support\Links\WebAddress;
use Database\Factories\ModuleLinkFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An extra link on a module: either the platform's, or one an organization added.
 *
 * Owned the way {@see ModuleVideo} is, and simpler for the one reason a link always is — there is
 * nothing to upload, so there is no second thing it could have been.
 *
 * @property string $id
 * @property string|null $module_id
 * @property string|null $module_activation_id
 * @property string $title
 * @property string $url
 * @property int $position
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Module|null $module
 * @property-read ModuleActivation|null $activation
 */
class ModuleLink extends Model
{
    /** @use HasFactory<ModuleLinkFactory> */
    use HasFactory;

    use HasUuids, StampsAuditor;

    /** Read by the validator, by the column and by the message that quotes the number. */
    public const int MAXIMUM_TITLE_LENGTH = 200;

    protected $fillable = [
        'module_id',
        'module_activation_id',
        'title',
        'url',
        'position',
    ];

    /** @return BelongsTo<Module, $this> */
    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    /** @return BelongsTo<ModuleActivation, $this> */
    public function activation(): BelongsTo
    {
        return $this->belongsTo(ModuleActivation::class, 'module_activation_id');
    }

    protected function casts(): array
    {
        return [
            'url' => WebAddress::class,
            'position' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
