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

namespace Thelia\Tests\Http\Flexy;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\RouterInterface;
use Thelia\Model\Customer;
use Thelia\Model\Order;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\Flexy\CustomerSessionInjector;

/**
 * The order list of the account: the customer's own orders only, and a page of them
 * whatever the page number of the query string holds, never an error.
 */
final class AccountOrdersListTest extends WebIntegrationTestCase
{
    private ?CustomerSessionInjector $injector = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->injector = new CustomerSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
    }

    protected function tearDown(): void
    {
        $this->injector?->clear();

        parent::tearDown();
    }

    public function testAnotherCustomersOrderIsNotListed(): void
    {
        [$customer, $order] = $this->customerWithOneOrder();
        [, $someoneElses] = $this->customerWithOneOrder();

        $this->injector?->setCustomer($customer);
        $page = $this->ordersPage('1');

        self::assertStringContainsString((string) $order->getRef(), $page);
        self::assertStringNotContainsString((string) $someoneElses->getRef(), $page);
    }

    public function testAHugePageIsServedAsTheLast(): void
    {
        [$customer, $order] = $this->customerWithOneOrder();

        $this->injector?->setCustomer($customer);

        // Six orders a page: the offset of the largest integer page no longer fits one.
        self::assertStringContainsString((string) $order->getRef(), $this->ordersPage((string) \PHP_INT_MAX));
        self::assertStringContainsString((string) $order->getRef(), $this->ordersPage('999999999999999999'));
    }

    public function testAPageThatIsNotANumberIsServedAsTheFirst(): void
    {
        [$customer, $order] = $this->customerWithOneOrder();

        $this->injector?->setCustomer($customer);

        self::assertStringContainsString((string) $order->getRef(), $this->ordersPage('abc'));
        self::assertStringContainsString((string) $order->getRef(), $this->ordersPage(['1']));
    }

    /**
     * @param string|list<string> $page
     */
    private function ordersPage(string|array $page): string
    {
        $url = $this->getService(RouterInterface::class)->generate('account_orders', ['page' => $page]);
        $crawler = $this->client->request('GET', $url);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $url);

        return $crawler->filter('body')->text();
    }

    /**
     * @return array{0: Customer, 1: Order}
     */
    private function customerWithOneOrder(): array
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $customer = $factory->customer($factory->customerTitle());

        return [$customer, $factory->order($customer, ['statusCode' => OrderStatus::CODE_PAID])];
    }
}
