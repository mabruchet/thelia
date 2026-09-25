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

namespace Thelia\Domain\OrderReturn\Service;

use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Connection\ConnectionWrapper;
use Propel\Runtime\Propel;
use Thelia\Config\DatabaseConfiguration;
use Thelia\Domain\OrderReturn\Exception\ReturnRequestConflictException;
use Thelia\Model\Map\OrderProductTableMap;
use Thelia\Model\Map\OrderTableMap;

/**
 * The transaction a return is written in: every path that opens a return or
 * changes a returned quantity runs its check and its write through here, and
 * names up front the order and the order product lines it is about.
 *
 * Locks first. The rows are locked before the transaction reads anything,
 * always in the same order: the `order` row, then the `order_product` rows by
 * increasing id. Under REPEATABLE READ, InnoDB takes the picture every plain
 * read of a transaction returns at its first plain read, not at its start; a
 * `SELECT ... FOR UPDATE` does not take it. Locking first therefore means the
 * picture is taken once the locks are held, after the commit of any request
 * that held them: the plain reads that follow see every return written on
 * these lines and this order, and a request that waited cannot be granted
 * the units, or the postage, the one before it took. The locks are on primary
 * keys, so they hold those rows and nothing around them, and one order for
 * every path means two requests cannot each hold what the other waits for.
 *
 * Nested in a transaction someone else opened, the picture may already be
 * taken: the rows are still locked, in the same order, but readsSeeCommits()
 * says no, and ReturnEligibilityChecker falls back to locking reads. Those lock
 * index gaps and may deadlock, or on MariaDB 11.6 and later run into a row
 * changed since the picture; both come back as ReturnRequestConflictException,
 * a refusal the caller can ask to retry, never a unit granted twice.
 *
 * A constructor default of `= new self()` - every consumer typed on
 * {@see OrderReturnWriteTransactionInterface} carries one - builds an instance
 * of its own rather than reusing the one the container wires as a singleton.
 * That instance shares no state with it: readsSeeCommits() answers false on it
 * whatever the caller's own transaction has already read, so
 * ReturnEligibilityChecker always falls back to a locking read. It is never
 * wrong - a locking read is the fallback this class exists to provide - but it
 * gives up the plain read the caller's transaction would otherwise be entitled
 * to, and the row lock and the wait that comes with it. A caller that wants
 * the state the container's instance holds takes it through autowiring,
 * without supplying its own default.
 */
final class OrderReturnWriteTransaction implements OrderReturnWriteTransactionInterface
{
    private const ER_CHECKREAD = 1020;
    private const ER_LOCK_DEADLOCK = 1213;

    private bool $readsSeeCommits = false;

    /**
     * Run $work in the transaction, the rows locked first; commit it, or roll
     * it back and rethrow.
     *
     * @template T
     *
     * @param list<int>                        $orderProductIds
     * @param callable(ConnectionInterface): T $work
     *
     * @return T
     *
     * @throws ReturnRequestConflictException when the database gave up on the transaction to break a lock conflict
     */
    #[\Override]
    public function run(int $orderId, array $orderProductIds, callable $work): mixed
    {
        $connection = Propel::getWriteConnection(DatabaseConfiguration::THELIA_CONNECTION_NAME);
        $outermost = $this->isOutsideAnyTransaction($connection);

        // Read before the transaction opens, so this read is not the one that
        // fixes the picture of the transaction.
        $orderProductIds = $this->linesOfOrder($orderId, $orderProductIds);

        $connection->beginTransaction();

        if (!$connection->inTransaction()) {
            throw new \LogicException('The return transaction did not start: the checks would run in autocommit, where no lock holds.');
        }

        $previousReadsSeeCommits = $this->readsSeeCommits;

        try {
            $this->lockRows($orderId, $orderProductIds);

            // Outermost: the picture read from here on is taken after these
            // locks, so a plain read may be trusted. Nested in a transaction a
            // caller opened itself: whatever the outer run() already set, the
            // lines just locked here are not covered by the picture the outer
            // transaction may have already taken - only a locking read sees
            // them, so the flag is forced down for the length of $work.
            $this->readsSeeCommits = $outermost;

            $result = $work($connection);
            $connection->commit();

            return $result;
        } catch (\Throwable $exception) {
            $connection->rollBack();

            if ($this->isLockConflict($exception)) {
                throw new ReturnRequestConflictException(ReturnRequestConflictException::MESSAGE, previous: $exception);
            }

            throw $exception;
        } finally {
            $this->readsSeeCommits = $previousReadsSeeCommits;
        }
    }

    /**
     * Lock the order and its lines until the end of the transaction in
     * progress, in the one order every path uses.
     *
     * The rows are also dropped from the Propel instance pool, so a model read
     * from here on is read after the lock, not handed back as it was loaded
     * before it - the order status included.
     *
     * The ids come from the request: only those of lines of this order are
     * locked, see linesOfOrder(). That takes a plain read, so a transaction
     * that has not read yet goes through run(), which makes it before the
     * transaction opens.
     *
     * A no-op while readsSeeCommits() is true: that is only ever the case
     * inside the $work of the outermost run() for this same order, which has
     * already taken every lock this method would take again - a second round
     * trip to hold what is already held. Nested in a transaction a caller
     * opened, readsSeeCommits() is false and the lock is taken for real: it is
     * the only way those rows get locked at all.
     *
     * @param list<int> $orderProductIds
     */
    public function lock(int $orderId, array $orderProductIds): void
    {
        if ($this->readsSeeCommits) {
            return;
        }

        $this->lockRows($orderId, $this->linesOfOrder($orderId, $orderProductIds));
    }

    /**
     * The ids among $orderProductIds that are lines of the order. A request
     * naming the lines of somebody else's order would otherwise hold them for
     * as long as its transaction lasts, and keep their owner from opening a
     * return; they are left out here, and refused later by the eligibility
     * check like any line that is not the order's.
     *
     * It is a plain read on the primary key, which takes no lock: a locking
     * one filtered on order_id would scan the order_id index and lock the gap
     * the lines of the next orders are written in. A line never moves from
     * one order to another, so reading it outside the transaction is safe.
     *
     * @param list<int> $orderProductIds
     *
     * @return list<int>
     */
    private function linesOfOrder(int $orderId, array $orderProductIds): array
    {
        $orderProductIds = array_values(array_unique(array_map('intval', $orderProductIds)));

        if ([] === $orderProductIds) {
            return [];
        }

        $statement = Propel::getWriteConnection(DatabaseConfiguration::THELIA_CONNECTION_NAME)->prepare(\sprintf(
            'SELECT `id` FROM `%s` WHERE `id` IN (%s) AND `order_id` = ?',
            OrderProductTableMap::TABLE_NAME,
            implode(', ', array_fill(0, \count($orderProductIds), '?')),
        ));
        $statement->execute([...$orderProductIds, $orderId]);
        $ids = array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
        $statement->closeCursor();

        return $ids;
    }

    /**
     * @param list<int> $orderProductIds lines of the order, see linesOfOrder()
     */
    private function lockRows(int $orderId, array $orderProductIds): void
    {
        $this->lockRow(OrderTableMap::TABLE_NAME, $orderId);
        OrderTableMap::removeInstanceFromPool((string) $orderId);

        sort($orderProductIds);

        foreach ($orderProductIds as $orderProductId) {
            $this->lockRow(OrderProductTableMap::TABLE_NAME, $orderProductId);
            OrderProductTableMap::removeInstanceFromPool((string) $orderProductId);
        }
    }

    /**
     * Whether the transaction in progress is one this class opened, the rows
     * locked before its first plain read.
     */
    public function readsSeeCommits(): bool
    {
        return $this->readsSeeCommits;
    }

    /**
     * Propel counts the transactions it nests and PDO knows whether one is
     * open: both have to agree there is none. A transaction opened on the PDO
     * connection behind Propel's back, or one ended by an implicit commit
     * while Propel still counts it, would make the next beginTransaction()
     * either a no-op or a fresh transaction nobody meant: refusing is the only
     * answer that never leaves the checks in autocommit.
     */
    private function isOutsideAnyTransaction(ConnectionInterface $connection): bool
    {
        $openInPdo = $connection->inTransaction();
        $openInPropel = $connection instanceof ConnectionWrapper
            ? $connection->getNestedTransactionCount() > 0
            : $openInPdo;

        if ($openInPdo !== $openInPropel) {
            throw new \LogicException(\sprintf('The database connection is %s a transaction while Propel counts %s: the return cannot be written safely.', $openInPdo ? 'in' : 'out of', $openInPropel ? 'one' : 'none'));
        }

        return !$openInPdo;
    }

    private function isLockConflict(\Throwable $exception): bool
    {
        for ($current = $exception; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof \PDOException
                && \in_array((int) ($current->errorInfo[1] ?? 0), [self::ER_LOCK_DEADLOCK, self::ER_CHECKREAD], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `order` is a reserved word: the table name is always quoted.
     */
    private function lockRow(string $table, int $id): void
    {
        $statement = Propel::getWriteConnection(DatabaseConfiguration::THELIA_CONNECTION_NAME)
            ->prepare(\sprintf('SELECT `id` FROM `%s` WHERE `id` = :id FOR UPDATE', $table));
        $statement->execute([':id' => $id]);
        $statement->closeCursor();
    }
}
