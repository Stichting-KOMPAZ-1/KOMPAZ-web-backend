<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Videos\VideoEmbed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A video link becomes an embed built from its id, never an iframe pointed at whatever was typed.
 * The cases that matter most are the lookalikes at the bottom: a host that merely contains
 * "youtube", or an id with something appended, is a plain link and nothing more.
 */
final class VideoEmbedTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string, 2: ?string}> */
    public static function links(): array
    {
        $embed = 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ';

        return [
            'youtube watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', VideoEmbed::YOUTUBE, $embed],
            'youtube watch with a timestamp' => ['https://youtube.com/watch?v=dQw4w9WgXcQ&t=42s', VideoEmbed::YOUTUBE, $embed],
            'youtube mobile' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', VideoEmbed::YOUTUBE, $embed],
            'youtube short link' => ['https://youtu.be/dQw4w9WgXcQ?si=abc', VideoEmbed::YOUTUBE, $embed],
            'youtube embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', VideoEmbed::YOUTUBE, $embed],
            'youtube shorts' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', VideoEmbed::YOUTUBE, $embed],
            'vimeo' => ['https://vimeo.com/76979871', VideoEmbed::VIMEO, 'https://player.vimeo.com/video/76979871'],
            'vimeo unlisted' => ['https://vimeo.com/76979871/8272103f6e', VideoEmbed::VIMEO, 'https://player.vimeo.com/video/76979871?h=8272103f6e'],
            'vimeo player' => ['https://player.vimeo.com/video/76979871?h=8272103f6e', VideoEmbed::VIMEO, 'https://player.vimeo.com/video/76979871?h=8272103f6e'],
            'a video file' => ['https://cdn.example.nl/instructie.MP4', VideoEmbed::FILE, 'https://cdn.example.nl/instructie.MP4'],
            'any other page' => ['https://www.example.nl/protocol', VideoEmbed::LINK, 'https://www.example.nl/protocol'],

            'a host that only contains youtube' => ['https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ', VideoEmbed::LINK, 'https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ'],
            'an id with something appended' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ"onload', VideoEmbed::LINK, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ"onload'],
            'a vimeo page that is not a video' => ['https://vimeo.com/channels/staffpicks', VideoEmbed::LINK, 'https://vimeo.com/channels/staffpicks'],
            'a video file over plain http' => ['http://cdn.example.nl/instructie.mp4', VideoEmbed::LINK, 'http://cdn.example.nl/instructie.mp4'],
            'not a web address' => ['javascript:alert(1)', null, null],
            'no host' => ['https:///watch', null, null],
        ];
    }

    #[Test]
    #[DataProvider('links')]
    public function a_link_is_shown_as_what_it_turns_out_to_be(string $url, ?string $kind, ?string $src): void
    {
        $embed = VideoEmbed::fromUrl($url);

        $this->assertSame($kind, $embed?->kind);
        $this->assertSame($src, $embed?->src);
    }
}
