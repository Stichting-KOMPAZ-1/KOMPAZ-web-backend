<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Errors\ProblemDetailFactory;
use App\Support\Errors\ProblemType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * What a caller reads when the CSRF token on a cookie-authenticated write did not match.
 *
 * Laravel turns a TokenMismatchException into a 419 carrying "CSRF token mismatch." before any of
 * this application's code sees it, so the status is what there is to recognise it by — and the
 * English sentence it arrives with is not one a caller should ever read.
 */
final class CsrfProblemDetailTest extends TestCase
{
    #[Test]
    public function a_csrf_failure_answers_in_dutch(): void
    {
        $problem = ProblemDetailFactory::make(new HttpException(419, 'CSRF token mismatch.'));

        $this->assertSame(419, $problem->status);
        $this->assertSame(ProblemDetailFactory::CSRF_DETAIL, $problem->detail);
        $this->assertStringNotContainsString('CSRF', (string) $problem->detail);
    }

    /** 419 is Laravel's, not the specification's, and is named rather than left as "Error". */
    #[Test]
    public function the_status_is_named(): void
    {
        $this->assertSame('Page Expired', ProblemType::titleForStatus(419));
        $this->assertNotSame(ProblemType::forStatus(419), ProblemType::forStatus(418));
    }
}
