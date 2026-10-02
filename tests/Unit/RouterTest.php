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
        $router->get('/health', static fn (Request $request, array $params): Response => Response::text(''));

        $this->expectException(RouteNotFoundException::class);

        $router->dispatch(new Request(RequestMethod::GET, '/nope'));
    }

    public function testKnownPathWrongMethodThrowsMethodNotAllowed(): void
    {
        $router = new Router();
        $router->get('/orders', static fn (Request $request, array $params): Response => Response::text(''));
        $router->post('/orders', static fn (Request $request, array $params): Response => Response::text(''));

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

    /**
     * A registered path is a path, not a regex. Unescaped, the `.` in a path
     * matched any character, so `/hea.th` also answered `/health` and two
     * different registrations could quietly become one.
     */
    public function testLiteralSegmentsAreNotRegexWildcards(): void
    {
        $router = new Router();
        $router->get('/health', static fn (Request $request, array $params): Response => Response::json(['kind' => 'health']));
        $router->get('/hea.th', static fn (Request $request, array $params): Response => Response::json(['kind' => 'literal']));

        // Its own path still matches...
        self::assertSame('{"kind":"literal"}', $router->dispatch(new Request(RequestMethod::GET, '/hea.th'))->body);
        self::assertSame('{"kind":"health"}', $router->dispatch(new Request(RequestMethod::GET, '/health'))->body);

        // ...and a path that only the unescaped pattern would have matched
        // now matches nothing.
        $this->expectException(RouteNotFoundException::class);
        $router->dispatch(new Request(RequestMethod::GET, '/heaXth'));
    }

    /**
     * Regex punctuation in a path is a path, not a broken pattern: an
     * unescaped `(` would not even compile, and `+` would have meant "the
     * previous character, one or more times".
     */
    public function testRegexPunctuationInAPathIsLiteral(): void
    {
        $router = new Router();
        $router->get('/a+b', static fn (Request $request, array $params): Response => Response::json(['kind' => 'plus']));
        $router->get('/c(d)', static fn (Request $request, array $params): Response => Response::json(['kind' => 'group']));

        self::assertSame('{"kind":"plus"}', $router->dispatch(new Request(RequestMethod::GET, '/a+b'))->body);
        self::assertSame('{"kind":"group"}', $router->dispatch(new Request(RequestMethod::GET, '/c(d)'))->body);

        // "one or more" would have made /a+b answer /ab and /aaab too.
        $this->expectException(RouteNotFoundException::class);
        $router->dispatch(new Request(RequestMethod::GET, '/aaab'));
    }

    /**
     * Escaping the literal runs must not touch the placeholders: they stay
     * named groups, still one segment each.
     */
    public function testPlaceholdersStillCaptureOneSegmentEach(): void
    {
        $router = new Router();
        $router->get('/orders/{id}/items/{itemId}', static fn (Request $request, array $params): Response => Response::json($params));

        self::assertSame(
            '{"id":"42","itemId":"7"}',
            $router->dispatch(new Request(RequestMethod::GET, '/orders/42/items/7'))->body,
        );

        // A placeholder is still [^/]+, so it does not span a slash.
        $this->expectException(RouteNotFoundException::class);
        $router->dispatch(new Request(RequestMethod::GET, '/orders/42/items/7/extra'));
    }
}
