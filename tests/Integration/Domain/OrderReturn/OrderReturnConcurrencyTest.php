<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Thelia\Tests\Integration\Domain\OrderReturn;

use PHPUnit\Framework\Attributes\DataProvider;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Connection\ConnectionWrapper;
use Propel\Runtime\Connection\PdoConnection;
use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Propel;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Config\DatabaseConfiguration;
use Thelia\Core\Event\OrderReturn\OrderReturnEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\OrderReturn\Exception\ReturnNotAllowedException;
use Thelia\Domain\OrderReturn\Exception\ReturnRequestConflictException;
use Thelia\Domain\OrderReturn\Service\OrderReturnComposer;
use Thelia\Domain\OrderReturn\Service\OrderReturnWriteTransaction;
use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct as OrderProductModel;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderReturn as OrderReturnModel;
use Thelia\Model\OrderReturnLine;
use Thelia\Model\OrderReturnStatus;
use Thelia\Model\OrderReturnStatusQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * Every other test in this suite runs inside the single transaction
 * IntegrationTestCase wraps around it, where a second `SELECT ... FOR UPDATE`
 * never blocks: a MySQL session always re-acquires the row locks it already
 * holds. Proving the locks of {@see OrderReturnWriteTransaction} protect
 * anything needs a second, genuinely independent database session.
 *
 * Two kinds of competing request are used. One runs on a second connection of
 * this process, and is paused and resumed between the steps of the request
 * under test. The other runs in a PHP process of its own: it is the only way
 * to have a request commit while the request under test is really waiting on
 * a lock, which is the sequence the locks-first order exists for. Nothing in
 * that exchange is left to timing: the child says when it holds its locks,
 * and commits only once it has seen the request under test blocked on them.
 */
final class OrderReturnConcurrencyTest extends IntegrationTestCase
{
    // A dedicated connection only proves anything outside the transaction
    // IntegrationTestCase would otherwise wrap around it and roll back: rows
    // this test commits for real are cleaned up by hand in tearDown().
    protected bool $useTransaction = false;

    // How long either side of the exchange with the competing process waits
    // for the other before giving up: generous, so that a slow runner is only
    // slow, never wrong. Nothing waits that long when things go as they should.
    private const COMPETING_PATIENCE_SECONDS = 10;

    // The lock wait timeout of the request under test while it waits on the
    // competing process, and the one every other test runs with.
    private const LOCK_WAIT_TIMEOUT_AGAINST_A_PROCESS = self::COMPETING_PATIENCE_SECONDS;
    private const LOCK_WAIT_TIMEOUT = 1;

    private ?ConnectionWrapper $otherSessionConnection = null;

    private ?ConnectionInterface $mainConnection = null;

    private ?ReturnEligibilityChecker $checker = null;

    private ?OrderReturnComposer $composer = null;

    private ?OrderReturnWriteTransaction $transaction = null;

    private ?FixtureFactory $factory = null;

    private ?string $previousEnabled = null;

    private ?string $previousWindowDays = null;

    private ?string $previousLockWaitTimeout = null;

    /** @var resource|null */
    private $competingProcess;

    /** @var array<int, resource> */
    private array $competingPipes = [];

    /** @var list<int> */
    private array $customerIds = [];

    /** @var list<int> */
    private array $orderIds = [];

    /** @var list<int> */
    private array $cartIds = [];

    /** @var list<int> */
    private array $orderAddressIds = [];

    /** @var list<int> */
    private array $orderProductIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousEnabled = ConfigQuery::read(ReturnEligibilityChecker::ENABLED_CONFIG_KEY, '0');
        $this->previousWindowDays = ConfigQuery::read(ReturnEligibilityChecker::WINDOW_CONFIG_KEY, (string) ReturnEligibilityChecker::DEFAULT_WINDOW_DAYS);
        ConfigQuery::write(ReturnEligibilityChecker::ENABLED_CONFIG_KEY, '1');
        ConfigQuery::write(ReturnEligibilityChecker::WINDOW_CONFIG_KEY, '14');

        $this->otherSessionConnection = new ConnectionWrapper(new PdoConnection(
            $this->dsn(),
            $_SERVER['DATABASE_USER'],
            $_SERVER['DATABASE_PASSWORD'],
        ));
        $this->otherSessionConnection->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $this->checker = $this->getService(ReturnEligibilityChecker::class);
        $this->composer = $this->getService(OrderReturnComposer::class);
        $this->transaction = $this->getService(OrderReturnWriteTransaction::class);
        $this->factory = $this->createFixtureFactory();

        // The connection Propel, the locks and the checker's reads all use.
        // A wait the test does not expect fails in a second instead of hanging
        // the suite for the default fifty.
        $this->mainConnection = Propel::getWriteConnection(DatabaseConfiguration::THELIA_CONNECTION_NAME);
        $this->previousLockWaitTimeout = (string) $this->mainConnection->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn();
        $this->mainConnection->exec('SET SESSION innodb_lock_wait_timeout = '.self::LOCK_WAIT_TIMEOUT);
    }

    protected function tearDown(): void
    {
        try {
            $this->stopCompetingProcess();

            if ($this->otherSessionConnection?->inTransaction()) {
                $this->otherSessionConnection->rollBack();
            }

            if ($this->mainConnection?->inTransaction()) {
                $this->mainConnection->rollBack();
            }

            if (null !== $this->previousLockWaitTimeout) {
                $this->mainConnection?->exec('SET SESSION innodb_lock_wait_timeout = '.(int) $this->previousLockWaitTimeout);
            }

            $this->deleteFixtures();
        } finally {
            // Restored whatever happened to the cleanup above: a shop left with
            // returns switched on changes what every later test sees.
            if (null !== $this->previousEnabled) {
                ConfigQuery::write(ReturnEligibilityChecker::ENABLED_CONFIG_KEY, $this->previousEnabled);
            }

            if (null !== $this->previousWindowDays) {
                ConfigQuery::write(ReturnEligibilityChecker::WINDOW_CONFIG_KEY, $this->previousWindowDays);
            }

            ConfigQuery::resetCache();
            OrderReturnStatusQuery::resetCache();

            parent::tearDown();
        }
    }

    /**
     * How the request under test opens its transaction: as every core path
     * does, the rows locked before anything is read, or nested in a
     * transaction a caller opened itself, where the checker falls back to
     * locking reads.
     *
     * @return iterable<string, array{bool}>
     */
    public static function transactionModes(): iterable
    {
        yield 'locks first' => [false];
        yield 'nested in a caller transaction' => [true];
    }

    /**
     * A first request holds the line and has not committed yet. A second one
     * for the same line must wait for it rather than read the quantity out
     * from under it.
     */
    #[DataProvider('transactionModes')]
    public function testASecondRequestWaitsForTheLocksOfTheFirstOne(bool $nested): void
    {
        [$order, $customer, [$orderProduct]] = $this->paidOrderWithReturnableLines(2.0);

        $this->otherSessionConnection->beginTransaction();
        $this->lockOnOtherSession('order_product', (int) $orderProduct->getId());

        $this->expectLockWaitTimeout(
            'A second, genuinely concurrent request read past the lock held by another session instead of waiting for it.',
            fn () => $this->inWriteTransaction($nested, $order, [$orderProduct], fn () => $this->composer->priceRequestedLines($order, $customer, [
                ['order_product' => $orderProduct, 'quantity' => 1.0],
            ])),
        );
    }

    /**
     * The sequence the locks-first order exists for: the first request holds
     * the order and the line, takes every unit and commits while the second
     * one waits on its locks. The second request has read nothing yet, so its
     * picture is taken after that commit, and it is refused.
     */
    public function testARequestWaitingOnTheLocksIsRefusedWhatTheFirstOneCommitted(): void
    {
        [$order, $customer, [$orderProduct]] = $this->paidOrderWithReturnableLines(2.0);

        $this->startCompetingProcess($order, [[$orderProduct, 2.0]], includePostage: false);

        $this->assertRefusedOnceTheCompetingProcessCommitted(
            'The returned quantity exceeds the returnable quantity of this line.',
            'The second request was granted a unit the first one took and committed while it waited.',
            fn () => $this->inWriteTransaction(false, $order, [$orderProduct], fn () => $this->composer->priceRequestedLines($order, $customer, [
                ['order_product' => $orderProduct, 'quantity' => 1.0],
            ])),
        );
    }

    /**
     * A return over two lines has every line locked before the first is read,
     * so a competing return committed on the second line while it waited is
     * seen too.
     */
    public function testAReturnOverSeveralLinesWaitingOnTheLocksIsRefusedWhatWasCommittedOnOne(): void
    {
        [$order, $customer, [$firstLine, $secondLine]] = $this->paidOrderWithReturnableLines(1.0, 1.0);

        $this->startCompetingProcess($order, [[$secondLine, 1.0]], includePostage: false);

        $this->assertRefusedOnceTheCompetingProcessCommitted(
            'The returned quantity exceeds the returnable quantity of this line.',
            'The second line was granted a unit a competing return took and committed while the request waited.',
            fn () => $this->inWriteTransaction(false, $order, [$firstLine, $secondLine], fn () => $this->composer->priceRequestedLines($order, $customer, [
                ['order_product' => $firstLine, 'quantity' => 1.0],
                ['order_product' => $secondLine, 'quantity' => 1.0],
            ])),
        );
    }

    /**
     * The postage counterpart: two returns on two different lines of one order
     * meet on the order row, and the second sees the postage the first took.
     */
    public function testAReturnWaitingOnTheLocksIsRefusedThePostageTheFirstOneCommitted(): void
    {
        [$order, $customer, [$firstLine, $secondLine]] = $this->paidOrderWithReturnableLines(1.0, 1.0);

        $this->startCompetingProcess($order, [[$firstLine, 1.0]], includePostage: true);

        $this->assertRefusedOnceTheCompetingProcessCommitted(
            'The postage of this order is already included in another return.',
            'The postage was granted to a second return after a first one carried it and committed.',
            fn () => $this->inWriteTransaction(false, $order, [$secondLine], function () use ($order, $customer, $secondLine): void {
                $this->composer->priceRequestedLines($order, $customer, [
                    ['order_product' => $secondLine, 'quantity' => 1.0],
                ]);
                $this->composer->postageRefund($order);
            }),
        );
    }

    /**
     * Nested in a transaction a caller opened and has already read in, the
     * picture predates the competing commit: only a locking read sees it.
     */
    public function testInACallerTransactionThatAlreadyReadAReturnIsRefusedWhatWasCommittedSince(): void
    {
        [$order, $customer, [$orderProduct]] = $this->paidOrderWithReturnableLines(2.0);

        $this->openCompetingReturnOnOtherSession($order, [[$orderProduct, 2.0]], includePostage: false);

        $this->assertRefused(
            'The returned quantity exceeds the returnable quantity of this line.',
            'The second request was granted a unit the first one had already taken and committed.',
            fn () => $this->inACallerTransactionThatAlreadyRead($order, fn () => $this->inWriteTransaction(true, $order, [$orderProduct], fn () => $this->composer->priceRequestedLines($order, $customer, [
                ['order_product' => $orderProduct, 'quantity' => 1.0],
            ]))),
        );
    }

    public function testInACallerTransactionThatAlreadyReadTheSecondLineIsCheckedAgainstWhatWasCommittedSince(): void
    {
        [$order, $customer, [$firstLine, $secondLine]] = $this->paidOrderWithReturnableLines(1.0, 1.0);

        $this->openCompetingReturnOnOtherSession($order, [[$secondLine, 1.0]], includePostage: false);

        $this->assertRefused(
            'The returned quantity exceeds the returnable quantity of this line.',
            'The second line was granted a unit a competing return had already taken and committed.',
            fn () => $this->inACallerTransactionThatAlreadyRead($order, fn () => $this->inWriteTransaction(true, $order, [$firstLine, $secondLine], fn () => $this->composer->priceRequestedLines($order, $customer, [
                ['order_product' => $firstLine, 'quantity' => 1.0],
                ['order_product' => $secondLine, 'quantity' => 1.0],
            ]))),
        );
    }

    public function testInACallerTransactionThatAlreadyReadThePostageIsCheckedAgainstWhatWasCommittedSince(): void
    {
        [$order, $customer, [$firstLine, $secondLine]] = $this->paidOrderWithReturnableLines(1.0, 1.0);

        $this->openCompetingReturnOnOtherSession($order, [[$firstLine, 1.0]], includePostage: true);

        $this->assertRefused(
            'The postage of this order is already included in another return.',
            'The postage was granted to a second return after a first one had carried it and committed.',
            fn () => $this->inACallerTransactionThatAlreadyRead($order, fn () => $this->inWriteTransaction(true, $order, [$secondLine], function () use ($order, $customer, $secondLine): void {
                $this->composer->priceRequestedLines($order, $customer, [
                    ['order_product' => $secondLine, 'quantity' => 1.0],
                ]);
                $this->composer->postageRefund($order);
            })),
        );
    }

    /**
     * Two returns on two different lines of the same order share no line lock:
     * the order row is what makes the second wait for the first, rather than
     * count the postage returns without it.
     */
    #[DataProvider('transactionModes')]
    public function testASecondReturnWaitsBeforeCountingThePostageReturns(bool $nested): void
    {
        [$order, , [$firstLine, $secondLine]] = $this->paidOrderWithReturnableLines(1.0, 1.0);

        $this->openCompetingReturnOnOtherSession($order, [[$firstLine, 1.0]], includePostage: true);

        $this->expectLockWaitTimeout(
            'The postage was counted without waiting for a return that carries it and is about to commit.',
            fn () => $this->inWriteTransaction($nested, $order, [$secondLine], fn () => $this->composer->postageRefund($order)),
        );
    }

    /**
     * Checking a line must not hold more than that line. A read that locks the
     * gaps of order_return_line.order_product_id holds the gap every line with
     * no return yet falls in, whatever its order: a second request writing a
     * return there waits on it, and when both requests have read before
     * writing, one dies of a deadlock (1213). Here the competing request
     * writes on another order while the request under test has checked its
     * line and not committed yet: it must not wait at all.
     */
    public function testCheckingALineDoesNotBlockAReturnOnAnotherOrder(): void
    {
        [$order, $customer, [$orderProduct]] = $this->paidOrderWithReturnableLines(1.0);
        [$otherOrder, , [$otherOrderProduct]] = $this->paidOrderWithReturnableLines(1.0);

        $this->inWriteTransaction(false, $order, [$orderProduct], function () use ($order, $customer, $orderProduct, $otherOrder, $otherOrderProduct): void {
            $this->composer->priceRequestedLines($order, $customer, [
                ['order_product' => $orderProduct, 'quantity' => 1.0],
            ]);

            $this->assertWritesWithoutWaiting(
                fn () => $this->openCompetingReturnOnOtherSession($otherOrder, [[$otherOrderProduct, 1.0]], includePostage: false),
                'A return on another order waited on the check of this line: the check locked more than its own rows.',
            );
        });
    }

    /**
     * Same for the postage: counting the postage returns of an order must not
     * hold the index gap the returns of the next orders are written in.
     */
    public function testCheckingThePostageOfAnOrderDoesNotBlockAReturnOnAnotherOrder(): void
    {
        [$order] = $this->paidOrderWithReturnableLines(1.0);
        [$otherOrder] = $this->paidOrderWithReturnableLines(1.0);

        $this->inWriteTransaction(false, $order, [], function () use ($order, $otherOrder): void {
            $this->composer->postageRefund($order);

            $this->assertWritesWithoutWaiting(
                fn () => $this->openCompetingReturnOnOtherSession($otherOrder, [], includePostage: true),
                'A return on another order waited on the postage check of this one: the check locked more than its own order.',
            );
        });
    }

    /**
     * Two returns asking for the same two lines in opposite orders, [A, B] and
     * [B, A], would each lock their first line and wait for the other's: a
     * deadlock. The rows are locked in one order, whatever the order of the
     * request. The competing request holds A; the request under test asks for
     * [B, A], waits on A, and must not hold B meanwhile.
     */
    public function testTheRowsAreLockedInOneOrder(): void
    {
        [$order, , [$firstLine, $secondLine]] = $this->paidOrderWithReturnableLines(1.0, 1.0);

        $this->otherSessionConnection->beginTransaction();
        $this->lockOnOtherSession('order_product', (int) $firstLine->getId());

        $this->mainConnection->beginTransaction();

        try {
            $this->transaction->lock((int) $order->getId(), [(int) $secondLine->getId(), (int) $firstLine->getId()]);
            self::fail('The request did not wait for the line the competing request holds.');
        } catch (\PDOException $timeout) {
            self::assertStringContainsString('Lock wait timeout exceeded', $timeout->getMessage());
        }

        try {
            $this->lockOnOtherSession('order_product', (int) $secondLine->getId());
        } catch (\PDOException) {
            self::fail('The request held the second line of its list while waiting for the first: two requests in opposite orders deadlock.');
        } finally {
            $this->mainConnection->rollBack();
        }
    }

    /**
     * The ids of the lines to lock come from the request. A line of somebody
     * else's order named there must be neither locked - its owner would be
     * kept from opening a return for as long as this transaction lasts - nor
     * accepted.
     */
    public function testALineOfAnotherOrderIsNeitherLockedNorAccepted(): void
    {
        [$order, $customer, [$orderProduct]] = $this->paidOrderWithReturnableLines(1.0);
        [, , [$foreignLine]] = $this->paidOrderWithReturnableLines(1.0);

        $this->assertRefused(
            'This product line does not belong to the order.',
            'A line of another order was accepted in a return.',
            fn () => $this->inWriteTransaction(false, $order, [$orderProduct, $foreignLine], function () use ($order, $customer, $orderProduct, $foreignLine): void {
                $this->assertLockableByAnotherSession($foreignLine, 'The line of another order was locked by the request that named it.');

                try {
                    $this->composer->priceRequestedLines($order, $customer, [
                        ['order_product' => $orderProduct, 'quantity' => 1.0],
                        ['order_product' => $foreignLine, 'quantity' => 1.0],
                    ]);
                } finally {
                    $this->assertLockableByAnotherSession($foreignLine, 'The check of the lines locked the line of another order.');
                }
            }),
        );
    }

    /**
     * The whole path, outside any test transaction: the ORDER_RETURN_CREATE
     * listener opens the outermost transaction itself, waits on the locks of
     * a competing return, and refuses once that return has committed.
     */
    public function testTheCreateListenerRefusesWhatACompetingReturnCommittedWhileItWaited(): void
    {
        [$order, $customer, [$orderProduct]] = $this->paidOrderWithReturnableLines(2.0);

        $this->startCompetingProcess($order, [[$orderProduct, 2.0]], includePostage: false);

        $this->assertRefusedOnceTheCompetingProcessCommitted(
            'The returned quantity exceeds the returnable quantity of this line.',
            'The create listener granted a unit a competing return took and committed while it waited.',
            fn () => $this->createReturnThroughTheListener($order, $customer, $orderProduct, 1.0),
        );

        self::assertSame(1, $this->committedReturnCount($order), 'Only the competing return may exist.');
    }

    public function testTheCreateListenerCommitsAReturnOtherSessionsSee(): void
    {
        [$order, $customer, [$orderProduct]] = $this->paidOrderWithReturnableLines(2.0);

        $this->createReturnThroughTheListener($order, $customer, $orderProduct, 1.0);

        self::assertFalse($this->mainConnection->inTransaction(), 'The listener left its transaction open.');
        self::assertSame(1, $this->committedReturnCount($order));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function lockConflicts(): iterable
    {
        yield 'deadlock' => [1213];
        yield 'row changed since the picture' => [1020];
    }

    /**
     * The database breaking a lock conflict is not a failure of the shop: the
     * request is refused with a message that invites a retry, nothing written.
     */
    #[DataProvider('lockConflicts')]
    public function testALockConflictComesBackAsARetryableRefusal(int $driverCode): void
    {
        [$order, , [$orderProduct]] = $this->paidOrderWithReturnableLines(1.0);

        try {
            $this->transaction->run((int) $order->getId(), [(int) $orderProduct->getId()], static function () use ($driverCode): never {
                $conflict = new \PDOException('SQLSTATE: lock conflict');
                $conflict->errorInfo = ['40001', $driverCode, 'lock conflict'];

                throw new PropelException('Unable to execute statement', 0, $conflict);
            });
            self::fail('The lock conflict was not turned into a refusal.');
        } catch (ReturnRequestConflictException $exception) {
            self::assertSame(ReturnRequestConflictException::MESSAGE, $exception->getMessage());
        }

        self::assertFalse($this->mainConnection->inTransaction(), 'The transaction of the refused request was left open.');
    }

    /**
     * A transaction opened on the PDO connection behind Propel's back would
     * make the beginTransaction() of the return a no-op, and the checks run
     * in no transaction of theirs. That is refused rather than guessed.
     */
    public function testATransactionOpenBehindPropelsBackIsRefused(): void
    {
        [$order, , [$orderProduct]] = $this->paidOrderWithReturnableLines(1.0);

        $pdo = $this->mainConnection->getWrappedConnection();
        $pdo->beginTransaction();

        try {
            $this->transaction->run((int) $order->getId(), [(int) $orderProduct->getId()], static fn (): null => null);
            self::fail('The return transaction ran over a transaction Propel does not know about.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('Propel counts', $exception->getMessage());
        } finally {
            $pdo->rollBack();
        }
    }

    /**
     * @param list<OrderProductModel> $orderProducts
     * @param callable(): mixed       $work
     */
    private function inWriteTransaction(bool $nested, Order $order, array $orderProducts, callable $work): mixed
    {
        $orderProductIds = array_map(static fn (OrderProductModel $orderProduct): int => (int) $orderProduct->getId(), $orderProducts);

        if (!$nested) {
            return $this->transaction->run((int) $order->getId(), $orderProductIds, static fn (): mixed => $work());
        }

        $this->mainConnection->beginTransaction();

        try {
            return $this->transaction->run((int) $order->getId(), $orderProductIds, static fn (): mixed => $work());
        } finally {
            $this->mainConnection->rollBack();
        }
    }

    /**
     * A caller transaction that has already made its first plain read - which
     * is what fixes its picture - when the competing return commits.
     *
     * @param callable(): mixed $work
     */
    private function inACallerTransactionThatAlreadyRead(Order $order, callable $work): mixed
    {
        $this->mainConnection->beginTransaction();

        try {
            OrderTableMap::clearInstancePool();
            self::assertNotNull(OrderQuery::create()->findPk($order->getId(), $this->mainConnection));

            $this->otherSessionConnection->commit();

            return $work();
        } finally {
            $this->mainConnection->rollBack();
        }
    }

    private function createReturnThroughTheListener(Order $order, Customer $customer, OrderProductModel $orderProduct, float $quantity): void
    {
        $return = (new OrderReturnModel())
            ->setOrder($order)
            ->setCustomer($customer)
            ->setCreatedByAdmin(false)
            ->setIncludePostage(false);
        $return->addOrderReturnLine((new OrderReturnLine())->setOrderProduct($orderProduct)->setQuantity($quantity));

        $this->getService(EventDispatcherInterface::class)->dispatch(new OrderReturnEvent($return), TheliaEvents::ORDER_RETURN_CREATE);
    }

    private function committedReturnCount(Order $order): int
    {
        return (int) $this->otherSessionConnection
            ->query('SELECT COUNT(*) FROM `order_return` WHERE `order_id` = '.(int) $order->getId())
            ->fetchColumn();
    }

    /**
     * @param callable(): mixed $attempt
     */
    private function assertRefused(string $expectedMessage, string $failure, callable $attempt): void
    {
        try {
            $attempt();
            self::fail($failure);
        } catch (ReturnNotAllowedException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());
        }
    }

    /**
     * assertRefused() for a request racing the competing process. Whether the
     * request really waited on that process is checked before its outcome: a
     * request that went through without waiting says nothing either way, and
     * has to fail as such rather than pass or fail for the wrong reason.
     *
     * @param callable(): mixed $attempt
     */
    private function assertRefusedOnceTheCompetingProcessCommitted(string $expectedMessage, string $failure, callable $attempt): void
    {
        $refusal = null;

        try {
            $attempt();
        } catch (ReturnNotAllowedException $exception) {
            $refusal = $exception->getMessage();
        }

        $this->finishCompetingProcess();

        self::assertNotNull($refusal, $failure);
        self::assertSame($expectedMessage, $refusal);
    }

    /**
     * @param callable(): mixed $attempt
     */
    private function expectLockWaitTimeout(string $failure, callable $attempt): void
    {
        try {
            $attempt();
            self::fail($failure);
        } catch (\PDOException $timeout) {
            self::assertStringContainsString('Lock wait timeout exceeded', $timeout->getMessage());
        }
    }

    /**
     * @param callable(): mixed $write
     */
    private function assertWritesWithoutWaiting(callable $write, string $failure): void
    {
        try {
            $write();
        } catch (\PDOException|PropelException $exception) {
            self::fail($failure.' ('.($exception->getPrevious() ?? $exception)->getMessage().')');
        } finally {
            if ($this->otherSessionConnection->inTransaction()) {
                $this->otherSessionConnection->rollBack();
            }
        }
    }

    private function assertLockableByAnotherSession(OrderProductModel $orderProduct, string $failure): void
    {
        $this->otherSessionConnection->beginTransaction();

        try {
            $this->lockOnOtherSession('order_product', (int) $orderProduct->getId());
        } catch (\PDOException $exception) {
            self::fail($failure.' ('.$exception->getMessage().')');
        } finally {
            $this->otherSessionConnection->rollBack();
        }
    }

    /**
     * A competing request on the second connection of this process: it locks
     * the lines, writes its return and stops short of committing.
     *
     * @param list<array{OrderProductModel, float}> $lines
     */
    private function openCompetingReturnOnOtherSession(Order $order, array $lines, bool $includePostage): void
    {
        $this->otherSessionConnection->beginTransaction();

        foreach ($lines as [$orderProduct]) {
            $this->lockOnOtherSession('order_product', (int) $orderProduct->getId());
        }

        $competingReturn = (new OrderReturnModel())
            ->setOrderId((int) $order->getId())
            ->setCustomerId((int) $order->getCustomerId())
            ->setStatusId($this->requestedStatusId())
            ->setIncludePostage($includePostage);
        $competingReturn->save($this->otherSessionConnection);

        foreach ($lines as [$orderProduct, $quantity]) {
            (new OrderReturnLine())
                ->setOrderReturnId((int) $competingReturn->getId())
                ->setOrderProductId((int) $orderProduct->getId())
                ->setProductSaleElementsId($orderProduct->getProductSaleElementsId())
                ->setQuantity($quantity)
                ->save($this->otherSessionConnection);
        }
    }

    /**
     * A competing request in a process of its own. It locks what a return
     * transaction locks, in the same order, writes its return and says so;
     * it commits once it sees the session of this process blocked on those
     * locks, and says that too - or, having never seen it, rolls back and
     * says so, which finishCompetingProcess() turns into a failure.
     *
     * @param list<array{OrderProductModel, float}> $lines
     */
    private function startCompetingProcess(Order $order, array $lines, bool $includePostage): void
    {
        $script = <<<'PHP'
            <?php
            $c = json_decode((string) getenv('COMPETING_RETURN'), true, flags: JSON_THROW_ON_ERROR);
            $pdo = new PDO($c['dsn'], $c['user'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('SET SESSION innodb_lock_wait_timeout = '.(int) $c['patience']);
            $pdo->beginTransaction();
            $lock = static function (string $table, int $id) use ($pdo): void {
                $statement = $pdo->prepare(sprintf('SELECT `id` FROM `%s` WHERE `id` = ? FOR UPDATE', $table));
                $statement->execute([$id]);
                $statement->closeCursor();
            };
            $lock('order', $c['order_id']);
            $ids = array_column($c['lines'], 'order_product_id');
            sort($ids);
            foreach ($ids as $id) {
                $lock('order_product', $id);
            }
            $pdo->prepare('INSERT INTO `order_return` (`order_id`, `customer_id`, `status_id`, `include_postage`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, NOW(), NOW())')
                ->execute([$c['order_id'], $c['customer_id'], $c['status_id'], $c['include_postage'] ? 1 : 0]);
            $returnId = (int) $pdo->lastInsertId();
            foreach ($c['lines'] as $line) {
                $pdo->prepare('INSERT INTO `order_return_line` (`order_return_id`, `order_product_id`, `product_sale_elements_id`, `quantity`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, NOW(), NOW())')
                    ->execute([$returnId, $line['order_product_id'], $line['product_sale_elements_id'], $line['quantity']]);
            }
            fwrite(STDOUT, "ready\n");
            fflush(STDOUT);

            // The request under test locks the order row first, which this
            // process holds: once its session is seen running a FOR UPDATE, it
            // is blocked on this process and on nothing else. A session sees
            // the threads of its own account without the PROCESS privilege.
            $blocked = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = ? AND COMMAND IN ('Query', 'Execute') AND INFO LIKE '%FOR UPDATE%'"
            );
            $deadline = microtime(true) + $c['patience'];
            $seen = false;
            while (!$seen && microtime(true) < $deadline) {
                $blocked->execute([$c['waiting_thread_id']]);
                $seen = (int) $blocked->fetchColumn() > 0;
                $blocked->closeCursor();

                if (!$seen) {
                    usleep(10000);
                }
            }

            if (!$seen) {
                $pdo->rollBack();
                fwrite(STDOUT, "the request under test never waited\n");
                exit(3);
            }

            $pdo->commit();
            fwrite(STDOUT, "committed while the request under test waited\n");
            PHP;

        $configuration = json_encode([
            'dsn' => $this->dsn(),
            'user' => $_SERVER['DATABASE_USER'],
            'password' => $_SERVER['DATABASE_PASSWORD'],
            'order_id' => (int) $order->getId(),
            'customer_id' => (int) $order->getCustomerId(),
            'status_id' => $this->requestedStatusId(),
            'include_postage' => $includePostage,
            'patience' => self::COMPETING_PATIENCE_SECONDS,
            'waiting_thread_id' => (int) $this->mainConnection->query('SELECT CONNECTION_ID()')->fetchColumn(),
            'lines' => array_map(static fn (array $line): array => [
                'order_product_id' => (int) $line[0]->getId(),
                'product_sale_elements_id' => $line[0]->getProductSaleElementsId(),
                'quantity' => $line[1],
            ], $lines),
        ], \JSON_THROW_ON_ERROR);

        $process = proc_open(
            [\PHP_BINARY],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['COMPETING_RETURN' => $configuration, 'PATH' => (string) getenv('PATH')],
        );
        self::assertIsResource($process, 'The competing process could not be started.');

        $this->competingProcess = $process;
        $this->competingPipes = $pipes;

        fwrite($pipes[0], $script);
        fclose($pipes[0]);

        $read = [$pipes[1]];
        $none = [];
        $ready = stream_select($read, $none, $none, self::COMPETING_PATIENCE_SECONDS) > 0 ? fgets($pipes[1]) : false;

        if ("ready\n" !== $ready) {
            self::fail('The competing process did not take its locks: '.stream_get_contents($pipes[2]));
        }

        // The request under test is about to wait on those locks until the
        // competing process sees it waiting and commits: long enough for a
        // slow runner.
        $this->mainConnection->exec('SET SESSION innodb_lock_wait_timeout = '.self::LOCK_WAIT_TIMEOUT_AGAINST_A_PROCESS);
    }

    private function finishCompetingProcess(): void
    {
        $output = (string) stream_get_contents($this->competingPipes[1]);
        $errors = (string) stream_get_contents($this->competingPipes[2]);
        $exitCode = $this->stopCompetingProcess();

        $this->mainConnection->exec('SET SESSION innodb_lock_wait_timeout = '.self::LOCK_WAIT_TIMEOUT);

        // Without this, a request that went through before the competing one
        // committed - never waiting at all - would pass for one that waited.
        self::assertStringContainsString(
            'committed while the request under test waited',
            $output,
            'The request under test never waited on the competing process, so the test proved nothing: '.$output.$errors,
        );
        self::assertSame(0, $exitCode, 'The competing process failed: '.$errors);
    }

    private function stopCompetingProcess(): ?int
    {
        if (null === $this->competingProcess) {
            return null;
        }

        foreach ($this->competingPipes as $index => $pipe) {
            if (0 !== $index && \is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $exitCode = proc_close($this->competingProcess);
        $this->competingProcess = null;
        $this->competingPipes = [];

        return $exitCode;
    }

    private function lockOnOtherSession(string $table, int $id): void
    {
        $lock = $this->otherSessionConnection->prepare(\sprintf('SELECT id FROM `%s` WHERE id = :id FOR UPDATE', $table));
        $lock->execute([':id' => $id]);
        $lock->closeCursor();
    }

    private function requestedStatusId(): int
    {
        $status = OrderReturnStatusQuery::create()->findOneByCode(OrderReturnStatus::CODE_REQUESTED);
        self::assertNotNull($status, "Seeded order return status 'requested' is missing - run bin/test-prepare.");

        return (int) $status->getId();
    }

    private function dsn(): string
    {
        return \sprintf(
            'mysql:host=%s;port=%s;dbname=%s',
            $_SERVER['DATABASE_HOST'],
            $_SERVER['DATABASE_PORT'] ?? '3306',
            $_SERVER['DATABASE_NAME'],
        );
    }

    /**
     * Each fixture id is kept the moment its row exists, so a failure halfway
     * through leaves nothing committed behind.
     *
     * @return array{Order, Customer, list<OrderProductModel>}
     */
    private function paidOrderWithReturnableLines(float ...$quantities): array
    {
        $connection = $this->getPropelConnection();

        $customer = $this->factory->customer($this->factory->customerTitle());
        $this->customerIds[] = (int) $customer->getId();

        $invoiceAddress = $this->factory->orderAddress();
        $this->orderAddressIds[] = (int) $invoiceAddress->getId();
        $deliveryAddress = $this->factory->orderAddress();
        $this->orderAddressIds[] = (int) $deliveryAddress->getId();

        $cart = $this->factory->cart($customer);
        $this->cartIds[] = (int) $cart->getId();

        $paidStatus = OrderStatusQuery::create()->findOneByCode(OrderStatus::CODE_PAID);
        self::assertNotNull($paidStatus, "Seeded order status 'paid' is missing - run bin/test-prepare.");

        $deliveryModule = ModuleQuery::create()->findOneByCode('CustomDelivery');
        $paymentModule = ModuleQuery::create()->findOneByCode('Cheque');
        self::assertNotNull($deliveryModule, 'No delivery module installed - run bin/test-prepare.');
        self::assertNotNull($paymentModule, 'No payment module installed - run bin/test-prepare.');

        // Built here rather than by FixtureFactory::order(), which creates the
        // addresses and the cart itself and would leave them behind if the
        // order failed to save.
        $order = (new Order())
            ->setCustomer($customer)
            ->setInvoiceOrderAddressId($invoiceAddress->getId())
            ->setDeliveryOrderAddressId($deliveryAddress->getId())
            ->setCurrencyId($this->factory->currency()->getId())
            ->setCurrencyRate(1.0)
            ->setPaymentModuleId($paymentModule->getId())
            ->setDeliveryModuleId($deliveryModule->getId())
            ->setStatusId($paidStatus->getId())
            ->setLangId($this->factory->lang()->getId())
            ->setCartId($cart->getId())
            ->setPostage('5')
            ->setPostageTax('0')
            ->setCreatedAt(new \DateTime('-1 day'));
        $order->save($connection);
        $this->orderIds[] = (int) $order->getId();

        $orderProducts = [];
        foreach ($quantities as $quantity) {
            $orderProduct = (new OrderProductModel())
                ->setOrderId((int) $order->getId())
                ->setProductRef('REF-'.uniqid())
                ->setProductSaleElementsRef('PSE-'.uniqid())
                ->setProductSaleElementsId(1)
                ->setTitle('A returnable product')
                ->setQuantity($quantity)
                ->setPrice('10.000000')
                ->setPromoPrice('10.000000')
                ->setWasNew(1)
                ->setWasInPromo(0)
                ->setVirtual(0);
            $orderProduct->save($connection);
            $this->orderProductIds[] = (int) $orderProduct->getId();
            $orderProducts[] = $orderProduct;
        }

        return [$order, $customer, $orderProducts];
    }

    /**
     * Manual cleanup, in FK order: nothing here rolls back on its own, since
     * $useTransaction is off.
     */
    private function deleteFixtures(): void
    {
        $connection = $this->getPropelConnection();

        foreach ($this->orderProductIds as $orderProductId) {
            $connection->exec('DELETE FROM `order_return_line` WHERE `order_product_id` = '.$orderProductId);
        }

        foreach ($this->orderIds as $orderId) {
            $connection->exec('DELETE FROM `order_return` WHERE `order_id` = '.$orderId);
        }

        foreach ($this->orderProductIds as $orderProductId) {
            $connection->exec('DELETE FROM `order_product` WHERE `id` = '.$orderProductId);
        }

        foreach ($this->orderIds as $orderId) {
            $connection->exec('DELETE FROM `order` WHERE `id` = '.$orderId);
        }

        foreach ($this->cartIds as $cartId) {
            $connection->exec('DELETE FROM `cart` WHERE `id` = '.$cartId);
        }

        foreach ($this->orderAddressIds as $addressId) {
            $connection->exec('DELETE FROM `order_address` WHERE `id` = '.$addressId);
        }

        foreach ($this->customerIds as $customerId) {
            $connection->exec('DELETE FROM `customer` WHERE `id` = '.$customerId);
        }
    }
}
