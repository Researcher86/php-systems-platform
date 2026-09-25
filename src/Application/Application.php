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
use PhpSystemsPlatform\Observability\MetricsRegistry;
use PhpSystemsPlatform\Observability\Trace;
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
        private ?MetricsRegistry $metrics = null,
        private ?Trace $trace = null,
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        // PLAN Step 24: every HTTP request gets a request_id, kept when the
        // client carried one (X-Request-ID) so a chain can span the whole
        // system, generated otherwise. The scope stays open for everything
        // this request does - database calls, the queue write path - and
        // the id is echoed on the answer so the caller can follow its chain
        // into the queue. Without a Trace none of this runs.
        $requestId = $this->trace?->beginRequest($request->header('x-request-id'));
        $startedAt = microtime(true);
        $response = $this->respond($request);

        // PLAN Step 23: the HTTP boundary reports into the shared registry -
        // one count per request, one count per non-2xx answer, and the
        // request's whole duration (route dispatch and response building).
        // Without a registry none of this runs; observability is serve()'s
        // wiring decision.
        if ($this->metrics !== null) {
            $this->metrics->increment(MetricsRegistry::HTTP_REQUESTS);

            if ($response->status->value >= 400) {
                $this->metrics->increment(MetricsRegistry::HTTP_ERRORS);
            }

            $this->metrics->observe(MetricsRegistry::HTTP_REQUEST_DURATION, microtime(true) - $startedAt);
        }

        if ($this->trace !== null) {
            $response->headers->set('X-Request-ID', (string) $requestId);
            $this->trace->record('http.request', microtime(true) - $startedAt, [
                'meta' => [
                    'method' => $request->method->value,
                    'path' => $request->path(),
                    'status' => $response->status->value,
                ],
            ]);
            $this->trace->finishRequest();
        }

        return $response;
    }

    private function respond(HttpRequest $request): HttpResponse
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
