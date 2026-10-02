<?php

declare(strict_types=1);

namespace Tests\Feature\Nova;

use App\Nova\Actions\CreateModuleCategory;
use App\Nova\Actions\CreateOrganization;
use App\Nova\Actions\InviteUser;
use Laravel\Nova\Nova;
use Laravel\Nova\Style;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one stylesheet the panel adds to Nova's.
 *
 * It labels the standalone-action trigger on the three listings whose create goes through a use
 * case rather than through Nova's own form, which Nova renders as three dots and gives no way to
 * name from PHP. Each sentence therefore lives in two files, so this is what keeps them the same
 * one: rename an action and this fails rather than the button quietly going on promising something
 * else.
 */
final class PanelStylesheetTest extends TestCase
{
    #[Test]
    public function the_panel_registers_its_own_stylesheet(): void
    {
        $names = array_map(
            static fn (Style $style): string => (string) $style->name(),
            Nova::allStyles(),
        );

        $this->assertContains('kompaz', $names);
        $this->assertFileExists(resource_path('assets/nova.css'));
    }

    /**
     * Every listing whose create is a standalone action, not just the first one to get a label:
     * leaving the others as three dots is what the operator reported, and a test naming only
     * Organisaties is what let the other two stay that way.
     */
    #[Test]
    public function every_standalone_action_trigger_is_labelled_with_the_action_s_own_name(): void
    {
        $stylesheet = (string) file_get_contents(resource_path('assets/nova.css'));

        foreach ([
            'organizations' => app(CreateOrganization::class)->name(),
            'users' => app(InviteUser::class)->name(),
            'module-categories' => app(CreateModuleCategory::class)->name(),
        ] as $resource => $label) {
            $this->assertStringContainsString(sprintf("content: '%s';", $label), $stylesheet);

            // The label belongs to that listing's trigger and no other: the rule is scoped by the
            // resource's own dusk attribute, which is how Modules keeps Nova's real create button.
            $this->assertStringContainsString(
                sprintf("[dusk='%s-index-component'] [dusk='index-standalone-action-dropdown']", $resource),
                $stylesheet,
            );
        }
    }
}
