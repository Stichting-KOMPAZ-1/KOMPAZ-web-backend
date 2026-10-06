<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Models\Module;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Modules saved while the description and source attribution were written in the rich-text
 * editor, and what is left of them once the two are plain text again.
 */
final class ModuleProsePlainTextMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_editors_markup_becomes_the_text_it_showed(): void
    {
        $module = Module::factory()->create([
            'description' => '<div>Prik <strong>langzaam</strong> &amp; wacht.<br>Daarna loslaten.</div><div><br></div><div>Klaar.</div>',
            'source_attribution' => '<div>Richtlijn 2026</div>',
        ]);

        $this->migrate();

        $module->refresh();

        $this->assertSame("Prik langzaam & wacht.\nDaarna loslaten.\n\nKlaar.", $module->description);
        $this->assertSame('Richtlijn 2026', $module->source_attribution);
    }

    #[Test]
    public function text_written_before_the_editor_is_left_as_it_was(): void
    {
        // Plain text may hold a `<` of its own, and stripping it would eat part of the sentence.
        $module = Module::factory()->create([
            'description' => 'Dosering bij gewicht < 50 kg.',
            'source_attribution' => null,
        ]);

        $this->migrate();

        $module->refresh();

        $this->assertSame('Dosering bij gewicht < 50 kg.', $module->description);
        $this->assertNull($module->source_attribution);
    }

    #[Test]
    public function a_source_with_no_text_in_its_markup_is_left_out(): void
    {
        $module = Module::factory()->create(['source_attribution' => '<div><br></div>']);

        $this->migrate();

        $this->assertNull($module->refresh()->source_attribution);
    }

    /** Run once more over rows written after the schema was: what a deploy does to existing ones. */
    private function migrate(): void
    {
        $migration = require database_path('migrations/2026_10_06_100000_convert_module_prose_to_plain_text.php');

        $this->assertInstanceOf(Migration::class, $migration);

        $this->app->call([$migration, 'up']);
    }
}
