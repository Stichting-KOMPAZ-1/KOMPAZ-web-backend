<?php

declare(strict_types=1);

namespace Tests\Feature\Nova;

use App\Nova\Actions\CreateOrganization;
use Laravel\Nova\Nova;
use Laravel\Nova\Style;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one stylesheet the panel adds to Nova's.
 *
 * It labels the standalone-action trigger on Organisaties, which Nova renders as three dots and
 * gives no way to name from PHP. The sentence therefore lives in two files, so this is what keeps
 * them the same one: rename the action and this fails rather than the button quietly going on
 * promising something else.
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

    #[Test]
    public function the_standalone_action_trigger_is_labelled_with_the_action_s_own_name(): void
    {
        $stylesheet = (string) file_get_contents(resource_path('assets/nova.css'));

        $this->assertStringContainsString(
            sprintf("content: '%s';", app(CreateOrganization::class)->name()),
            $stylesheet,
        );
    }
}
