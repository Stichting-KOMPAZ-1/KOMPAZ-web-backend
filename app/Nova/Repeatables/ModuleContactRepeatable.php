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
 * The name, the job role and the e-mail address are required, as KOM-61 asks; the phone number and
 * the two notes are what that organization happens to publish.
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

            Text::make('Functie', 'job_role')
                ->rules(ContentRules::contactJobRole()),

            Email::make('E-mailadres', 'email')
                ->rules(ContentRules::contactEmail()),

            Text::make('Telefoonnummer', 'phone')
                ->rules(ContentRules::contactPhone()),

            // Plain text, as a chapter's description is: a textarea keeps the line breaks typed.
            Textarea::make('Reden voor contact', 'reason')
                ->alwaysShow()
                ->rules(ContentRules::contactNote()),

            Textarea::make('Beschikbaarheid', 'availability')
                ->alwaysShow()
                ->rules(ContentRules::contactNote()),
        ];
    }
}
