<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Http;

use Closure;

/**
 * Maps a (method, path) pair to its handler. A path is exact ("/health") or
 * a pattern with {name} placeholders ("/orders/{id}"); exact routes win,
 * patterns are tried in registration order. Routing is a pure lookup - the
 * Application boundary turns the two miss exceptions into 404 and 405.
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

        // No route under this method: a 405 if another method serves the
        // path, a 404 otherwise.
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
            if (preg_match($route->regex, $path, $matches) === 1) {
                // Keep only the named groups; preg also returns numeric ones.
                return [$route, array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY)];
            }
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
