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
     * The envelope used to name its resource by class string, which Scramble cannot follow: every
     * listing documented `items` as a string, and its counters with it.
     */
    #[Test]
    public function every_listing_documents_its_rows_and_its_counters(): void
    {
        $listings = [
            '/users' => 'UserResource',
            '/organizations' => 'OrganizationResource',
            '/modules' => 'ModuleSummaryResource',
        ];

        foreach ($listings as $path => $resource) {
            $properties = $this->paths()[$path]['get']['responses']['200']['content']['application/json']['schema']['properties'];

            $this->assertSame(
                ['type' => 'array', 'items' => ['$ref' => "#/components/schemas/{$resource}"]],
                $properties['items'],
                "{$path} does not document its rows as {$resource}.",
            );

            foreach (['pageNumber', 'pageSize', 'totalCount', 'totalPages'] as $counter) {
                $this->assertSame('integer', $properties[$counter]['type'], "{$path} documents {$counter} as something other than an integer.");
            }
        }
    }

    /**
     * A file's media type is read from its bytes, so no literal header names it, and Scramble
     * documented every image as a JSON string that a generated client would try to parse.
     */
    #[Test]
    public function a_file_is_documented_as_bytes_rather_than_as_json(): void
    {
        $files = [
            '/organizations/{organization}/logo',
            '/modules/{module}/image',
            '/e-learnings/{eLearning}/image',
            '/e-learnings/{eLearning}/steps/{step}/blocks/{block}/file',
        ];

        foreach ($files as $path) {
            $content = $this->paths()[$path]['get']['responses']['200']['content'];

            $this->assertArrayNotHasKey('application/json', $content, "{$path} is documented as JSON.");

            foreach ($content as $mediaType => $body) {
                $this->assertSame(['type' => 'string', 'format' => 'binary'], $body['schema'], "{$path} documents {$mediaType} as something other than bytes.");
            }
        }
    }

    /**
     * An uploaded video is a redirect to a signed link, not bytes — and Scramble, knowing nothing
     * about the object that makes it, documented it as JSON a client would try to parse.
     */
    #[Test]
    public function a_video_is_documented_as_a_redirect(): void
    {
        $videos = [
            '/modules/{module}/videos/{video}/file',
            '/e-learnings/{eLearning}/steps/{step}/blocks/{block}/file',
        ];

        foreach ($videos as $path) {
            $redirect = $this->paths()[$path]['get']['responses']['302'] ?? null;

            $this->assertIsArray($redirect, "{$path} does not document its redirect.");
            $this->assertArrayNotHasKey('content', $redirect, "{$path} documents a body on its redirect.");
            $this->assertArrayHasKey('Location', $redirect['headers'], "{$path} does not say where it redirects to.");
        }
    }

    /**
     * An object with nothing inside it is what a generated client sees as `unknown`. The last
     * ones were the `whenLoaded` fields of a user, restated per endpoint as an anonymous object.
     */
    #[Test]
    public function no_schema_is_an_object_with_nothing_in_it(): void
    {
        foreach ($this->schemasIn($this->document()) as $location => $schema) {
            if (($schema['type'] ?? null) !== 'object') {
                continue;
            }

            $this->assertTrue(
                isset($schema['properties']) || isset($schema['additionalProperties']),
                "{$location} is an object the document says nothing about.",
            );
        }
    }

    /** Every timestamp in a response is named `…Utc`, and every one of them is an ISO 8601 instant. */
    #[Test]
    public function every_timestamp_is_documented_as_a_date_time(): void
    {
        /** @var array<string, array<string, mixed>> $schemas */
        $schemas = $this->document()['components']['schemas'];

        foreach ($schemas as $name => $schema) {
            /** @var array<string, array<string, mixed>> $properties */
            $properties = $schema['properties'] ?? [];

            foreach ($properties as $property => $definition) {
                if (! str_ends_with($property, 'Utc')) {
                    continue;
                }

                $this->assertSame('date-time', $definition['format'] ?? null, "{$name}.{$property} is not documented as a date-time.");
            }
        }
    }

    /**
     * Every array in the document, keyed by where it sits.
     *
     * @param  array<mixed, mixed>  $node
     * @return iterable<string, array<mixed, mixed>>
     */
    private function schemasIn(array $node, string $location = '#'): iterable
    {
        yield $location => $node;

        foreach ($node as $key => $child) {
            if (is_array($child)) {
                yield from $this->schemasIn($child, "{$location}/{$key}");
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
