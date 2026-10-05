<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Support\Links\AcceptableWebAddress;
use App\Support\Links\WebAddress;
use App\Support\Modules\ModuleMessages;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WebAddressTest extends TestCase
{
    /** @return array<string, array{string, string|null}> */
    public static function addresses(): array
    {
        return [
            'no scheme' => ['www.voorbeeld.nl', 'https://www.voorbeeld.nl'],
            'surrounding space' => ['  www.voorbeeld.nl/pad  ', 'https://www.voorbeeld.nl/pad'],
            'https kept' => ['https://www.voorbeeld.nl', 'https://www.voorbeeld.nl'],
            'http kept' => ['http://voorbeeld.nl', 'http://voorbeeld.nl'],
            'scheme in capitals kept' => ['HTTPS://voorbeeld.nl', 'HTTPS://voorbeeld.nl'],
            'nothing' => ['   ', null],
        ];
    }

    #[Test]
    #[DataProvider('addresses')]
    public function an_address_is_completed_the_way_a_browser_would(string $typed, ?string $stored): void
    {
        $this->assertSame($stored, WebAddress::complete($typed));
    }

    /** @return array<string, array{string}> */
    public static function acceptable(): array
    {
        return [
            'no scheme' => ['www.voorbeeld.nl'],
            'with a path and a query' => ['voorbeeld.nl/protocol?versie=2'],
            'https' => ['https://www.youtube.com/watch?v=abc'],
        ];
    }

    #[Test]
    #[DataProvider('acceptable')]
    public function a_web_address_is_accepted(string $address): void
    {
        $this->assertSame([], $this->refusals($address));
    }

    /** @return array<string, array{string}> */
    public static function unacceptable(): array
    {
        return [
            'one word' => ['voorbeeld'],
            'a script' => ['javascript:alert(1)'],
            'an e-mail address' => ['mailto:iemand@voorbeeld.nl'],
            'another scheme' => ['ftp://voorbeeld.nl'],
            'spaces inside' => ['www.voor beeld.nl'],
        ];
    }

    #[Test]
    #[DataProvider('unacceptable')]
    public function an_address_that_leads_nowhere_is_refused_in_the_products_words(string $address): void
    {
        $this->assertSame([ModuleMessages::INVALID_WEB_ADDRESS], $this->refusals($address));
    }

    #[Test]
    public function an_address_too_long_for_the_column_is_refused(): void
    {
        $this->assertSame(
            [ModuleMessages::webAddressTooLong()],
            $this->refusals('www.voorbeeld.nl/'.str_repeat('a', WebAddress::MAXIMUM_LENGTH)),
        );
    }

    /** @return list<string> */
    private function refusals(string $address): array
    {
        return array_values(
            Validator::make(['url' => $address], ['url' => [new AcceptableWebAddress]])->errors()->get('url'),
        );
    }
}
