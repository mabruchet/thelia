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

namespace Thelia\Tests\Unit\Api\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Api\Resource\OrderReturn as OrderReturnResource;
use Thelia\Api\Service\OrderReturnStatusEmailDispatcher;
use Thelia\Core\Event\OrderReturn\OrderReturnEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\OrderReturn as OrderReturnModel;

/**
 * dispatch(mixed) exists for the API bridge, which only ever hands over a
 * resource: a caller that already holds the Propel model - the ORDER_RETURN
 * listener, a theme reacting to its own event - used to wrap it in a resource
 * for no reason but to call this. dispatchFor() takes the model directly and
 * dispatch() is a thin pass-through onto it.
 */
final class OrderReturnStatusEmailDispatcherTest extends TestCase
{
    public function testDispatchForAnnouncesTheModelDirectly(): void
    {
        $orderReturn = new OrderReturnModel();

        $dispatchedEvents = [];
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(
            static function (object $event, ?string $eventName) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = [$event, $eventName];

                return $event;
            },
        );

        (new OrderReturnStatusEmailDispatcher($eventDispatcher))->dispatchFor($orderReturn);

        self::assertCount(1, $dispatchedEvents, 'dispatchFor() must announce the return exactly once.');
        [$event, $eventName] = $dispatchedEvents[0];
        self::assertInstanceOf(OrderReturnEvent::class, $event);
        self::assertSame($orderReturn, $event->getOrderReturn());
        self::assertSame(TheliaEvents::ORDER_RETURN_SEND_STATUS_EMAIL, $eventName);
    }

    public function testDispatchDelegatesToDispatchForWhenTheResultCarriesAModel(): void
    {
        $orderReturn = new OrderReturnModel();
        $resource = (new OrderReturnResource())->setPropelModel($orderReturn);

        $dispatchedEvents = [];
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(
            static function (object $event, ?string $eventName) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = [$event, $eventName];

                return $event;
            },
        );

        $result = (new OrderReturnStatusEmailDispatcher($eventDispatcher))->dispatch($resource);

        self::assertSame($resource, $result, 'dispatch() must hand the result back unchanged.');
        self::assertCount(1, $dispatchedEvents);
        [$event, $eventName] = $dispatchedEvents[0];
        self::assertInstanceOf(OrderReturnEvent::class, $event);
        self::assertSame($orderReturn, $event->getOrderReturn());
        self::assertSame(TheliaEvents::ORDER_RETURN_SEND_STATUS_EMAIL, $eventName);
    }

    public function testDispatchAnnouncesNothingWhenTheResultCarriesNoModel(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $result = (new OrderReturnStatusEmailDispatcher($eventDispatcher))->dispatch('not a return resource');

        self::assertSame('not a return resource', $result);
    }
}
