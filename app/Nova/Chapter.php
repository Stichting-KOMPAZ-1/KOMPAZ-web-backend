<?php

declare(strict_types=1);

namespace App\Nova;

use App\Models\Chapter as ChapterModel;
use App\Models\User;
use App\Nova\Breadcrumbs\NestedResource;
use App\Support\Access\OrganizationAccess;
use App\Support\Modules\ContentRules;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Outl1ne\NovaSortable\Traits\HasSortableRows;

/**
 * A chapter of a course, reached from the course it belongs to.
 *
 * Not in the menu: a chapter on its own means nothing, so the way in is the course's page, whose
 * "Hoofdstukken" table creates one already attached. A new chapter goes at the end — see
 * {@see ChapterModel::booted()}.
 *
 * Nova's own delete is on. Rule 27 turned it off for modules and courses because the product wrote
 * the sentence an operator confirms; it wrote none for a chapter, and the delete goes through the
 * model, which collects the files of every block underneath before the cascade removes them.
 */
/**
 * @extends \App\Nova\Resource<ChapterModel>
 */
class Chapter extends Resource implements NestedResource
{
    use Concerns\AuthoredByThePlatform;
    use HasSortableRows;

    /**
     * The package caches whether a resource can be sorted once per process, which would carry one
     * operator's answer to the next.
     */
    public static bool $sortableCacheEnabled = false;

    /** @var class-string<ChapterModel> */
    public static $model = ChapterModel::class;

    public static $title = 'name';

    /** @var array<int, string> */
    public static $search = ['name'];

    public static $displayInNavigation = false;

    public static function label(): string
    {
        return 'Hoofdstukken';
    }

    public static function singularLabel(): string
    {
        return 'Hoofdstuk';
    }

    /**
     * The course this chapter is part of, for the breadcrumbs.
     *
     * @return \App\Nova\Resource<covariant \Illuminate\Database\Eloquent\Model>|null
     */
    public function parentResource(): ?Resource
    {
        return new ELearning($this->model()->eLearning);
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->onlyOnDetail(),

            // On the form because that is how Nova attaches a chapter created from the course's
            // page; it arrives filled in and locked there.
            BelongsTo::make('E-learning', 'eLearning', ELearning::class)
                ->hideFromIndex(),

            Text::make('Naam', 'name')
                ->rules(ContentRules::chapterName()),

            Textarea::make('Omschrijving', 'description')
                ->alwaysShow()
                ->nullable()
                ->rules(ContentRules::chapterDescription()),

            Number::make('Stappen', fn (): int => (int) ($this->steps_count ?? 0))
                ->exceptOnForms(),

            // A toggle to write it and a word to read it: the wireframe's table says "Ja" and "Nee",
            // where Nova's own Boolean would draw an icon.
            Boolean::make('Samenvatting', 'is_summary')
                ->onlyOnForms(),

            Text::make('Samenvatting?', fn (): string => $this->model()->is_summary ? 'Ja' : 'Nee')
                ->exceptOnForms(),

            HasMany::make('Stappen', 'steps', Step::class),
        ];
    }

    /** In the order the course reads them, which is the order an operator arranged them in. */
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
            ->withCount('steps')
            ->orderBy('position')
            ->orderBy('id');
    }

    public static function detailQuery(NovaRequest $request, Builder $query): Builder
    {
        return $query->withCount('steps');
    }
}
