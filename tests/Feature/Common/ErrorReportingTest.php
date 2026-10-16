<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Exceptions\AuthenticationFailedException;
use App\Exceptions\ConflictException;
use App\Exceptions\Contracts\ProvidesProblemDetail;
use App\Exceptions\ForbiddenAccessException;
use App\Exceptions\NotFoundException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * What reaches Flare: failures, and not the answers the application gives on purpose.
 */
final class ErrorReportingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_applications_own_refusals_are_not_reported(): void
    {
        foreach ([
            new NotFoundException('Deze e-learning bestaat niet of is niet beschikbaar.'),
            new ForbiddenAccessException('Geen toegang.'),
            new ConflictException('Bestaat al.'),
            new AuthenticationFailedException('Niet ingelogd.'),
        ] as $refusal) {
            $this->assertFalse($this->handler()->shouldReport($refusal), $refusal::class.' was reported.');
        }
    }

    #[Test]
    public function a_failure_is_still_reported_even_when_it_is_one_of_the_applications_own(): void
    {
        $this->assertTrue($this->handler()->shouldReport(new RuntimeException('Iets ging mis.')));

        $serverSide = new class('Opslag niet bereikbaar.') extends RuntimeException implements ProvidesProblemDetail
        {
            public function problemStatus(): int
            {
                return 503;
            }
        };

        $this->assertTrue($this->handler()->shouldReport($serverSide));
    }

    #[Test]
    public function a_mistyped_shared_link_answers_404_and_reports_nothing(): void
    {
        // The request in the Flare report that started this.
        Exceptions::fake();

        $this->getJson('/api/shared/kompa/prikken-onder-de-huid')->assertNotFound();

        Exceptions::assertNothingReported();
    }

    private function handler(): ExceptionHandler
    {
        return $this->app->make(ExceptionHandler::class);
    }
}
