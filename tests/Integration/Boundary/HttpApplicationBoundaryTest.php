<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Integration\Boundary;

use PhpSystemsPlatform\Tests\Support\PlatformTestStack;
use PHPUnit\Framework\TestCase;

/**
 * The HTTP → Application boundary, against the running stack.
 *
 * PLAN Step 27 names three things to pin here: request routing, response
 * handling, errors. All three are tested the way a client experiences them -
 * bytes on the socket to the serve process, answered by the platform's
 * Application - so what is asserted is the boundary itself, not the
 * Application's internals called directly.
 */
final class HttpApplicationBoundaryTest extends TestCase
{
    private static PlatformTestStack $stack;

    public static function setUpBeforeClass(): void
    {
        self::$stack = PlatformTestStack::acquire();
    }

    public static function tearDownAfterClass(): void
    {
        PlatformTestStack::release();
    }

    public function testAGetIsRoutedToTheHandlerThatOwnsThePath(): void
    {
        [$status, $body, $headers] = self::$stack->http('GET', '/health');

        self::assertSame(200, $status);
        self::assertSame(
            'application/json; charset=utf-8',
            PlatformTestStack::header($headers, 'content-type'),
        );

        $health = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $health['status']);
        self::assertSame('php-systems-platform', $health['service']);
    }

    public function testThePathParameterReachesTheHandlerAsARouteParameter(): void
    {
        // A matched route with an id in the URL the database does not know is
        // the handler's own 404 - JSON, from inside the platform - which is
        // what tells it apart from the router's plain-text one below.
        [$status, $body] = self::$stack->http('GET', '/orders/00000000-0000-4000-8000-000000000000?trace=1');

        self::assertSame(404, $status);

        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Order not found.', $payload['error']);
    }

    public function testAnUnknownPathIsARouteMissAtTheApplicationBoundary(): void
    {
        [$status, $body, $headers] = self::$stack->http('GET', '/no-such-route');

        self::assertSame(404, $status);

        // The component's ErrorHandlerMiddleware shape, verbatim: the status'
        // own reason phrase, so "404" and "Not Found" cannot drift apart - and
        // nothing internal about the platform leaks into it.
        self::assertSame("Not Found\n", $body);
        self::assertStringNotContainsString('PlatformTestStack', $body);
        self::assertStringNotContainsString('{', $body);
        self::assertSame('text/plain; charset=utf-8', PlatformTestStack::header($headers, 'content-type'));
    }

    public function testAKnownPathWithTheWrongMethodAnswers405AndSaysWhatIsAllowed(): void
    {
        [$status, $body, $headers] = self::$stack->http('GET', '/orders');

        self::assertSame(405, $status);
        self::assertSame('POST', PlatformTestStack::header($headers, 'Allow'));
        self::assertSame("Method Not Allowed\n", $body);

        [$status, , $headers] = self::$stack->http('DELETE', '/orders/00000000-0000-4000-8000-000000000000');

        self::assertSame(405, $status);
        self::assertSame('GET, HEAD, PUT', PlatformTestStack::header($headers, 'Allow'));
    }

    public function testAMalformedBodyIsAHandlerErrorAndTheServerKeepsServing(): void
    {
        [$status, $body] = self::$stack->http('POST', '/orders', '{"customer": "Ada", am');

        self::assertSame(400, $status);
        self::assertSame('Malformed JSON payload.', json_decode($body, true, 512, JSON_THROW_ON_ERROR)['error']);

        // One bad request must not take the server with it: the error was
        // answered, not crashed on.
        [$status] = self::$stack->http('GET', '/health');
        self::assertSame(200, $status);
    }

    public function testADomainRejectionBecomesA400WithTheDomainsOwnReason(): void
    {
        // The Application boundary turns everything below it into a 500, so a
        // 400 here can only have come from the domain refusing the order -
        // the reason text is the domain's, not the platform's.
        [$status, $body] = self::$stack->http('POST', '/orders', '{"customer":"","amount":"10.00"}');

        self::assertSame(400, $status);
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $payload);
        self::assertNotSame('', $payload['error']);
    }

    public function testTheRequestIdTheClientCarriesIsKeptAndEchoedOnTheAnswer(): void
    {
        [$status, , $headers] = self::$stack->http('GET', '/health', null, ['X-Request-ID: boundary-42']);

        self::assertSame(200, $status);
        self::assertSame('boundary-42', PlatformTestStack::header($headers, 'x-request-id'));
    }

    public function testAnAnswerWithoutAClientRequestIdStillCarriesOne(): void
    {
        [$status, , $headers] = self::$stack->http('GET', '/health');

        self::assertSame(200, $status);
        self::assertNotNull(PlatformTestStack::header($headers, 'x-request-id'));
    }

    public function testTheWireResponseIsTheComponentsOwnStatusLineAndHeaderBlock(): void
    {
        // Written by hand rather than parsed by a client, so the status line
        // and the header block are judged as bytes on the wire.
        $raw = self::$stack->rawHttp("GET /health HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");

        self::assertStringStartsWith("HTTP/1.1 200 OK\r\n", $raw);
        self::assertStringContainsString('Content-Type: application/json; charset=utf-8', $raw);
        self::assertStringContainsString('X-Request-ID: ', $raw);
        self::assertStringContainsString('{"status":"ok","service":"php-systems-platform"}', $raw);
    }

    public function testTheWireErrorResponseCarriesTheStatusLineMatchingTheCode(): void
    {
        $raw = self::$stack->rawHttp("GET /no-such-route HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");

        self::assertStringStartsWith("HTTP/1.1 404 Not Found\r\n", $raw);
        self::assertStringEndsWith("\r\n\r\nNot Found\n", $raw);
    }

    public function testTheFailureInjectionRouteIsRegisteredInADevelopmentServe(): void
    {
        // PLAN Step 22's rule, seen from the outside: the route exists at all
        // only because this serve is a development one, and the answer is the
        // crash report rather than a status code of its own.
        [$status, $body] = self::$stack->http('POST', '/debug/fail-worker');

        self::assertSame(200, $status);

        $report = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($report['crash_detected']);
        self::assertSame('worker_crashed', $report['error']);
        self::assertTrue($report['replacement_started']);
    }
}
