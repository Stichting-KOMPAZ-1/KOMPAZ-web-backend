<?php

declare(strict_types=1);

namespace App\Nova;

use App\Models\ModuleContact as ModuleContactModel;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Repeater;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Nova's handle on a repeatable row, and nothing more.
 *
 * A {@see Repeater} using the HasMany preset addresses its rows through a
 * resource, so one has to exist. It is never navigated to: these rows are edited inside the form
 * of the thing that owns them, which is the whole point of a repeater, and a second way in would
 * be a second place the rules could differ.
 */
/**
 * @extends \App\Nova\Resource<ModuleContactModel>
 */
class ModuleContact extends Resource
{
    use Concerns\ReachedOnlyThroughItsOwner;

    /** @var class-string<ModuleContactModel> */
    public static $model = ModuleContactModel::class;

    public static $title = 'name';

    public static $displayInNavigation = false;

    public static function label(): string
    {
        return 'Contactpersonen';
    }

    public static function singularLabel(): string
    {
        return 'Contactpersoon';
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
