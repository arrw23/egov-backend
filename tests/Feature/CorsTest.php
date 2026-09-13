<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CorsTest extends TestCase
{
    /**
     * The frontend calls these root-level provider shims directly from the
     * browser. Laravel's built-in cors defaults only cover api/*, so before
     * config/cors.php existed these paths returned no CORS headers, the browser
     * blocked them, and request() silently served mock data.
     */
    public static function browserCalledPaths(): array
    {
        return [
            'root api token' => ['/api/token'],
            'root liveness session' => ['/v1/liveness/session'],
            'root liveness result' => ['/v1/liveness/result/some-token'],
            'root sms push' => ['/messaging/v1/sms/push'],
            'api v1' => ['/api/v1/cases'],
            'root ereport token' => ['/api/integration/token'],
            'root pay transaction' => ['/api/v1/transaction'],
        ];
    }

    #[DataProvider('browserCalledPaths')]
    public function test_preflight_returns_cors_headers_for_browser_called_paths(string $path): void
    {
        $response = $this->call('OPTIONS', $path, [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ]);

        $response->assertStatus(204);
        $this->assertSame(
            'http://localhost:3000',
            $response->headers->get('Access-Control-Allow-Origin'),
            "Preflight for {$path} must return an Access-Control-Allow-Origin header."
        );
    }

    public function test_wildcard_origin_is_not_allowed(): void
    {
        $response = $this->call('OPTIONS', '/api/token', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertNotSame(
            'https://evil.example.com',
            $response->headers->get('Access-Control-Allow-Origin'),
            'An unlisted origin must not be reflected back.'
        );
    }
}
