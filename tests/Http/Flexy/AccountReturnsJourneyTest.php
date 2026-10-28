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

use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\OrderReturn\OrderReturnEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Order\Enum\OrderHistoryEventType;
use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Model\MessageQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderHistoryQuery;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderReturnQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\Flexy\CustomerSessionInjector;

/**
 * The returns of a customer, from the "My returns" page to the form that opens one.
 *
 * Complements AccountOrderReturnTest, which covers the order page button, the tracking
 * page and the return document: here the list is scoped to the signed-in customer, opening
 * a return leaves its line on the order history and mails the customer once, every refusal
 * writes nothing, a visitor who is not signed in is sent to the sign-in page, and a return
 * of somebody else is as absent as one that does not exist.
 *
 * The pages belong to the theme, which ships as its own package on its own release cycle: a
 * theme older than these routes is reported as skipped rather than failed.
 */
final class AccountReturnsJourneyTest extends WebIntegrationTestCase
{
    use MailerAssertionsTrait;

    private const LIST_ROUTE = 'account_returns';

    private const FORM_ROUTE = 'account_order_return_new';

    private const CREATE_ROUTE = 'account_order_return_create';

    private const TRACKING_ROUTE = 'account_return';

    private ?CustomerSessionInjector $injector = null;

    private ?string $previousEnabled = null;

    private ?string $previousWindow = null;

    private ?string $previousStoreEmail = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (null === $this->getService(RouterInterface::class)->getRouteCollection()->get(self::LIST_ROUTE)) {
            self::markTestSkipped('The installed front-office theme has no "My returns" page.');
        }

        $this->previousEnabled = ConfigQuery::read(ReturnEligibilityChecker::ENABLED_CONFIG_KEY);
        $this->previousWindow = ConfigQuery::read(ReturnEligibilityChecker::WINDOW_CONFIG_KEY);
        $this->previousStoreEmail = ConfigQuery::read('store_email');
        ConfigQuery::write(ReturnEligibilityChecker::ENABLED_CONFIG_KEY, '1');
        ConfigQuery::write(ReturnEligibilityChecker::WINDOW_CONFIG_KEY, '14');
        // A shop without a sender address sends nothing at all, and the test database is seeded without one.
        ConfigQuery::write('store_email', 'shop@test.com');

        $this->injector = new CustomerSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
    }

    protected function tearDown(): void
    {
        $this->injector?->clear();
        ConfigQuery::write(ReturnEligibilityChecker::ENABLED_CONFIG_KEY, (string) $this->previousEnabled);
        ConfigQuery::write(ReturnEligibilityChecker::WINDOW_CONFIG_KEY, (string) $this->previousWindow);
        ConfigQuery::write('store_email', (string) $this->previousStoreEmail);

        parent::tearDown();
    }

    public function testTheListIsEmptyForACustomerWithoutReturns(): void
    {
        $this->injector?->setCustomer($this->customer());

        $crawler = $this->client->request('GET', $this->url(self::LIST_ROUTE));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="order-return"]'));
        self::assertStringContainsString('You have no returns yet', $crawler->filter('body')->text());
    }

    public function testTheListShowsOnlyTheReturnsOfTheSignedInCustomer(): void
    {
        [$owner, $ownOrder, $ownLine] = $this->paidOrderWithOneLine(2.0);
        [$other, $otherOrder, $otherLine] = $this->paidOrderWithOneLine(2.0);

        $this->injector?->setCustomer($other);
        $this->submitReturn($otherOrder, $otherLine, '1');
        $otherReturn = OrderReturnQuery::create()->filterByOrderId($otherOrder->getId())->findOne();
        self::assertNotNull($otherReturn);

        $this->injector?->setCustomer($owner);
        $crawler = $this->client->request('GET', $this->url(self::LIST_ROUTE));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="order-return"]'), 'The return of somebody else is not on this list.');

        $this->submitReturn($ownOrder, $ownLine, '1');
        $ownReturn = OrderReturnQuery::create()->filterByOrderId($ownOrder->getId())->findOne();
        self::assertNotNull($ownReturn);

        $crawler = $this->client->request('GET', $this->url(self::LIST_ROUTE));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-testid="order-return"]'));
        self::assertStringContainsString((string) $ownReturn->getRef(), $crawler->filter('[data-testid="order-return"]')->text());
        self::assertStringNotContainsString((string) $otherReturn->getRef(), $crawler->filter('body')->text());
        self::assertCount(1, $crawler->filter('a[href$="/return/'.$ownReturn->getId().'"]'), 'Each return links to its tracking page.');
    }

    public function testOpeningAReturnWritesItsHistoryLineAndMailsTheCustomerOnce(): void
    {
        [$customer, $order, $line] = $this->paidOrderWithOneLine(2.0);
        $mailed = [];
        $this->getService(EventDispatcherInterface::class)->addListener(
            TheliaEvents::ORDER_RETURN_SEND_STATUS_EMAIL,
            static function (OrderReturnEvent $event) use (&$mailed): void {
                $mailed[] = (int) $event->getOrderReturn()->getId();
            },
            1024,
        );

        $this->injector?->setCustomer($customer);
        $this->submitReturn($order, $line, '1');

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $return = OrderReturnQuery::create()->filterByOrderId($order->getId())->findOne();
        self::assertNotNull($return);
        self::assertCount(1, $return->getOrderReturnLines());

        $history = OrderHistoryQuery::create()
            ->filterByOrderId((int) $order->getId())
            ->filterByEventType(OrderHistoryEventType::RETURN_OPENED->value)
            ->find();
        self::assertCount(1, $history, 'The return is on the history of its order, once.');
        self::assertStringContainsString((string) $return->getRef(), (string) $history->getFirst()->getPayload());

        self::assertSame([(int) $return->getId()], $mailed, 'The customer is announced the return once.');

        self::assertNotNull(MessageQuery::create()->findOneByName('order_return_status_changed'), 'The shop ships the message that announces a return.');
        $toTheCustomer = array_filter(
            self::getMailerMessages(),
            static fn (RawMessage $message): bool => $message instanceof Email
                && \in_array($customer->getEmail(), array_map(static fn (Address $to): string => $to->getAddress(), $message->getTo()), true),
        );
        self::assertCount(1, $toTheCustomer, 'One mail leaves the shop for the opening of the return.');
    }

    public function testAnOrderThatIsNoLongerPaidIsRefusedAtSubmission(): void
    {
        foreach ([OrderStatus::CODE_NOT_PAID, OrderStatus::CODE_CANCELED] as $statusCode) {
            [$customer, $order, $line] = $this->paidOrderWithOneLine(2.0);
            $this->injector?->setCustomer($customer);

            // The form is served while the order is paid; the order moves on before it is sent.
            $form = $this->returnForm($order, $line, '1');
            $statusId = OrderStatusQuery::create()->findOneByCode($statusCode)?->getId();
            self::assertNotNull($statusId);
            $order->setStatusId($statusId)->save($this->getPropelConnection());

            $this->client->submit($form);

            self::assertSame(302, $this->client->getResponse()->getStatusCode(), $statusCode);
            self::assertStringContainsString('/order/'.$order->getId().'/return', (string) $this->client->getResponse()->headers->get('Location'), 'The customer comes back to the form.');
            self::assertFalse(OrderReturnQuery::create()->filterByOrderId($order->getId())->exists(), 'Nothing is written for an order that is '.$statusCode.'.');

            // And its form is gone altogether.
            $this->client->request('GET', $this->url(self::FORM_ROUTE, ['orderId' => $order->getId()]));
            self::assertSame(404, $this->client->getResponse()->getStatusCode(), $statusCode);
        }
    }

    public function testAQuantityAboveTheOrderedOneIsRefusedWithAMessageOnTheForm(): void
    {
        [$customer, $order, $line] = $this->paidOrderWithOneLine(2.0);

        $this->injector?->setCustomer($customer);
        $this->submitReturn($order, $line, '9');

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertFalse(OrderReturnQuery::create()->filterByOrderId($order->getId())->exists());

        $crawler = $this->client->followRedirect();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[role="alert"]'), 'The refusal is told to the customer on the form.');
        self::assertSame(
            0,
            OrderHistoryQuery::create()->filterByOrderId((int) $order->getId())->filterByEventType(OrderHistoryEventType::RETURN_OPENED->value)->count(),
            'A refused request leaves nothing on the history.',
        );
    }

    public function testNothingIsReachableWhenReturnsAreDisabled(): void
    {
        [$customer, $order, $line] = $this->paidOrderWithOneLine(2.0);
        $this->injector?->setCustomer($customer);
        $form = $this->returnForm($order, $line, '1');

        ConfigQuery::write(ReturnEligibilityChecker::ENABLED_CONFIG_KEY, '0');

        $this->client->request('GET', $this->url(self::LIST_ROUTE));
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'The list is gone.');

        $this->client->submit($form);
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'And so is the submission.');
        self::assertFalse(OrderReturnQuery::create()->filterByOrderId($order->getId())->exists());
    }

    public function testAVisitorWhoIsNotSignedInIsSentToTheSignInPage(): void
    {
        [, $order] = $this->paidOrderWithOneLine(2.0);

        foreach ([
            ['GET', $this->url(self::LIST_ROUTE)],
            ['GET', $this->url(self::FORM_ROUTE, ['orderId' => $order->getId()])],
            ['POST', $this->url(self::CREATE_ROUTE, ['orderId' => $order->getId()])],
        ] as [$method, $url]) {
            $this->client->request($method, $url);

            self::assertSame(302, $this->client->getResponse()->getStatusCode(), $method.' '.$url);
            self::assertStringContainsString(
                $this->url('customer_login'),
                (string) $this->client->getResponse()->headers->get('Location'),
                $method.' '.$url.' sends the visitor to sign in.',
            );
        }

        self::assertFalse(OrderReturnQuery::create()->filterByOrderId($order->getId())->exists());
    }

    public function testTheReturnOfAnotherCustomerIsNotFound(): void
    {
        [$owner, $order, $line] = $this->paidOrderWithOneLine(2.0);
        $this->injector?->setCustomer($owner);
        $this->submitReturn($order, $line, '1');
        $return = OrderReturnQuery::create()->filterByOrderId($order->getId())->findOne();
        self::assertNotNull($return);

        $this->injector?->setCustomer($this->customer());
        $this->client->request('GET', $this->url(self::TRACKING_ROUTE, ['returnId' => $return->getId()]));
        $foreign = $this->client->getResponse()->getStatusCode();

        $this->client->request('GET', $this->url(self::TRACKING_ROUTE, ['returnId' => 999999999]));
        $missing = $this->client->getResponse()->getStatusCode();

        self::assertSame(404, $foreign);
        self::assertSame($missing, $foreign, 'A return of somebody else answers like one that does not exist.');
    }

    /**
     * Fills the real form of the page, so the CSRF token and the field names are the ones the
     * theme renders rather than ones this test made up.
     */
    private function returnForm(Order $order, OrderProduct $line, string $quantity): \Symfony\Component\DomCrawler\Form
    {
        $crawler = $this->client->request('GET', $this->url(self::FORM_ROUTE, ['orderId' => $order->getId()]));

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'The return form must be reachable.');

        $form = $this->formOf($crawler);
        $form['lines['.$line->getId().'][selected]']->tick();
        $form['lines['.$line->getId().'][quantity]']->setValue($quantity);
        $form['resolution']->select('refund');

        return $form;
    }

    private function formOf(Crawler $crawler): \Symfony\Component\DomCrawler\Form
    {
        return $crawler->filter('form[method="POST"]')->form();
    }

    private function submitReturn(Order $order, OrderProduct $line, string $quantity): void
    {
        $this->client->submit($this->returnForm($order, $line, $quantity));
    }

    /**
     * @return array{0: Customer, 1: Order, 2: OrderProduct}
     */
    private function paidOrderWithOneLine(float $quantity): array
    {
        $customer = $this->customer();
        $order = $this->factory()->order($customer, ['statusCode' => OrderStatus::CODE_PAID]);

        $order->setCreatedAt(new \DateTime('-1 days'));
        $order->save($this->getPropelConnection());

        $reference = 'JOURNEY-LINE-'.$order->getId();

        $orderProduct = new OrderProduct();
        $orderProduct->setOrderId($order->getId());
        $orderProduct->setProductRef($reference);
        $orderProduct->setProductSaleElementsRef($reference.'-PSE');
        $orderProduct->setTitle('A chair to send back');
        $orderProduct->setQuantity($quantity);
        $orderProduct->setPrice('100.000000');
        $orderProduct->setPromoPrice('0.000000');
        $orderProduct->setWasNew(0);
        $orderProduct->setWasInPromo(0);
        $orderProduct->setVirtual(0);
        $orderProduct->save($this->getPropelConnection());

        return [$customer, $order, $orderProduct];
    }

    private function customer(): Customer
    {
        return $this->factory()->customer($this->factory()->customerTitle());
    }

    private function url(string $route, array $parameters = []): string
    {
        return $this->getService(RouterInterface::class)->generate($route, $parameters);
    }

    /**
     * Built without createFixtureFactory(): that helper pushes a synthetic request when the
     * stack is empty, and it would then be the "main" request of the calls below, the one
     * the session, and therefore the signed-in customer, is read from.
     */
    private function factory(): FixtureFactory
    {
        return new FixtureFactory($this->getPropelConnection());
    }
}
