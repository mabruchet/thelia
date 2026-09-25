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

namespace Thelia\Tests\Support\Trait;

use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Connection\ConnectionWrapper;
use Propel\Runtime\Connection\PdoConnection;

/**
 * A second, genuinely independent database session, and the manual, FK-ordered
 * cleanup of the return fixtures a test commits for real to use it.
 *
 * The transaction IntegrationTestCase/WebIntegrationTestCase wraps around a
 * test and rolls back in tearDown() never blocks a second `SELECT ... FOR
 * UPDATE` on rows it already holds - a MySQL session always re-acquires its
 * own locks. Proving a lock holds across two requests needs a session of its
 * own, which only sees what the test has actually committed: the fixtures it
 * commits for real have to be cleaned up by hand, since nothing rolls them
 * back on their own.
 */
trait SharesSecondDatabaseSession
{
    /**
     * The DSN of a second, genuinely independent database session: the one
     * IntegrationTestCase/WebIntegrationTestCase and the request under test
     * share is wrapped in the transaction the test rolls back.
     */
    private function secondSessionDsn(): string
    {
        return \sprintf(
            'mysql:host=%s;port=%s;dbname=%s',
            $_SERVER['DATABASE_HOST'],
            $_SERVER['DATABASE_PORT'] ?? '3306',
            $_SERVER['DATABASE_NAME'],
        );
    }

    private function openSecondSession(): PdoConnection
    {
        return new PdoConnection($this->secondSessionDsn(), $_SERVER['DATABASE_USER'], $_SERVER['DATABASE_PASSWORD']);
    }

    private function openSecondSessionWrapped(): ConnectionWrapper
    {
        return new ConnectionWrapper($this->openSecondSession());
    }

    /**
     * Deletes, in FK order, the return fixtures a test committed for real:
     * nothing here rolls back on its own, since it was written outside - or
     * with - the transaction the test itself rolls back.
     *
     * @param list<int> $orderProductIds
     * @param list<int> $orderIds
     * @param list<int> $cartIds
     * @param list<int> $orderAddressIds
     * @param list<int> $customerIds
     */
    private function deleteReturnFixtures(
        ConnectionInterface $connection,
        array $orderProductIds,
        array $orderIds,
        array $cartIds = [],
        array $orderAddressIds = [],
        array $customerIds = [],
    ): void {
        foreach ($orderProductIds as $orderProductId) {
            $connection->exec('DELETE FROM `order_return_line` WHERE `order_product_id` = '.$orderProductId);
        }

        foreach ($orderIds as $orderId) {
            $connection->exec('DELETE FROM `order_return` WHERE `order_id` = '.$orderId);
        }

        foreach ($orderProductIds as $orderProductId) {
            $connection->exec('DELETE FROM `order_product` WHERE `id` = '.$orderProductId);
        }

        foreach ($orderIds as $orderId) {
            $connection->exec('DELETE FROM `order` WHERE `id` = '.$orderId);
        }

        foreach ($cartIds as $cartId) {
            $connection->exec('DELETE FROM `cart` WHERE `id` = '.$cartId);
        }

        foreach ($orderAddressIds as $addressId) {
            $connection->exec('DELETE FROM `order_address` WHERE `id` = '.$addressId);
        }

        foreach ($customerIds as $customerId) {
            $connection->exec('DELETE FROM `customer` WHERE `id` = '.$customerId);
        }
    }
}
