<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Models\Chapter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Chapters saved while the description was written in the rich-text editor, and what is left of
 * them once it is plain text again.
 */
final class ChapterDescriptionPlainTextMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_editors_markup_becomes_the_text_it_showed(): void
    {
        $chapter = Chapter::factory()->create([
            'description' => '<div>In dit hoofdstuk lees je <strong>wat</strong> je gaat leren &amp; waarom.<br>Lees het rustig.</div><div><br></div><div>Succes.</div>',
        ]);

        $this->migrate();

        $this->assertSame(
            "In dit hoofdstuk lees je wat je gaat leren & waarom.\nLees het rustig.\n\nSucces.",
            $chapter->refresh()->description,
        );
    }

    #[Test]
    public function text_written_as_plain_text_is_left_as_it_was(): void
    {
        // Plain text may hold a `<` of its own, and stripping it would eat part of the sentence.
        $kept = Chapter::factory()->create(['description' => 'Dosering bij gewicht < 50 kg.']);
        $empty = Chapter::factory()->create(['description' => null]);

        $this->migrate();

        $this->assertSame('Dosering bij gewicht < 50 kg.', $kept->refresh()->description);
        $this->assertNull($empty->refresh()->description);
    }

    #[Test]
    public function a_description_with_no_text_in_its_markup_is_left_out(): void
    {
        $chapter = Chapter::factory()->create(['description' => '<div><br></div>']);

        $this->migrate();

        $this->assertNull($chapter->refresh()->description);
    }

    /** Run once more over rows written after the schema was: what a deploy does to existing ones. */
    private function migrate(): void
    {
        $migration = require database_path('migrations/2026_10_08_100000_convert_chapter_descriptions_to_plain_text.php');

        $this->assertInstanceOf(Migration::class, $migration);

        $this->app->call([$migration, 'up']);
    }
}
