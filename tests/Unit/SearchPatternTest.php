<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Search\SearchPattern;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SearchPatternTest extends TestCase
{
    #[Test]
    public function it_folds_the_pattern_so_both_sides_match_folded(): void
    {
        $this->assertSame('%RENÉE%', SearchPattern::contains('renée'));
    }

    #[Test]
    public function it_neutralizes_wildcards_a_user_typed(): void
    {
        $this->assertSame('%100\%%', SearchPattern::contains('100%'));
        $this->assertSame('%A\_B%', SearchPattern::contains('a_b'));
    }

    #[Test]
    public function it_escapes_the_escape_character_first(): void
    {
        // Escaping in the other order would double the escapes added afterwards.
        $this->assertSame('%\\\\\%%', SearchPattern::contains('\\%'));
    }
}
