<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PhpMiniHttpServer\Http\Headers\Headers;
use PhpMiniHttpServer\Http\Protocol\HttpMethod;
use PhpMiniHttpServer\Http\Protocol\HttpVersion;
use PhpMiniHttpServer\Http\Request\HttpRequest;
use PhpSystemsPlatform\Application\Application;
use PhpSystemsPlatform\Application\Handlers\HealthHandler;
use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\Response;
use PhpSystemsPlatform\Http\Router;
use PHPUnit\Framework\TestCase;

/**
 * The Application is the one place that translates between the component
 * request/response types and the platform's own, so every test goes through a
 * real component HttpRequest and asserts on a real component HttpResponse.
 */
final class ApplicationTest extends TestCase
{
    public function testHealthRouteReturnsJsonThroughTheComponentBoundary(): void
    {
        $router = new Router();
        $router->get('/health', (new HealthHandler())(...));

        $response = new Application($router)->handle($this->request(HttpMethod::GET, '/health'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('application/json; charset=utf-8', $response->header('Content-Type'));
        self::assertSame('{"status":"ok","service":"php-systems-platform"}', $response->body);
    }

    public function testUnknownRouteBecomes404WithReasonPhraseBody(): void
    {
        $application = new Application(new Router());

        $response = $application->handle($this->request(HttpMethod::GET, '/nope'));

        self::assertSame(404, $response->statusCode());
        self::assertSame("Not Found\n", $response->body);
    }

    public function testPathMissMethodBecomes405WithAllowHeader(): void
    {
        $router = new Router();
        $router->get('/orders', static fn (Request $request, array $params): Response => Response::empty());

        $response = new Application($router)->handle($this->request(HttpMethod::DELETE, '/orders'));

        self::assertSame(405, $response->statusCode());
        self::assertSame('GET, HEAD', $response->header('Allow'));
    }

    public function testHandlerExceptionBecomes500(): void
    {
        $router = new Router();
        $router->get('/boom', static function (Request $request, array $params): Response {
            throw new \RuntimeException('boom');
        });

        $response = new Application($router)->handle($this->request(HttpMethod::GET, '/boom'));

        self::assertSame(500, $response->statusCode());
        self::assertSame("Internal Server Error\n", $response->body);
    }

    public function testQueryAndBodyMakeItThrough(): void
    {
        $router = new Router();
        $router->post('/echo', static fn (Request $request, array $params): Response => Response::json([
            'query' => $request->query,
            'body' => $request->body,
        ]));

        $application = new Application($router);
        $request = new HttpRequest(
            method: HttpMethod::POST,
            target: '/echo?verbose=1',
            version: HttpVersion::HTTP_1_1,
            headers: new Headers(),
            body: 'payload',
        );

        $response = $application->handle($request);

        self::assertSame('{"query":{"verbose":"1"},"body":"payload"}', $response->body);
    }

    private function request(HttpMethod $method, string $target): HttpRequest
    {
        return new HttpRequest(
            method: $method,
            target: $target,
            version: HttpVersion::HTTP_1_1,
            headers: new Headers(),
            body: '',
        );
    }
}
