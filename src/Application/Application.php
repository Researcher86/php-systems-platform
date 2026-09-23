<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Application;

use PhpMiniHttpServer\Http\Handler\RequestHandler;
use PhpMiniHttpServer\Http\Headers\Headers;
use PhpMiniHttpServer\Http\Protocol\HttpVersion;
use PhpMiniHttpServer\Http\Request\HttpRequest;
use PhpMiniHttpServer\Http\Response\HttpResponse;
use PhpMiniHttpServer\Http\Response\HttpStatusCode;
use PhpMiniHttpServer\Http\Response\ResponseFactory;
use PhpSystemsPlatform\Http\MethodNotAllowedException;
use PhpSystemsPlatform\Http\Request;
use PhpSystemsPlatform\Http\RequestMethod;
use PhpSystemsPlatform\Http\Response;
use PhpSystemsPlatform\Http\RouteNotFoundException;
use PhpSystemsPlatform\Http\Router;
use Throwable;

/**
 * The single boundary between the component HTTP server and the platform.
 *
 * Implements the component's RequestHandler so the server can hand us its
 * HttpRequest directly. Inside, the request is converted into the platform's
 * own Request value, routed through the platform Router, and the resulting
 * Response is converted back into the component's HttpResponse. This happens
 * exactly once, here: controllers and the router never see the component
 * types, and the component never sees the platform's.
 *
 * Route misses and handler failures are translated into their HTTP answers
 * at the same boundary, so one bad request never stops the server.
 */
final readonly class Application implements RequestHandler
{
    public function __construct(
        private Router $router,
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $internal = new Request(
            method: RequestMethod::from($request->method->value),
            path: $request->path(),
            query: $request->query(),
            headers: array_change_key_case($request->headers->normalized(), CASE_LOWER),
            body: $request->body,
        );

        try {
            return $this->toHttpResponse($this->router->dispatch($internal));
        } catch (RouteNotFoundException) {
            return $this->error(HttpStatusCode::NOT_FOUND);
        } catch (MethodNotAllowedException $e) {
            $headers = new Headers();
            $headers->set('Allow', implode(', ', $e->allowed));

            return $this->error(HttpStatusCode::METHOD_NOT_ALLOWED, $headers);
        } catch (Throwable) {
            return $this->error(HttpStatusCode::INTERNAL_SERVER_ERROR);
        }
    }

    private function toHttpResponse(Response $response): HttpResponse
    {
        $headers = new Headers();

        foreach ($response->headers as $name => $value) {
            $headers->set($name, $value);
        }

        return new HttpResponse(
            version: HttpVersion::HTTP_1_1,
            status: HttpStatusCode::from($response->status),
            headers: $headers,
            body: $response->body,
        );
    }

    /**
     * The same shape the component's ErrorHandlerMiddleware produces: the
     * body is the status' own reason phrase, so "404" and "Not Found" cannot
     * drift apart.
     */
    private function error(HttpStatusCode $status, Headers $headers = new Headers()): HttpResponse
    {
        return ResponseFactory::text($status->reasonPhrase() . PHP_EOL, $status, $headers);
    }
}
