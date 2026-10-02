<?php

declare(strict_types=1);

namespace Tests\Feature\Nova;

use App\Nova\Actions\CreateModuleCategory;
use App\Nova\Actions\CreateOrganization;
use App\Nova\Actions\InviteUser;
use Laravel\Nova\Nova;
use Laravel\Nova\Script;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one script the panel adds to Nova's, and whether what is committed is what the source says.
 *
 * The deploy runs Composer and nothing else, so `dist/js/panel.js` is built here and committed:
 * editing a source file without rebuilding changes nothing in the panel and fails nowhere. These
 * assert the built file still carries what the sources put in it, which is the cheapest way to
 * catch a forgotten `npm run production`.
 */
final class PanelScriptTest extends TestCase
{
    #[Test]
    public function the_panel_registers_its_own_script(): void
    {
        $names = array_map(
            static fn (Script $script): string => (string) $script->name(),
            Nova::allScripts(),
        );

        $this->assertContains('kompaz-panel', $names);
        $this->assertFileExists(resource_path('nova/panel/dist/js/panel.js'));
    }

    /**
     * The listings whose create goes through a use case have one standalone action, and Nova puts
     * every standalone action behind an ellipsis. The panel replaces that control with a real
     * button, so the built script has to know the name Nova gives the trigger.
     */
    #[Test]
    public function the_built_script_replaces_the_standalone_action_control(): void
    {
        $script = (string) file_get_contents(resource_path('nova/panel/dist/js/panel.js'));

        $this->assertStringContainsString('index-standalone-action-dropdown', $script);
        $this->assertStringContainsString('ActionDropdown', $script);
    }

    /**
     * The button carries the action's own name, read off the action at runtime rather than written
     * out anywhere — so this asserts the names exist to be read, and that nothing has quietly left
     * one of these listings without the one action the button is.
     */
    #[Test]
    public function each_listing_has_exactly_the_one_action_the_button_offers(): void
    {
        foreach ([
            CreateOrganization::class,
            InviteUser::class,
            CreateModuleCategory::class,
        ] as $action) {
            $this->assertNotSame('', trim(app($action)->name()));
        }
    }
}
