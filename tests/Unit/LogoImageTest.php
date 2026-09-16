<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Images\LogoImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The format is read out of the bytes, and an SVG is only an SVG when its *root* element is one.
 * An HTML page containing an inline chart contains `<svg>` too, and accepting it would let the API
 * store a document and serve it back from its own origin as an image.
 */
final class LogoImageTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string}> */
    public static function payloads(): array
    {
        return [
            'png' => ["\x89PNG\r\n\x1a\n".'data', LogoImage::PNG],
            'jpeg' => ["\xFF\xD8\xFF".'data', LogoImage::JPEG],
            'webp' => ['RIFF'.'size'.'WEBP'.'data', LogoImage::WEBP],
            'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"></svg>', LogoImage::SVG],
            'svg with leading whitespace' => ["\n  <svg></svg>", LogoImage::SVG],
            'svg behind an xml declaration' => ['<?xml version="1.0"?><svg></svg>', LogoImage::SVG],
            'svg behind a doctype' => ['<!DOCTYPE svg><svg></svg>', LogoImage::SVG],
            'svg behind a comment' => ['<!-- a note --><svg></svg>', LogoImage::SVG],
            'svg behind a byte order mark' => ["\u{FEFF}<svg></svg>", LogoImage::SVG],
            'uppercase svg element' => ['<SVG></SVG>', LogoImage::SVG],

            'html containing an svg' => ['<html><body><svg></svg></body></html>', null],
            'html with a leading comment' => ['<!-- x --><html><svg></svg></html>', null],
            'plain text' => ['not an image at all', null],
            'empty' => ['', null],
            'unterminated comment' => ['<!-- never closed <svg></svg>', null],
            'gif is not accepted' => ['GIF89a'.'data', null],
            'riff that is not webp' => ['RIFF'.'size'.'WAVE'.'data', null],
            'invalid utf8' => ["\xC3\x28<svg></svg>", null],
        ];
    }

    #[Test]
    #[DataProvider('payloads')]
    public function it_recognizes_only_what_it_should(string $content, ?string $expected): void
    {
        $this->assertSame($expected, LogoImage::detectContentType($content));
    }

    #[Test]
    public function it_names_the_file_after_the_format_it_recognized(): void
    {
        $this->assertSame('png', LogoImage::extensionFor(LogoImage::PNG));
        $this->assertSame('jpg', LogoImage::extensionFor(LogoImage::JPEG));
        $this->assertSame('svg', LogoImage::extensionFor(LogoImage::SVG));
        $this->assertSame('webp', LogoImage::extensionFor(LogoImage::WEBP));
        $this->assertSame('bin', LogoImage::extensionFor('application/x-anything'));
    }
}
