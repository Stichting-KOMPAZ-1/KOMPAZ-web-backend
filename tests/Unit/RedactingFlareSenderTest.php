<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Errors\RedactingFlareSender;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedactingFlareSenderTest extends TestCase
{
    #[Test]
    public function it_censors_the_secret_in_every_address_a_report_records(): void
    {
        $redacted = RedactingFlareSender::redact([
            'attributes' => [
                'url.full' => 'https://kompaz.test/beheer/sessie?token=s3cr3t&next=1',
                'url.query' => 'token=s3cr3t',
                'entry_point.value' => 'https://kompaz.test/inloggen?TOKEN=s3cr3t#top',
            ],
            'spans' => [['attributes' => ['url.full' => 'https://kompaz.test/beheer/sessie?a=1&token=s3cr3t']]],
        ]);

        $this->assertSame([
            'attributes' => [
                'url.full' => 'https://kompaz.test/beheer/sessie?token=[CENSORED]&next=1',
                'url.query' => 'token=[CENSORED]',
                'entry_point.value' => 'https://kompaz.test/inloggen?TOKEN=[CENSORED]#top',
            ],
            'spans' => [['attributes' => ['url.full' => 'https://kompaz.test/beheer/sessie?a=1&token=[CENSORED]']]],
        ], $redacted);
    }

    #[Test]
    public function it_leaves_parameters_that_only_end_in_the_name_alone(): void
    {
        $payload = ['url.full' => 'https://kompaz.test/x?csrf_token=abc&page=2', 'status' => 500];

        $this->assertSame($payload, RedactingFlareSender::redact($payload));
    }
}
