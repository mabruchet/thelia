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
use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\OrderReturn;
use Thelia\Model\OrderReturnStatus;
use Thelia\Model\OrderReturnStatusQuery;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\Flexy\CustomerSessionInjector;

/**
 * The page listing every return of the signed-in customer: their own returns and no one
 * else's, an empty state when there are none, and no page at all when the shop does not
 * offer returns.
 *
 * The page belongs to the theme, which ships on its own release cycle: a theme older
 * than this route is reported as skipped rather than failed.
 */
final class AccountReturnsListTest extends WebIntegrationTestCase
{
    private const ROUTE = 'account_returns';

    private ?CustomerSessionInjector $injector = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (null === $this->getService(RouterInterface::class)->getRouteCollection()->get(self::ROUTE)) {
            self::markTestSkipped('The installed front-office theme has no returns list.');
        }

        ConfigQuery::write(ReturnEligibilityChecker::ENABLED_CONFIG_KEY, '1');

        $this->injector = new CustomerSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
    }

    protected function tearDown(): void
    {
        $this->injector?->clear();
        // The written setting goes with the transaction rollback; the static cache of
        // ConfigQuery does not.
        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testTheCustomerSeesTheirOwnReturnsOnly(): void
    {
        $customer = $this->customer();
        $first = $this->returnOf($customer);
        $second = $this->returnOf($customer);
        $someoneElses = $this->returnOf($this->customer());

        $this->injector?->setCustomer($customer);
        $crawler = $this->client->request('GET', $this->url(self::ROUTE));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $trackingLinks = $crawler->filter('a[href*="/account/return/"]')->each(
            static fn ($link): string => (string) $link->attr('href'),
        );
        sort($trackingLinks);

        $expected = [
            $this->url('account_return', ['returnId' => $first->getId()]),
            $this->url('account_return', ['returnId' => $second->getId()]),
        ];
        sort($expected);

        self::assertSame($expected, $trackingLinks, 'Each of the customer\'s returns links to its tracking page, and nothing else does.');

        $body = $crawler->filter('body')->text();
        self::assertStringContainsString((string) $first->getRef(), $body);
        self::assertStringContainsString((string) $first->getOrder()->getRef(), $body, 'Each return names the order it belongs to.');
        self::assertStringNotContainsString((string) $someoneElses->getRef(), $body, 'Another customer\'s return must not be listed.');
        self::assertCount(2, $crawler->filter('.OrderReturnTag'), 'Each return shows its status.');
    }

    public function testACustomerWithoutReturnsSeesTheEmptyState(): void
    {
        $this->returnOf($this->customer());

        $this->injector?->setCustomer($this->customer());
        $crawler = $this->client->request('GET', $this->url(self::ROUTE));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('a[href*="/account/return/"]'));
        self::assertStringContainsString('You have no returns yet', $crawler->filter('body')->text());
    }

    public function testThePageIsGoneWhenTheFeatureIsDisabled(): void
    {
        $customer = $this->customer();
        $this->returnOf($customer);
        ConfigQuery::write(ReturnEligibilityChecker::ENABLED_CONFIG_KEY, '0');

        $this->injector?->setCustomer($customer);
        $this->client->request('GET', $this->url(self::ROUTE));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testAVisitorIsSentToSignIn(): void
    {
        $this->client->request('GET', $this->url(self::ROUTE));

        self::assertResponseRedirects();
        self::assertStringContainsString(
            $this->url('customer_login'),
            (string) $this->client->getResponse()->headers->get('Location'),
        );
    }

    /**
     * The return templates are not pages of their own: named through the front-office
     * catch-all, they would render without their controller's customer check.
     */
    public function testTheReturnTemplatesAreNotReachableByName(): void
    {
        $this->injector?->setCustomer($this->customer());

        foreach (['/account-returns', '/account-return', '/account-order-return'] as $view) {
            $this->client->request('GET', $view);

            self::assertSame(404, $this->client->getResponse()->getStatusCode(), $view.' must not render by name.');
        }
    }

    /**
     * A trailing slash must not land on another template: it reaches this same controller,
     * guard included, or it leads back to the list.
     */
    public function testTheTrailingSlashFormServesTheSamePage(): void
    {
        $customer = $this->customer();
        $return = $this->returnOf($customer);

        $this->injector?->setCustomer($customer);
        $crawler = $this->client->request('GET', $this->url(self::ROUTE).'/');
        $response = $this->client->getResponse();

        if ($response->isRedirection()) {
            self::assertSame($this->url(self::ROUTE), parse_url((string) $response->headers->get('Location'), \PHP_URL_PATH));

            return;
        }

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $crawler->filter('a[href="'.$this->url('account_return', ['returnId' => $return->getId()]).'"]'));

        ConfigQuery::write(ReturnEligibilityChecker::ENABLED_CONFIG_KEY, '0');
        $this->client->request('GET', $this->url(self::ROUTE).'/');

        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'The trailing slash form must go through the same guard.');
    }

    public function testThePageIsNeverStoredInASharedCache(): void
    {
        $this->injector?->setCustomer($this->customer());
        $this->client->request('GET', $this->url(self::ROUTE));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue(
            $this->client->getResponse()->headers->hasCacheControlDirective('private'),
            'An account page must be private: '.$this->client->getResponse()->headers->get('Cache-Control'),
        );
    }

    public function testTheListIsPagedNewestFirst(): void
    {
        [$customer, $returns] = $this->elevenReturns();

        $this->injector?->setCustomer($customer);

        self::assertSame(
            array_map(fn (OrderReturn $return): string => $this->trackingUrl($return), \array_slice($returns, 0, 10)),
            $this->listedReturns(['page' => '1']),
            'The first page lists the ten newest returns, newest first.',
        );
        self::assertSame([$this->trackingUrl($returns[10])], $this->listedReturns(['page' => '2']));
    }

    public function testAPagePastTheLastIsServedAsTheLast(): void
    {
        [$customer, $returns] = $this->elevenReturns();

        $this->injector?->setCustomer($customer);

        foreach (['99', '999999999999999999', (string) \PHP_INT_MAX] as $page) {
            self::assertSame([$this->trackingUrl($returns[10])], $this->listedReturns(['page' => $page]));
            self::assertSame('2', $this->client->getCrawler()->filter('.Pagination-item--active')->text(), 'page='.$page.' is marked as the last page.');
        }
    }

    public function testAPageThatIsNotANumberIsServedAsTheFirst(): void
    {
        [$customer, $returns] = $this->elevenReturns();

        $this->injector?->setCustomer($customer);

        self::assertSame(
            array_map(fn (OrderReturn $return): string => $this->trackingUrl($return), \array_slice($returns, 0, 10)),
            $this->listedReturns(['page' => 'abc']),
        );
        self::assertSame(
            array_map(fn (OrderReturn $return): string => $this->trackingUrl($return), \array_slice($returns, 0, 10)),
            $this->listedReturns(['page' => ['1']]),
            'page[]=1 is not a page number either.',
        );
    }

    /**
     * Eleven returns of one customer, one day apart, handed back newest first. They are
     * written oldest first, so that ordering by id would not pass for ordering by date.
     *
     * @return array{0: Customer, 1: list<OrderReturn>}
     */
    private function elevenReturns(): array
    {
        $customer = $this->customer();
        $returns = [];

        for ($daysAgo = 10; $daysAgo >= 0; --$daysAgo) {
            $return = $this->returnOf($customer);
            $return->setCreatedAt(new \DateTime(\sprintf('-%d days', $daysAgo)))->save($this->getPropelConnection());
            $returns[] = $return;
        }

        return [$customer, array_reverse($returns)];
    }

    /**
     * The tracking links of the page, in the order it lists them.
     *
     * @param array<string, string|list<string>> $query
     *
     * @return list<string>
     */
    private function listedReturns(array $query): array
    {
        $crawler = $this->client->request('GET', $this->url(self::ROUTE).'?'.http_build_query($query));

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), http_build_query($query));

        return $crawler->filter('a[href*="/account/return/"]')->each(
            static fn ($link): string => (string) $link->attr('href'),
        );
    }

    private function trackingUrl(OrderReturn $return): string
    {
        return $this->url('account_return', ['returnId' => $return->getId()]);
    }

    private function returnOf(Customer $customer): OrderReturn
    {
        $order = $this->factory()->order($customer, ['statusCode' => OrderStatus::CODE_PAID]);

        $return = (new OrderReturn())
            ->setOrder($order)
            ->setCustomer($customer)
            ->setOrderReturnStatus(OrderReturnStatusQuery::create()->findOneByCode(OrderReturnStatus::CODE_REQUESTED))
            ->setExpectedResolution(OrderReturn::RESOLUTION_REFUND);
        $return->save($this->getPropelConnection());

        return $return;
    }

    private function customer(): Customer
    {
        return $this->factory()->customer($this->factory()->customerTitle());
    }

    /**
     * @param array<string, int|string|null> $parameters
     */
    private function url(string $route, array $parameters = []): string
    {
        return $this->getService(RouterInterface::class)->generate($route, $parameters);
    }

    /**
     * Built without createFixtureFactory(): that helper pushes a synthetic request when the
     * stack is empty, and it would then be the "main" request of the calls below.
     */
    private function factory(): FixtureFactory
    {
        return new FixtureFactory($this->getPropelConnection());
    }
}
