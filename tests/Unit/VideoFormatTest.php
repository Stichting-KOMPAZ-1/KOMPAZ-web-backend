<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Videos\VideoFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A video's format is read out of its first bytes, and only the three containers every reader's
 * browser plays count as one. A Matroska file that is not WebM is a video to a desktop player and
 * a blank rectangle in Safari, so it is refused like anything else.
 */
final class VideoFormatTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string}> */
    public static function heads(): array
    {
        return [
            'mp4' => ["\x00\x00\x00\x18".'ftyp'.'mp42'.'the rest', VideoFormat::MP4],
            'mp4 with an isom brand' => ["\x00\x00\x00\x20".'ftyp'.'isom'.'the rest', VideoFormat::MP4],
            'quicktime, as a phone records it' => ["\x00\x00\x00\x14".'ftyp'.'qt  '.'the rest', VideoFormat::QUICKTIME],
            'webm' => ["\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\x82\x84".'webm'.'the rest', VideoFormat::WEBM],

            'matroska that is not webm' => ["\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\x82\x88".'matroska', null],
            'a box that is not ftyp' => ["\x00\x00\x00\x18".'moov'.'mp42'.'the rest', null],
            'too short to have a brand' => ["\x00\x00\x00\x18".'ftyp', null],
            'a png' => ["\x89PNG\r\n\x1a\n".'data', null],
            'plain text' => ['not a video at all', null],
            'empty' => ['', null],
        ];
    }

    #[Test]
    #[DataProvider('heads')]
    public function the_format_is_read_out_of_the_first_bytes(string $head, ?string $expected): void
    {
        $this->assertSame($expected, VideoFormat::detect($head));
    }
}
