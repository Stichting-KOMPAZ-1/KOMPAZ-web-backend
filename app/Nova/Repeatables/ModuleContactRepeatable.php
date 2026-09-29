<?php

declare(strict_types=1);

namespace App\Nova\Repeatables;

use App\Models\ModuleContact;
use App\Support\Modules\ContentRules;
use Laravel\Nova\Fields\Email;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Repeater\Repeatable;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * One contact card on an organization's copy of a module.
 *
 * Only the name is required. An organization that publishes a shared inbox and no direct line is
 * giving a real answer, and refusing it would teach an operator to type a placeholder — which is
 * worse than a blank, because a blank is honest.
 */
class ModuleContactRepeatable extends Repeatable
{
    /** @var class-string<ModuleContact> */
    public static $model = ModuleContact::class;

    public static function label(): string
    {
        return 'Contactpersoon';
    }

    /** @return array<int, Field> */
    public function fields(NovaRequest $request): array
    {
        return [
            Text::make('Naam', 'name')
                ->rules(ContentRules::contactName()),

            Email::make('E-mailadres', 'email')
                ->rules(ContentRules::contactEmail()),

            Text::make('Telefoonnummer', 'phone')
                ->rules(ContentRules::contactPhone()),

            Textarea::make('Reden voor contact', 'reason')
                ->rules(ContentRules::contactNote()),

            Textarea::make('Beschikbaarheid', 'availability')
                ->rules(ContentRules::contactNote()),
        ];
    }
}
