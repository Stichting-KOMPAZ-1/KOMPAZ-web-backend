<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\StampsAuditor;
use App\Support\Html\SanitizedHtml;
use Database\Factories\ModuleContactFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Who to ring about a module, at one organization.
 *
 * Belongs to an activation and never to a module, which is the whole reason activations are rows
 * with keys of their own. A contact on the module would be one organization's number shown to
 * another's people.
 *
 * The name, the job role and the e-mail address are required (KOM-61); the phone number and the
 * two notes are what that organization happens to publish. The job role and the e-mail address
 * are required by the forms and not by the columns, which hold cards written before the rule —
 * so they read as nullable here, honestly, until every card has been saved again.
 *
 * @property string $id
 * @property string $module_activation_id
 * @property string $name
 * @property string|null $job_role
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $reason
 * @property string|null $availability
 * @property int $position
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read ModuleActivation $activation
 */
class ModuleContact extends Model
{
    /** @use HasFactory<ModuleContactFactory> */
    use HasFactory;

    use HasUuids, StampsAuditor;

    /** Read by the validator, by the column and by the message that quotes the number. */
    public const int MAXIMUM_NAME_LENGTH = 200;

    /** Read by the validator and by the column. */
    public const int MAXIMUM_JOB_ROLE_LENGTH = 200;

    protected $fillable = [
        'module_activation_id',
        'name',
        'job_role',
        'email',
        'phone',
        'reason',
        'availability',
        'position',
    ];

    /** @return BelongsTo<ModuleActivation, $this> */
    public function activation(): BelongsTo
    {
        return $this->belongsTo(ModuleActivation::class, 'module_activation_id');
    }

    protected function casts(): array
    {
        return [
            'reason' => SanitizedHtml::class,
            'availability' => SanitizedHtml::class,
            'position' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
