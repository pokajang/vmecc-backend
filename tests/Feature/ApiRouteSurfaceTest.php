<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiRouteSurfaceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * This is a deliberately anonymous, no-payload probe. It establishes that
     * every registered API endpoint resolves through its intended middleware
     * and fails safely before any state-changing controller work can run.
     * Positive lifecycle coverage remains in the focused feature tests.
     */
    public function test_every_api_route_handles_an_anonymous_safe_probe_without_a_server_error(): void
    {
        $failures = [];

        foreach ($this->apiRoutes() as $route) {
            $method = $this->probeMethod($route->methods());
            $uri = $this->probeUri($route->uri());

            $response = $this->call($method, '/'.$uri, [], [], [], [
                'HTTP_ACCEPT' => 'application/json',
            ]);

            if ($response->getStatusCode() >= 500 || $response->getStatusCode() === 405) {
                $failures[] = sprintf(
                    '%s %s returned %d: %s',
                    $method,
                    $uri,
                    $response->getStatusCode(),
                    mb_strimwidth($response->getContent(), 0, 300, '…'),
                );
            }
        }

        $this->assertSame([], $failures, "Unsafe API route responses:\n".implode("\n", $failures));
    }

    public function test_api_route_inventory_has_unique_method_and_uri_pairs(): void
    {
        $keys = [];

        foreach ($this->apiRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $key = $method.' '.$route->uri();
                $this->assertArrayNotHasKey($key, $keys, "Duplicate API route: {$key}");
                $keys[$key] = true;
            }
        }

        $this->assertNotEmpty($keys);
    }

    /** @return array<int, \Illuminate\Routing\Route> */
    private function apiRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn ($route): bool => str_starts_with($route->uri(), 'api/'),
        ));
    }

    /** @param array<int, string> $methods */
    private function probeMethod(array $methods): string
    {
        foreach ($methods as $method) {
            if ($method !== 'HEAD') {
                return $method;
            }
        }

        return 'GET';
    }

    private function probeUri(string $uri): string
    {
        return preg_replace_callback('/\{([^}?]+)\??\}/', function (array $match): string {
            $parameter = strtolower($match[1]);

            return match (true) {
                str_contains($parameter, 'email') => 'missing-user%40example.test',
                str_contains($parameter, 'uuid'), str_contains($parameter, 'uid') => '00000000-0000-4000-8000-000000000001',
                str_contains($parameter, 'token'), str_contains($parameter, 'code') => 'missing-token',
                default => '999999',
            };
        }, $uri) ?? $uri;
    }
}
