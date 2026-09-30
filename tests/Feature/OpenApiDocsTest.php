<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Coverage for the generated OpenAPI document.
 *
 * These tests guard the docs against the failure modes that are easy to
 * reintroduce and hard to notice: a missing @OA\Info (swagger-php refuses to
 * generate without it), a $ref pointing at a schema that no longer exists, and
 * an operation whose paths drift from the real route table.
 *
 * The spec is generated ahead of time by `php artisan l5-swagger:generate` and
 * committed, so these assertions read the committed artifact rather than
 * running the generator.
 */
class OpenApiDocsTest extends TestCase
{
    protected string $specPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->specPath = storage_path('api-docs/api-docs.json');
    }

    protected function spec(): array
    {
        $this->assertFileExists($this->specPath, 'Run: php artisan l5-swagger:generate');

        return json_decode(file_get_contents($this->specPath), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testSpecIsValidOpenApiDocument(): void
    {
        $spec = $this->spec();

        $this->assertSame('3.0.0', $spec['openapi']);
        $this->assertArrayHasKey('info', $spec);
        $this->assertSame('BRAMON API', $spec['info']['title']);
        $this->assertNotEmpty($spec['info']['version']);
        $this->assertNotEmpty($spec['info']['description']);
    }

    public function testServerBasePathMatchesApiPrefix(): void
    {
        $spec = $this->spec();

        $urls = array_column($spec['servers'], 'url');
        $this->assertContains('/api/v1/public', $urls);
    }

    /**
     * Every documented path must correspond to a real route, otherwise the
     * docs advertise endpoints that do not exist.
     */
    public function testDocumentedPathsMatchApplicationRoutes(): void
    {
        $spec = $this->spec();

        $documented = array_keys($spec['paths']);
        sort($documented);

        $registered = [];
        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();

            // The router prefixes everything with `api`, and the controllers
            // document paths relative to the `v1/public` server base URL.
            if (!str_starts_with($uri, 'api/v1/public/')) {
                continue;
            }

            $path = substr($uri, strlen('api/v1/public'));

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $registered[] = $method.' '.$path;
            }
        }
        sort($registered);

        $expected = [];
        foreach ($documented as $path) {
            foreach (array_keys($spec['paths'][$path]) as $method) {
                $expected[] = strtoupper($method).' '.$path;
            }
        }
        sort($expected);

        $this->assertSame($expected, $registered, 'Documented operations and registered routes have diverged.');
    }

    public function testEveryOperationHasAUniqueOperationId(): void
    {
        $spec = $this->spec();

        $ids = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $this->assertArrayHasKey('operationId', $operation, "{$method} {$path} has no operationId");
                $id = $operation['operationId'];
                $this->assertArrayNotHasKey($id, $ids, "Duplicate operationId: {$id}");
                $ids[$id] = true;
            }
        }

        $this->assertCount(13, $ids);
    }

    /**
     * A dangling $ref renders as an empty object in Swagger UI, which is easy
     * to miss by eye, so assert on the raw document text.
     */
    public function testAllRefsResolveToDeclaredComponents(): void
    {
        $spec = $this->spec();

        $components = $spec['components'] ?? [];
        $unresolved = [];

        $walk = function ($node) use (&$walk, $components, &$unresolved) {
            if (!is_array($node)) {
                return;
            }

            foreach ($node as $key => $value) {
                if ($key === '$ref' && is_string($value)) {
                    if (!preg_match('{^#/components/(\w+)/(.+)$}', $value, $m)) {
                        $unresolved[] = $value;

                        return;
                    }

                    [$section, $name] = [$m[1], $m[2]];
                    if (!isset($components[$section][$name])) {
                        $unresolved[] = $value;
                    }
                } else {
                    $walk($value);
                }
            }
        };

        $walk($spec);

        $this->assertSame([], $unresolved, 'Unresolved $ref targets: '.implode(', ', array_unique($unresolved)));
    }

    public function testListingEndpointsDocumentIndexUnavailable(): void
    {
        $spec = $this->spec();

        $listingPaths = [
            '/captures',
            '/captures/search',
            '/stations',
            '/stations/{stationName}',
            '/stations/{stationName}/captures',
            '/stations/{stationName}/stats',
            '/operators',
            '/operators/{operatorId}',
            '/stats',
        ];

        foreach ($listingPaths as $path) {
            $responses = $spec['paths'][$path]['get']['responses'];
            $this->assertArrayHasKey(503, $responses, "{$path} does not document 503 for a missing index");
        }
    }

    public function testValidationRejectionIsDocumentedAs422(): void
    {
        $spec = $this->spec();

        // per_page carries a max:100 rule, so an oversized page size is a 422.
        foreach (['/captures', '/stations', '/operators'] as $path) {
            $responses = $spec['paths'][$path]['get']['responses'];
            $this->assertArrayHasKey(422, $responses, "{$path} does not document 422 for invalid query params");
        }
    }

    public function testPaginationParametersAreReusableAndBounded(): void
    {
        $spec = $this->spec();

        $perPage = $spec['components']['parameters']['per_page'];
        $this->assertSame('query', $perPage['in']);
        $this->assertSame(100, $perPage['schema']['maximum']);
    }
}
