<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Unit;

use InvalidArgumentException;
use PhpMiniDatabase\Client\ClientConfig;
use PhpSystemsPlatform\Domain\OrderService;
use PhpSystemsPlatform\Storage\Database;
use PhpSystemsPlatform\Storage\Repositories\OrderRepository;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * OrderService's validation runs before anything is persisted, so these
 * tests point it at a database nobody listens on: a payload validation
 * rejects never gets that far, and one it accepts fails on the connect.
 */
final class OrderServiceTest extends TestCase
{
    public function testACustomerLongerThanTheColumnInBytesIsABadRequest(): void
    {
        // 200 characters, 400 bytes: the mini database caps VARCHAR(255) in
        // bytes, so a character count let this through to a failed INSERT -
        // a 500 for what is a bad payload.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('customer must not exceed 255 bytes.');

        $this->service()->createOrder(str_repeat('Ж', 200), 10);
    }

    public function testACustomerThatFitsTheColumnPassesValidation(): void
    {
        try {
            $this->service()->createOrder(str_repeat('a', 255), 10);
        } catch (InvalidArgumentException $e) {
            self::fail('A 255-byte customer was refused: ' . $e->getMessage());
        } catch (Throwable) {
            // Reached the (unreachable) database: validation let it through.
        }

        $this->addToAssertionCount(1);
    }

    private function service(): OrderService
    {
        return new OrderService(new OrderRepository(Database::fromConfig(new ClientConfig(
            host: '127.0.0.1',
            port: 1,
            connectTimeoutSeconds: 0.01,
        ))));
    }
}
