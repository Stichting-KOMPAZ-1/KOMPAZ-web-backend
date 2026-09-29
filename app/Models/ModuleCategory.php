<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\StampsAuditor;
use Database\Factories\ModuleCategoryFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What a module is filed under.
 *
 * The platform creates, renames and deletes them (KOM-51), in the panel and through the API; the
 * seeder plants the first two on an empty table. The name is unique folded, like every name here
 * (rule 10), which is why a category is written by a use case and not by a Nova form. One still
 * worn by a module cannot be deleted: the foreign key restricts it, and the use case says so first.
 *
 * @property string $id
 * @property string $name
 * @property string $normalized_name
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property-read Collection<int, Module> $modules
 */
class ModuleCategory extends Model
{
    /** @use HasFactory<ModuleCategoryFactory> */
    use HasFactory;

    use HasUuids, StampsAuditor;

    /** Read by the validator, by the column and by any message that quotes the number. */
    public const int MAXIMUM_NAME_LENGTH = 120;

    protected $fillable = [
        'name',
        'normalized_name',
    ];

    /** Folds a name for storage and lookup. Comparisons go against the folded column. */
    public static function normalize(string $name): string
    {
        return mb_strtoupper(trim($name));
    }

    /** Applies a name, keeping the folded form it is indexed by in step. */
    public function applyName(string $name): void
    {
        $this->name = trim($name);
        $this->normalized_name = self::normalize($name);
    }

    /** @return HasMany<Module, $this> */
    public function modules(): HasMany
    {
        return $this->hasMany(Module::class, 'category_id');
    }

    /**
     * Every category, keyed by identifier, for the module form's picker.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return self::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(static fn (self $category): array => [
                (string) $category->getKey() => $category->name,
            ])
            ->all();
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
