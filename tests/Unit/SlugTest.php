<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Links\Slug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function names(): array
    {
        return [
            'words' => ['Medicijnen prikken onder de huid', 'medicijnen-prikken-onder-de-huid'],
            'accents' => ['Zorg Café Één', 'zorg-cafe-een'],
            'punctuation' => ['Maastricht UMC+ (locatie Noord)', 'maastricht-umc-locatie-noord'],
            'nothing usable' => ['🙂🙂', 'organisatie'],
        ];
    }

    #[Test]
    #[DataProvider('names')]
    public function a_name_becomes_a_readable_slug(string $name, string $slug): void
    {
        $this->assertSame($slug, Slug::make($name, static fn (): bool => false, 'organisatie'));
    }

    #[Test]
    public function a_taken_slug_is_numbered_until_one_is_free(): void
    {
        $taken = ['steunkousen', 'steunkousen-2'];

        $this->assertSame(
            'steunkousen-3',
            Slug::make('Steunkousen', static fn (string $slug): bool => in_array($slug, $taken, true), 'e-learning'),
        );
    }

    #[Test]
    public function a_path_the_frontends_domain_already_answers_is_never_a_slug(): void
    {
        // An organization called "Beheer" would otherwise own the panel's address, and a course
        // called "QR" would be indistinguishable from the QR code's own segment.
        $this->assertSame('beheer-2', Slug::make('Beheer', static fn (): bool => false, 'organisatie'));
        $this->assertSame('qr-2', Slug::make('QR', static fn (): bool => false, 'e-learning'));
        $this->assertSame('api-2', Slug::make('API', static fn (): bool => false, 'organisatie'));
    }

    #[Test]
    public function a_long_name_is_cut_short_with_room_for_a_number(): void
    {
        $slug = Slug::make(str_repeat('verpleegkunde ', 20), static fn (string $slug): bool => ! str_ends_with($slug, '-2'), 'e-learning');

        $this->assertLessThanOrEqual(Slug::MAXIMUM_LENGTH, strlen($slug));
        $this->assertStringEndsWith('-2', $slug);
        $this->assertStringNotContainsString('--', $slug);
    }
}
