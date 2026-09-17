<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Support\Errors\ProblemDetailFactory;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The generated document is a contract clients are entitled to believe, and Scramble describes
 * Laravel's defaults unless something tells it otherwise. These assertions are the ones that
 * caught it describing a validation failure as 422 `{message, errors}` when this API has always
 * answered 400 problem details.
 */
final class ApiDocumentationTest extends TestCase
{
    /** @var array<mixed, mixed>|null */
    private static ?array $document = null;

    #[Test]
    public function a_validation_failure_is_documented_as_the_problem_this_api_returns(): void
    {
        $schema = $this->problemSchemaFor('/auth/tokens', 'post', 400);

        $this->assertSame(400, $schema['properties']['status']['const']);
        $this->assertSame(
            ProblemDetailFactory::VALIDATION_TITLE,
            $schema['properties']['title']['examples'][0],
        );
        $this->assertSame(
            ['type', 'title', 'status', 'instance', 'errors', 'traceId'],
            $schema['required'],
        );
        $this->assertArrayNotHasKey('message', $schema['properties']);
    }

    #[Test]
    public function no_operation_is_documented_as_answering_laravels_422(): void
    {
        foreach ($this->paths() as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $this->assertArrayNotHasKey(
                    '422',
                    $operation['responses'] ?? [],
                    "{$method} {$path} is documented as answering 422.",
                );
            }
        }
    }

    #[Test]
    public function every_documented_refusal_is_labelled_as_a_problem_detail(): void
    {
        foreach ($this->paths() as $path => $methods) {
            foreach ($methods as $method => $operation) {
                foreach ($operation['responses'] ?? [] as $status => $response) {
                    if ((int) $status < 400) {
                        continue;
                    }

                    $this->assertArrayHasKey(
                        'application/problem+json',
                        $this->resolve($response)['content'] ?? [],
                        "{$method} {$path} documents {$status} with something other than problem details.",
                    );
                }
            }
        }
    }

    /**
     * The response schema a refusal is documented with, with its `$ref` followed.
     *
     * @return array<string, mixed>
     */
    private function problemSchemaFor(string $path, string $method, int $status): array
    {
        $paths = $this->paths();

        $this->assertArrayHasKey($path, $paths, "The document has no {$path}.");
        $this->assertArrayHasKey((string) $status, $paths[$path][$method]['responses']);

        $response = $this->resolve($paths[$path][$method]['responses'][(string) $status]);

        /** @var array<string, mixed> $schema */
        $schema = $response['content']['application/problem+json']['schema'];

        return $schema;
    }

    /**
     * Scramble shares one response component between every operation that can answer with it, so
     * an assertion about an operation is an assertion about a `$ref` until it is followed.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function resolve(array $response): array
    {
        if (! isset($response['$ref'])) {
            return $response;
        }

        $name = basename((string) $response['$ref']);

        /** @var array<string, mixed> $resolved */
        $resolved = $this->document()['components']['responses'][$name];

        return $resolved;
    }

    /** @return array<string, array<string, array<string, mixed>>> */
    private function paths(): array
    {
        /** @var array<string, array<string, array<string, mixed>>> $paths */
        $paths = $this->document()['paths'];

        return $paths;
    }

    /**
     * Generating the document analyses the whole application, so it is done once for the class
     * rather than once per assertion.
     *
     * @return array<mixed, mixed>
     */
    private function document(): array
    {
        return self::$document ??= app(Generator::class)
            ->generate(Scramble::getGeneratorConfig('default'))
            ->spec();
    }
}
