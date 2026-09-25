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

namespace Thelia\Tests\Api;

use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\Order;
use Thelia\Model\OrderProduct as OrderProductModel;
use Thelia\Model\OrderStatus;
use Thelia\Test\ApiTestCase;
use Thelia\Test\FixtureFactory;
use Thelia\Test\Trait\RecordsSqlQueries;
use Thelia\Tests\Support\Trait\SharesSecondDatabaseSession;

/**
 * A POST opening a return locks its order and its lines once, through
 * OrderReturnWriteTransaction::run(). ReturnEligibilityChecker::lockLines()
 * asks for the very same rows a moment later: OrderReturnWriteTransaction::lock()
 * has to be a no-op then, or every request pays a second, redundant round trip
 * to the database per return.
 *
 * Proving it needs the request to be genuinely outermost: the transaction
 * ApiTestCase wraps around every other test would make this run() nested in
 * it instead, where the short-circuit never applies (see
 * OrderReturnWriteTransaction::lock()). This test commits its fixtures for
 * real and cleans them up itself, exactly as OrderReturnConcurrencyTest does
 * for the same reason.
 */
final class OrderReturnLockCountTest extends ApiTestCase
{
    use RecordsSqlQueries;
    use SharesSecondDatabaseSession;

    protected bool $useTransaction = false;

    private FixtureFactory $factory;

    private ?string $previousEnabled = null;

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

        $this->factory = $this->createFixtureFactory();
        $this->previousEnabled = ConfigQuery::read(ReturnEligibilityChecker::ENABLED_CONFIG_KEY, '0');
        ConfigQuery::write(ReturnEligibilityChecker::ENABLED_CONFIG_KEY, '1');
    }

    protected function tearDown(): void
    {
        try {
            $this->deleteReturnFixtures(
                $this->getPropelConnection(),
                $this->orderProductIds,
                $this->orderIds,
                $this->cartIds,
                $this->orderAddressIds,
                $this->customerIds,
            );
        } finally {
            if (null !== $this->previousEnabled) {
                ConfigQuery::write(ReturnEligibilityChecker::ENABLED_CONFIG_KEY, $this->previousEnabled);
            }

            ConfigQuery::resetCache();

            parent::tearDown();
        }
    }

    public function testAReturnLocksItsOrderAndItsLineOnlyOnce(): void
    {
        [$order, $orderProduct, $customer] = $this->paidOrderWithProduct();
        $token = $this->authenticateAsCustomer($customer);

        $response = null;
        $statements = $this->recordSqlQueries(function () use (&$response, $order, $orderProduct, $token): void {
            $response = $this->jsonRequest(
                'POST',
                '/api/front/account/order_returns',
                [
                    'order' => '/api/front/account/orders/'.$order->getId(),
                    'orderReturnLines' => [
                        ['orderProduct' => '/api/front/account/order_products/'.$orderProduct->getId(), 'quantity' => 1.0],
                    ],
                ],
                token: $token,
            );
        });

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $lockingStatements = array_values(array_filter(
            $statements,
            static fn (string $statement): bool => str_contains($statement, 'FOR UPDATE'),
        ));

        self::assertCount(
            2,
            $lockingStatements,
            'One line must lock its order and its own row once each - the eligibility check must not repeat a lock run() already holds: '.implode(' | ', $lockingStatements),
        );
    }

    /**
     * @return array{0: Order, 1: OrderProductModel, 2: Customer}
     */
    private function paidOrderWithProduct(): array
    {
        $customer = $this->factory->customer($this->factory->customerTitle());
        $this->customerIds[] = (int) $customer->getId();

        $order = $this->factory->order($customer, ['statusCode' => OrderStatus::CODE_PAID]);
        $this->orderIds[] = (int) $order->getId();
        $this->cartIds[] = (int) $order->getCartId();
        $this->orderAddressIds[] = (int) $order->getInvoiceOrderAddressId();
        $this->orderAddressIds[] = (int) $order->getDeliveryOrderAddressId();

        $orderProduct = (new OrderProductModel())
            ->setOrderId((int) $order->getId())
            ->setProductRef('REF-'.uniqid())
            ->setProductSaleElementsRef('PSE-'.uniqid())
            ->setProductSaleElementsId(1)
            ->setTitle('A returnable product')
            ->setQuantity(1.0)
            ->setPrice('10.000000')
            ->setPromoPrice('10.000000')
            ->setWasNew(1)
            ->setWasInPromo(0)
            ->setVirtual(0);
        $orderProduct->save($this->getPropelConnection());
        $this->orderProductIds[] = (int) $orderProduct->getId();

        return [$order, $orderProduct, $customer];
    }
}
