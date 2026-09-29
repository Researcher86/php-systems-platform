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
use PhpMiniHttpServer\Support\Logger;
use PhpMiniHttpServer\Support\StderrLogger;
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
 * The component's HttpRequest is converted into the platform's Request,
 * routed, and the platform Response converted back - exactly once, here, so
 * handlers and the router never see component types and vice versa.
 *
 * Route misses and handler failures become their HTTP answers at the same
 * boundary, so one bad request never stops the server. Metrics and tracing
 * are optional: without a registry or a Trace none of that bookkeeping runs.
 */
final readonly class Application implements RequestHandler
{
    public function __construct(
        private Router $router,
        private ?MetricsRegistry $metrics = null,
        private ?Trace $trace = null,
        private ?Logger $logger = null,
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        // The request scope stays open for everything this request does (db
        // calls, the job it publishes). A well-formed inbound X-Request-ID is
        // kept so a client can carry one chain across the whole system.
        $requestId = $this->trace?->beginRequest($request->header('x-request-id'));
        $startedAt = microtime(true);
        $response = $this->respond($request);
        $elapsed = microtime(true) - $startedAt;

        if ($this->metrics !== null) {
            $this->metrics->increment(MetricsRegistry::HTTP_REQUESTS);

            // 4xx and 5xx both count as errors; 3xx does not.
            if ($response->status->value >= 400) {
                $this->metrics->increment(MetricsRegistry::HTTP_ERRORS);
            }

            $this->metrics->observe(MetricsRegistry::HTTP_REQUEST_DURATION, $elapsed);
        }

        if ($this->trace !== null) {
            $response->headers->set('X-Request-ID', (string) $requestId);
            $this->trace->record('http.request', $elapsed, [
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
        // The two method enums carry the same cases, so from() cannot fail.
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
        } catch (Throwable $e) {
            $this->reportHandlerFailure($request, $e);

            return $this->error(HttpStatusCode::INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * The client gets a bare 500; the operator gets the throwable on STDERR.
     * Never the other way round: an exception message is the one thing on
     * this path that routinely holds a connection string or a file path.
     */
    private function reportHandlerFailure(HttpRequest $request, Throwable $e): void
    {
        $logger = $this->logger ?? new StderrLogger();

        $logger->log(sprintf(
            'unhandled %s: %s: %s at %s:%d',
            $request->method->value,
            $request->path(),
            $e::class,
            $e->getFile(),
            $e->getLine(),
        ));
        $logger->log($e->getTraceAsString());
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
     * The same shape as the component's ErrorHandlerMiddleware: the body is
     * the status' own reason phrase, so "404" and "Not Found" cannot drift.
     */
    private function error(HttpStatusCode $status, Headers $headers = new Headers()): HttpResponse
    {
        return ResponseFactory::text($status->reasonPhrase() . PHP_EOL, $status, $headers);
    }
}
