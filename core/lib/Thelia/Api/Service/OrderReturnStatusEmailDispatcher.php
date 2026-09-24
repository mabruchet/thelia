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

namespace Thelia\Api\Service;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Api\Resource\OrderReturn as OrderReturnResource;
use Thelia\Core\Event\OrderReturn\OrderReturnEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\OrderReturn as OrderReturnModel;

/**
 * Announces a return to the customer once it is committed, never from inside
 * the write transaction: a mail is not something a rollback takes back.
 *
 * Shared by every create processor of the return API (front and admin), which
 * used to each carry their own copy of this same dispatch.
 */
final readonly class OrderReturnStatusEmailDispatcher
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function dispatch(mixed $result): mixed
    {
        $model = $result instanceof OrderReturnResource ? $result->getPropelModel() : null;

        if ($model instanceof OrderReturnModel) {
            $this->eventDispatcher->dispatch(new OrderReturnEvent($model), TheliaEvents::ORDER_RETURN_SEND_STATUS_EMAIL);
        }

        return $result;
    }
}
