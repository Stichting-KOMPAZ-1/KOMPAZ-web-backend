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
     * The five listings offer one control, so the three drawn here have to keep matching the two
     * Nova draws itself. Nothing in CSS can read a Tailwind class, so the values in the stylesheet
     * are copies — and this is what fails when the thing they were copied from moves. It asserts
     * against Nova's own component rather than against a remembered list, so a Nova upgrade that
     * restyles the button is caught by the upgrade rather than by somebody noticing two shades of
     * blue.
     */
    #[Test]
    public function the_label_matches_the_button_nova_draws_on_the_other_listings(): void
    {
        $button = (string) file_get_contents(
            base_path('vendor/laravel/nova/resources/js/components/Buttons/InertiaButton.vue'),
        );

        foreach ([
            'rounded' => 'border-radius: 0.25rem;',
            'bg-primary-500' => 'background-color: rgba(var(--colors-primary-500), 1);',
            'hover:bg-primary-400' => 'background-color: rgba(var(--colors-primary-400), 1);',
            'active:bg-primary-600' => 'background-color: rgba(var(--colors-primary-600), 1);',
            'font-bold' => 'font-weight: 700;',
            'px-4 h-9 text-sm' => 'height: 2.25rem;',
        ] as $novaClass => $ourDeclaration) {
            $this->assertStringContainsString(
                $novaClass,
                $button,
                sprintf('Nova no longer styles its create button with "%s"; resources/assets/nova.css copied it.', $novaClass),
            );

            $this->assertStringContainsString(
                $ourDeclaration,
                (string) file_get_contents(resource_path('assets/nova.css')),
            );
        }
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
