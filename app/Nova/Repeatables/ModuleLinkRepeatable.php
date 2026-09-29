<?php

declare(strict_types=1);

namespace App\Nova\Repeatables;

use App\Models\ModuleLink;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Repeater\Repeatable;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\URL;
use Laravel\Nova\Http\Requests\NovaRequest;

/** One row of the "Extra links" list: a title and a link, which is all a link ever is. */
class ModuleLinkRepeatable extends Repeatable
{
    /** @var class-string<ModuleLink> */
    public static $model = ModuleLink::class;

    public static function label(): string
    {
        return 'Link';
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [
            Text::make('Titel', 'title')
                ->rules(['required', 'string', 'max:'.ModuleLink::MAXIMUM_TITLE_LENGTH]),

            URL::make('URL', 'url')
                ->rules(['required', 'url', 'max:2048']),
        ];
    }
}
