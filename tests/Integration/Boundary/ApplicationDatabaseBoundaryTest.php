<?php

declare(strict_types=1);

namespace PhpSystemsPlatform\Tests\Integration\Boundary;

use PhpMiniDatabase\Client\ClientException;
use PhpSystemsPlatform\Tests\Support\PlatformTestStack;
use PHPUnit\Framework\TestCase;

/**
 * The Application → Database boundary, against the running stack.
 *
 * PLAN Step 27 names create, read, update and transaction/error behavior.
 * The writes go in over HTTP - through the platform's real write path - and
 * the rows come back out over a second, independent connection to the same
 * database server, using the SQL OrderRepository itself uses. So a row that
 * shows up here really is in the platform's database, not in something the
 * test happened to share with the handler.
 */
final class ApplicationDatabaseBoundaryTest extends TestCase
{
    private const COLUMNS = 'id, customer, amount, product, status, created_at, updated_at';

    private static PlatformTestStack $stack;

    public static function setUpBeforeClass(): void
    {
        self::$stack = PlatformTestStack::acquire();
    }

    public static function tearDownAfterClass(): void
    {
        PlatformTestStack::release();
    }

    public function testCreateWritesExactlyTheRowTheRepositorySaysItDoes(): void
    {
        [$status, $answer] = self::$stack->http('POST', '/orders', '{"customer":"Alan Turing","amount":"31.50"}');

        self::assertSame(201, $status);

        $created = json_decode($answer, true, 512, JSON_THROW_ON_ERROR);

        $rows = self::$stack->database()->read(
            'SELECT ' . self::COLUMNS . ' FROM orders WHERE id = ?',
            [$created['id']],
        );

        self::assertCount(1, $rows);
        self::assertSame($created['id'], $rows[0]['id']);
        self::assertSame('Alan Turing', $rows[0]['customer']);
        self::assertSame('31.50', $rows[0]['amount']);
        self::assertSame($created['product'], $rows[0]['product']);
        self::assertSame('created', $rows[0]['status']);
        self::assertSame($created['createdAt'], $rows[0]['created_at']);
        self::assertSame($created['updatedAt'], $rows[0]['updated_at']);
    }

    public function testTheCreateAnswerPointsAtTheRowItWrote(): void
    {
        [$status, $answer, $headers] = self::$stack->http('POST', '/orders', '{"customer":"Katherine Johnson","amount":"12.00"}');

        self::assertSame(201, $status);

        $created = json_decode($answer, true, 512, JSON_THROW_ON_ERROR);
        $location = PlatformTestStack::header($headers, 'Location');

        self::assertSame('/orders/' . $created['id'], $location);

        // The Location really is the row: reading it back finds the same
        // order, and reading it over the database finds the same id.
        [$readStatus] = self::$stack->http('GET', (string) $location);

        self::assertSame(200, $readStatus);
        self::assertCount(1, self::$stack->database()->read('SELECT id FROM orders WHERE id = ?', [$created['id']]));
    }

    public function testReadAnswersTheAuthoritativeRowForAKnownId(): void
    {
        $created = $this->createOrder('Read Path', '7.25');

        [$status, $answer] = self::$stack->http('GET', '/orders/' . $created['id']);

        self::assertSame(200, $status);

        $read = json_decode($answer, true, 512, JSON_THROW_ON_ERROR);
        $row = self::$stack->database()->read('SELECT ' . self::COLUMNS . ' FROM orders WHERE id = ?', [$created['id']])[0];

        self::assertSame($row['id'], $read['id']);
        self::assertSame($row['customer'], $read['customer']);
        self::assertSame($row['amount'], $read['amount']);
        self::assertSame($row['status'], $read['status']);
    }

    public function testUpdateChangesTheStoredRowAndItsTimestamps(): void
    {
        $created = $this->createOrder('Update Path', '15.00');

        [$status, $answer] = self::$stack->http(
            'PUT',
            '/orders/' . $created['id'],
            '{"status":"processing"}',
        );

        self::assertSame(200, $status);

        $updated = json_decode($answer, true, 512, JSON_THROW_ON_ERROR);
        $row = self::$stack->database()->read('SELECT ' . self::COLUMNS . ' FROM orders WHERE id = ?', [$created['id']])[0];

        self::assertSame('processing', $row['status']);

        // The update wrote its own answer's timestamp into the row and left
        // the creation one alone - the same two columns, one of them moved.
        // (Timestamps have second granularity, so a create and an update in
        // the same second are legitimately equal - the columns are compared
        // against the answers rather than against each other.)
        self::assertSame($updated['updatedAt'], $row['updated_at']);
        self::assertSame($created['createdAt'], $row['created_at']);
    }

    public function testAnUnknownStatusIsRejectedBeforeAnyWriteHappens(): void
    {
        $created = $this->createOrder('Bad Update', '15.00');

        [$status, $body] = self::$stack->http('PUT', '/orders/' . $created['id'], '{"status":"teleported"}');

        self::assertSame(400, $status);
        self::assertSame('Unknown status.', json_decode($body, true, 512, JSON_THROW_ON_ERROR)['error']);

        $row = self::$stack->database()->read('SELECT status FROM orders WHERE id = ?', [$created['id']])[0];

        self::assertSame('created', $row['status']);
    }

    public function testARejectedCreateNeverReachesTheDatabase(): void
    {
        $marker = 'Rejected Create ' . uniqid('', false);

        $before = self::$stack->database()->read('SELECT COUNT(*) AS total FROM orders WHERE customer = ?', [$marker]);

        [$status] = self::$stack->http('POST', '/orders', json_encode([
            'customer' => $marker,
            'amount' => 'not-a-number',
        ], JSON_THROW_ON_ERROR));

        self::assertSame(400, $status);

        $after = self::$stack->database()->read('SELECT COUNT(*) AS total FROM orders WHERE customer = ?', [$marker]);

        self::assertSame($before[0]['total'], $after[0]['total']);
    }

    public function testAFailedStatementThrowsAndTheConnectionGoesBackToThePool(): void
    {
        // The error behavior of this boundary: a statement the database
        // refuses is an exception, not a silent empty result - and the
        // connection it happened on is returned to the pool rather than
        // poisoned, so the very next query still works.
        try {
            self::$stack->database()->read('SELECT * FROM no_such_table');
            self::fail('A statement against a table that does not exist should have thrown.');
        } catch (ClientException) {
            // expected
        }

        $rows = self::$stack->database()->read('SELECT 1 AS answer');

        self::assertSame(1, (int) $rows[0]['answer']);
    }

    public function testWritesAtThisBoundaryAreNotGroupedIntoATransaction(): void
    {
        // Pinned deliberately rather than left to be assumed: this seam is
        // read()/write() only, so each write is its own commit and a failure
        // halfway through a sequence of them keeps the ones before it. The
        // component's own Connection offers transactions; nothing in the
        // platform needs one yet, so the seam does not pretend to offer one
        // either - and this is the test that will have to change first if a
        // multi-statement write path ever does.
        $id = 'boundary-' . uniqid('', false);

        self::$stack->database()->write(
            'INSERT INTO orders (id, customer, amount, product, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, 'No Transaction', '1.00', 'standard', 'created', '2026-01-01 00:00:00', '2026-01-01 00:00:00'],
        );

        try {
            self::$stack->database()->write(
                'INSERT INTO orders (id, customer, amount) VALUES (?, ?, ?)',
                ['too-few-columns', 'x', '1.00'],
            );
            self::fail('An INSERT with too few columns should have thrown.');
        } catch (ClientException) {
            // expected
        }

        $survivors = self::$stack->database()->read('SELECT id FROM orders WHERE id = ?', [$id]);

        self::assertCount(1, $survivors, 'The first write stands: nothing at this boundary rolls it back.');

        // Clean up after the test so the row is not mistaken for platform data.
        self::$stack->database()->write('DELETE FROM orders WHERE id = ?', [$id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function createOrder(string $customer, string $amount): array
    {
        [$status, $answer] = self::$stack->http(
            'POST',
            '/orders',
            json_encode(['customer' => $customer, 'amount' => $amount], JSON_THROW_ON_ERROR),
        );

        self::assertSame(201, $status);

        return json_decode($answer, true, 512, JSON_THROW_ON_ERROR);
    }
}
