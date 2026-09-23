<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Http;

use Closure;

/**
 * Maps a request to the handler that answers it.
 *
 * The platform's own router, deliberately type-shaped like the component's:
 * routes are (method, path) pairs, a path is exact ("/health") or a pattern
 * with {name} placeholders ("/orders/{id}"), and exact routes win over
 * patterns. The handler receives the request and the extracted parameters,
 * so routing stays a pure lookup - no body parsing, no middleware, no error
 * translation. The application boundary owns those, and turns the two miss
 * exceptions into 404 and 405 responses there.
 */
final class Router
{
    /** @var array<string, array<string, Closure(Request, array<string, string>): Response>> */
    private array $exact = [];

    /** @var array<string, list<Route>> method token => ordered pattern routes */
    private array $patterns = [];

    public function get(string $path, Closure $handler): void
    {
        $this->add(RequestMethod::GET, $path, $handler);
    }

    public function post(string $path, Closure $handler): void
    {
        $this->add(RequestMethod::POST, $path, $handler);
    }

    public function put(string $path, Closure $handler): void
    {
        $this->add(RequestMethod::PUT, $path, $handler);
    }

    public function patch(string $path, Closure $handler): void
    {
        $this->add(RequestMethod::PATCH, $path, $handler);
    }

    public function delete(string $path, Closure $handler): void
    {
        $this->add(RequestMethod::DELETE, $path, $handler);
    }

    public function options(string $path, Closure $handler): void
    {
        $this->add(RequestMethod::OPTIONS, $path, $handler);
    }

    public function add(RequestMethod $method, string $path, Closure $handler): void
    {
        if (str_contains($path, '{')) {
            $this->patterns[$method->value][] = Route::compile($path, $handler);
        } else {
            $this->exact[$method->value][$path] = $handler;
        }
    }

    /**
     * @throws RouteNotFoundException    when no route matches the request
     * @throws MethodNotAllowedException when the path exists but the method does not
     */
    public function dispatch(Request $request): Response
    {
        // HEAD is GET without a response body: it answers from the GET
        // routes, and the encoder omits the body on the wire.
        $method = $request->method === RequestMethod::HEAD
            ? RequestMethod::GET
            : $request->method;
        $token = $method->value;
        $path = $request->path;

        $handler = $this->exact[$token][$path] ?? null;

        if ($handler !== null) {
            return $handler($request, []);
        }

        $matched = $this->matchPattern($token, $path);

        if ($matched !== null) {
            [$route, $params] = $matched;

            return ($route->handler)($request, $params);
        }

        $allowed = $this->allowedMethodsFor($path);

        if ($allowed !== []) {
            throw new MethodNotAllowedException($allowed);
        }

        throw new RouteNotFoundException(sprintf('No route for %s %s', $request->method->value, $path));
    }

    public function count(): int
    {
        $total = 0;

        foreach ($this->exact as $byMethod) {
            $total += count($byMethod);
        }

        foreach ($this->patterns as $byMethod) {
            $total += count($byMethod);
        }

        return $total;
    }

    /**
     * Whether some route serves $path under $method, exact or pattern.
     */
    private function serves(string $token, string $path): bool
    {
        return isset($this->exact[$token][$path]) || $this->matchPattern($token, $path) !== null;
    }

    /**
     * First matching pattern in registration order. Returns the route and the
     * extracted parameters together, so dispatch() never runs the regex twice.
     *
     * @return array{0: Route, 1: array<string, string>}|null
     */
    private function matchPattern(string $token, string $path): ?array
    {
        foreach ($this->patterns[$token] ?? [] as $route) {
            $matches = [];
            preg_match($route->regex, $path, $matches);

            if ($matches === []) {
                continue;
            }

            return [
                $route,
                array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY),
            ];
        }

        return null;
    }

    /**
     * The methods that serve $path under some route, exact or pattern, so a
     * 405 can name them in its Allow header. HEAD is implied wherever GET is.
     *
     * @return list<string>
     */
    private function allowedMethodsFor(string $path): array
    {
        $allowed = [];

        foreach (RequestMethod::cases() as $method) {
            if ($method === RequestMethod::HEAD) {
                continue; // HEAD is never registered; it is added below, with GET
            }

            if (!$this->serves($method->value, $path)) {
                continue;
            }

            $allowed[] = $method->value;

            if ($method === RequestMethod::GET) {
                $allowed[] = RequestMethod::HEAD->value;
            }
        }

        return $allowed;
    }
}
