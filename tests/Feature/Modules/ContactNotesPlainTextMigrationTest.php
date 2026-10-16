<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Models\ModuleContact;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Contact cards saved while their two notes were written in the rich-text editor, and what is left
 * of them once they are plain text again.
 */
final class ContactNotesPlainTextMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_editors_markup_becomes_the_text_it_showed(): void
    {
        $contact = ModuleContact::factory()->create([
            'reason' => '<div>Vragen over <strong>dosering</strong> &amp; bijwerkingen.<br>Bel gerust.</div>',
            'availability' => '<div>Ma t/m vr</div><div><br></div><div>Niet op feestdagen.</div>',
        ]);

        $this->migrate();

        $contact->refresh();

        $this->assertSame("Vragen over dosering & bijwerkingen.\nBel gerust.", $contact->reason);
        $this->assertSame("Ma t/m vr\n\nNiet op feestdagen.", $contact->availability);
    }

    #[Test]
    public function text_written_as_plain_text_is_left_as_it_was(): void
    {
        // Plain text may hold a `<` of its own, and stripping it would eat part of the sentence.
        $contact = ModuleContact::factory()->create([
            'reason' => 'Bij gewicht < 50 kg.',
            'availability' => null,
        ]);

        $this->migrate();

        $contact->refresh();

        $this->assertSame('Bij gewicht < 50 kg.', $contact->reason);
        $this->assertNull($contact->availability);
    }

    #[Test]
    public function a_note_with_no_text_in_its_markup_is_left_out(): void
    {
        $contact = ModuleContact::factory()->create([
            'reason' => '<div><br></div>',
            'availability' => 'Altijd',
        ]);

        $this->migrate();

        $contact->refresh();

        $this->assertNull($contact->reason);
        $this->assertSame('Altijd', $contact->availability);
    }

    /** Run once more over rows written after the schema was: what a deploy does to existing ones. */
    private function migrate(): void
    {
        $migration = require database_path('migrations/2026_10_12_100000_convert_contact_notes_to_plain_text.php');

        $this->assertInstanceOf(Migration::class, $migration);

        $this->app->call([$migration, 'up']);
    }
}
