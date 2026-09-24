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
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Thelia\Api\Bridge\Propel\State\PropelPersistProcessor;
use Thelia\Api\Resource\OrderReturn as OrderReturnResource;
use Thelia\Api\Service\OrderReturnHydrator;
use Thelia\Api\Service\OrderReturnStatusEmailDispatcher;
use Thelia\Domain\OrderReturn\Service\OrderReturnWriteTransaction;
use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;
use Thelia\Domain\OrderReturn\Service\ReturnRequestLimiter;
use Thelia\Model\Customer;
use Thelia\Model\OrderQuery;

/**
 * A return opened under /front/account belongs to the customer holding the
 * token. The owner and the initial status never come from the body, and every
 * line is checked against the order before anything is written.
 *
 * Every refusal this processor lets through - a ReturnNotAllowedException and
 * its ReturnRequestConflictException subclass - is mapped to its HTTP status
 * by the api_platform.exception_to_status configuration, not by a catch here:
 * see the core api_platform package configuration.
 */
final readonly class OrderReturnFrontCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private PropelPersistProcessor $persistProcessor,
        private TokenStorageInterface $tokenStorage,
        private OrderReturnHydrator $hydrator,
        private OrderReturnStatusEmailDispatcher $statusEmailDispatcher,
        private ReturnRequestLimiter $limiter,
        private OrderReturnWriteTransaction $transaction = new OrderReturnWriteTransaction(),
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof OrderReturnResource) {
            return $this->statusEmailDispatcher->dispatch(
                $this->persistProcessor->process($data, $operation, $uriVariables, $context),
            );
        }

        $customer = $this->tokenStorage->getToken()?->getUser();

        if (!$customer instanceof Customer) {
            throw new AccessDeniedHttpException('A customer must be authenticated to open a return.');
        }

        [$orderId, $orderProductIds] = OrderReturnHydrator::rowsToLock($data);

        // A plain read, before any row is locked: naming somebody else's order
        // in the body must not let a caller hold a FOR UPDATE lock on it, even
        // briefly, and this refusal must cost nothing towards the request
        // quota either - both true only if it happens before run() opens the
        // transaction. It answers a missing order and a foreign one with the
        // same message, see ReturnEligibilityChecker::ORDER_NOT_USABLE_MESSAGE.
        if (!OrderQuery::create()->filterById($orderId)->filterByCustomerId($customer->getId())->exists()) {
            throw new UnprocessableEntityHttpException(ReturnEligibilityChecker::ORDER_NOT_USABLE_MESSAGE);
        }

        // Checking how much of a line is still returnable and writing the
        // return that consumes it belong to the same transaction, or two
        // requests arriving together are both allowed the same last unit. The
        // persist processor opens a transaction of its own, which Propel nests
        // inside this one.
        $result = $this->transaction->run($orderId, $orderProductIds, function () use ($data, $customer, $operation, $uriVariables, $context): mixed {
            $this->hydrator->hydrate($data, $customer, false);

            // The quota counts the returns a customer opens, not the mistakes
            // they make filling the form in: consumed before the eligibility
            // gate, twenty refusals closed the hour for the one valid request
            // that followed them.
            if (!$this->limiter->allows($customer)) {
                throw new TooManyRequestsHttpException(message: 'Too many return requests, please try again later.');
            }

            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        });

        return $this->statusEmailDispatcher->dispatch($result);
    }
}
