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

namespace Thelia\Api\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Thelia\Api\Bridge\Propel\State\PropelPersistProcessor;
use Thelia\Api\Resource\OrderReturn as OrderReturnResource;
use Thelia\Api\Service\OrderReturnHydrator;
use Thelia\Api\Service\OrderReturnStatusEmailDispatcher;
use Thelia\Domain\OrderReturn\Service\OrderReturnWriteTransaction;
use Thelia\Domain\OrderReturn\Service\OrderReturnWriteTransactionInterface;
use Thelia\Model\Customer;
use Thelia\Model\OrderQuery;

/**
 * A return opened by the merchant from the back-office, without a customer
 * request (a return received by phone). The customer is taken from the order,
 * the return is flagged as admin-created, and the lines are still checked.
 *
 * The order named in the body comes from the merchant, never from an
 * unauthenticated caller naming somebody else's order: unlike the front path,
 * there is no ownership to check here before locking it.
 *
 * Every refusal this processor lets through - a ReturnNotAllowedException and
 * its ReturnRequestConflictException subclass - is mapped to its HTTP status
 * by the api_platform.exception_to_status configuration, not by a catch here:
 * see the core api_platform package configuration.
 */
final readonly class OrderReturnAdminCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private PropelPersistProcessor $persistProcessor,
        private OrderReturnHydrator $hydrator,
        private OrderReturnStatusEmailDispatcher $statusEmailDispatcher,
        private OrderReturnWriteTransactionInterface $transaction = new OrderReturnWriteTransaction(),
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof OrderReturnResource) {
            return $this->statusEmailDispatcher->dispatch(
                $this->persistProcessor->process($data, $operation, $uriVariables, $context),
            );
        }

        $order = OrderQuery::create()->findPk($data->getOrder()->getId());
        $customer = $order?->getCustomer();

        if (!$customer instanceof Customer) {
            throw new UnprocessableEntityHttpException('The order does not exist or has no customer.');
        }

        // The merchant path holds the same lines as the customer one, so it
        // takes the same row locks, in the same transaction as the insert they
        // protect. The persist processor opens a transaction of its own, which
        // Propel nests inside this one.
        [$orderId, $orderProductIds] = OrderReturnHydrator::rowsToLock($data);

        $result = $this->transaction->run($orderId, $orderProductIds, function () use ($data, $customer, $operation, $uriVariables, $context): mixed {
            $this->hydrator->hydrate($data, $customer, true);

            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        });

        return $this->statusEmailDispatcher->dispatch($result);
    }
}
