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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use ApiPlatform\Metadata\Exception\InvalidArgumentException as ApiInvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Thelia\Domain\OrderReturn\Exception\ReturnNotAllowedException;
use Thelia\Domain\OrderReturn\Exception\ReturnRequestConflictException;

return static function (ContainerConfigurator $container): void {
    $container->extension('api_platform', [
        'title' => 'Thelia API',
        'version' => '3.1.0',
        'show_webby' => false,
        'serializer' => [
            'hydra_prefix' => true,
        ],
        'exception_to_status' => [
            // Configuring this key at all replaces api_platform's own default
            // value instead of merging with it (Symfony config trees only fall
            // back to defaultValue() when no source sets the key), so the two
            // of its three built-in entries this project can ever throw are
            // carried over here - a bad IRI in a request body, for instance,
            // otherwise answers a 500 instead of the 400
            // ApiInvalidArgumentException used to give it. The third
            // (Doctrine\ORM\OptimisticLockException) is left out: this project
            // has no Doctrine ORM, so nothing ever throws it.
            SerializerExceptionInterface::class => Response::HTTP_BAD_REQUEST,
            ApiInvalidArgumentException::class => Response::HTTP_BAD_REQUEST,
            // The subclass first: exception_to_status stops at the first class
            // the thrown exception is an instance of, and every
            // ReturnRequestConflictException also is a ReturnNotAllowedException.
            // Listed the other way round, a conflict would answer 422 instead
            // of the 409 a caller can retry.
            ReturnRequestConflictException::class => Response::HTTP_CONFLICT,
            ReturnNotAllowedException::class => Response::HTTP_UNPROCESSABLE_ENTITY,
            // A module that prepends its own exception_to_status with a wide
            // class caught here too (\RuntimeException, say) would be merged
            // ahead of this file and win: the first class the thrown
            // exception is an instance of settles the status, so such an
            // entry would silently take the 409 and the 422 above away from
            // their callers.
        ],
        'defaults' => [
            'pagination_client_items_per_page' => true,
            // The caller picks its page size, so the page size needs a ceiling:
            // without one a single call can ask the shop to load, hydrate and
            // serialize a whole table in one response. A hundred is well above
            // what the shipped themes ask for (thirty on the front, twenty-five
            // in the back-office) and above the page sizes an integration
            // usually walks a catalogue with. A project that needs a different
            // ceiling overrides this key in its own api_platform configuration,
            // and an operation that needs its own says so on its metadata.
            'pagination_maximum_items_per_page' => 100,
            'stateless' => false,
        ],
        'mapping' => [
            'paths' => [],
        ],
        'formats' => [
            'json' => [
                'mime_types' => ['application/json'],
            ],
            'jsonld' => [
                'mime_types' => ['application/ld+json'],
            ],
            'html' => [
                'mime_types' => ['text/html'],
            ],
        ],
        'swagger' => [
            'versions' => [3],
            'swagger_ui_extra_configuration' => [
                'docExpansion' => 'none',
                'filter' => true,
                'persistAuthorization' => true,
                'showCommonExtensions' => true,
            ],
            'api_keys' => [
                'JWT' => [
                    'name' => 'Authorization',
                    'type' => 'header',
                ],
            ],
        ],
    ], prepend: true);
};
