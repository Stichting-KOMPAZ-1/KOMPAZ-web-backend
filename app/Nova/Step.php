<?php

declare(strict_types=1);

namespace App\Nova;

use App\Models\Step as StepModel;
use App\Models\User;
use App\Nova\Breadcrumbs\NestedResource;
use App\Support\Access\OrganizationAccess;
use App\Support\Modules\ContentRules;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Repeater;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Outl1ne\NovaSortable\Traits\HasSortableRows;

/**
 * One screen of a chapter, and the blocks that are on it.
 *
 * Reached from the chapter's page, like a chapter is from its course. The blocks are a repeater
 * with one row type per kind of block, which is the wireframe's "Type blok": each kind has its own
 * fields, and the arrows put them in the order the screen draws them. Saving is
 * {@see Repeatables\ContentBlockPreset}'s, because Nova's own would lose the kind of every block and
 * leave a removed picture's bytes on the disk.
 */
/**
 * @extends \App\Nova\Resource<StepModel>
 */
class Step extends Resource implements NestedResource
{
    use Concerns\AuthoredByThePlatform;
    use HasSortableRows;

    /**
     * The package caches whether a resource can be sorted once per process, which would carry one
     * operator's answer to the next.
     */
    public static bool $sortableCacheEnabled = false;

    /** @var class-string<StepModel> */
    public static $model = StepModel::class;

    public static $title = 'name';

    /** @var array<int, string> */
    public static $search = ['name'];

    public static $displayInNavigation = false;

    public static function label(): string
    {
        return 'Stappen';
    }

    public static function singularLabel(): string
    {
        return 'Stap';
    }

    /**
     * The chapter this step is part of, for the breadcrumbs.
     *
     * @return \App\Nova\Resource<covariant \Illuminate\Database\Eloquent\Model>|null
     */
    public function parentResource(): ?Resource
    {
        return new Chapter($this->model()->chapter);
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->onlyOnDetail(),

            BelongsTo::make('Hoofdstuk', 'chapter', Chapter::class)
                ->hideFromIndex(),

            Text::make('Naam', 'name')
                ->rules(ContentRules::stepName()),

            // A step is its blocks, so one without any is refused here rather than saved empty.
            Repeater::make('Inhoud', 'blocks')
                ->repeatables([
                    Repeatables\TextBlockRepeatable::make(),
                    Repeatables\ImageBlockRepeatable::make(),
                    Repeatables\VideoBlockRepeatable::make(),
                ])
                ->preset(new Repeatables\ContentBlockPreset)
                ->rules(ContentRules::blockList())
                // Nova's repeater is form-only unless told otherwise, which left a step's own page
                // with a name and nothing else. On detail each block is a card of its own fields.
                ->showOnDetail()
                ->hideFromIndex(),
        ];
    }

    /** In the order the chapter reads them. */
    /**
     * Whether the drag handles are drawn. Only for the platform, who writes courses; the
     * addresses the handles post to ask the same question again.
     *
     * @param  mixed  $resource
     */
    public static function canSort(NovaRequest $request, $resource): bool
    {
        $operator = $request->user();

        return $operator instanceof User && OrganizationAccess::isPlatformAdministrator($operator);
    }

    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        return $query
            ->orderBy('position')
            ->orderBy('id');
    }
}
