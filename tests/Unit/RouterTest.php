<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use PhpSystemsPlatform\Http\MethodNotAllowedException;
use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\RequestMethod;
use PhpSystemsPlatform\Http\Response;
use PhpSystemsPlatform\Http\RouteNotFoundException;
use PhpSystemsPlatform\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testExactRouteAnswers(): void
    {
        $router = new Router();
        $router->get('/health', static fn (Request $request, array $params): Response => Response::json(['ok' => true]));

        $response = $router->dispatch(new Request(RequestMethod::GET, '/health'));

        self::assertSame(200, $response->status);
        self::assertSame('{"ok":true}', $response->body);
    }

    public function testPatternRouteExtractsParameters(): void
    {
        $router = new Router();
        $router->get('/orders/{id}', static fn (Request $request, array $params): Response => Response::json($params));

        $response = $router->dispatch(new Request(RequestMethod::GET, '/orders/42'));

        self::assertSame('{"id":"42"}', $response->body);
    }

    public function testExactRouteWinsOverPattern(): void
    {
        $router = new Router();
        $router->get('/users/{id}', static fn (Request $request, array $params): Response => Response::json(['kind' => 'pattern']));
        $router->get('/users/me', static fn (Request $request, array $params): Response => Response::json(['kind' => 'exact']));

        $response = $router->dispatch(new Request(RequestMethod::GET, '/users/me'));

        self::assertSame('{"kind":"exact"}', $response->body);
    }

    public function testUnknownPathThrowsRouteNotFound(): void
    {
        $router = new Router();
        $router->get('/health', static fn (Request $request, array $params): Response => Response::empty());

        $this->expectException(RouteNotFoundException::class);

        $router->dispatch(new Request(RequestMethod::GET, '/nope'));
    }

    public function testKnownPathWrongMethodThrowsMethodNotAllowed(): void
    {
        $router = new Router();
        $router->get('/orders', static fn (Request $request, array $params): Response => Response::empty());
        $router->post('/orders', static fn (Request $request, array $params): Response => Response::empty());

        try {
            $router->dispatch(new Request(RequestMethod::DELETE, '/orders'));
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertSame(['GET', 'HEAD', 'POST'], $e->allowed);
        }
    }

    public function testHeadAnswersFromGetRoutes(): void
    {
        $router = new Router();
        $router->get('/health', static fn (Request $request, array $params): Response => Response::text('up'));

        $response = $router->dispatch(new Request(RequestMethod::HEAD, '/health'));

        self::assertSame('up', $response->body);
    }

    public function testCount(): void
    {
        $router = new Router();
        $router->get('/a', static fn (Request $request, array $params): Response => Response::empty());
        $router->post('/b/{id}', static fn (Request $request, array $params): Response => Response::empty());

        self::assertSame(2, $router->count());
    }

    public function testBodyAndHeadersReachTheHandler(): void
    {
        $router = new Router();
        $router->post('/echo', static fn (Request $request, array $params): Response => Response::json([
            'content_type' => $request->header('Content-Type'),
            'length' => strlen($request->body),
        ]));

        $response = $router->dispatch(new Request(
            RequestMethod::POST,
            '/echo',
            headers: ['content-type' => 'application/json'],
            body: '{"a":1}',
        ));

        self::assertSame('{"content_type":"application/json","length":7}', $response->body);
    }
}
