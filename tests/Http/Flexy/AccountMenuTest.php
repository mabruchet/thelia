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

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\RouterInterface;
use Thelia\Domain\OrderReturn\Service\ReturnEligibilityChecker;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\Flexy\CustomerSessionInjector;

/**
 * The customer account navigation, as the account subheader and the header profile
 * dropdown render it: the three entries the theme has always shipped, and "My returns"
 * only while the shop offers returns.
 *
 * The extensible menu belongs to the theme, which ships on its own release cycle: a
 * theme older than the returns list is reported as skipped rather than failed.
 */
final class AccountMenuTest extends WebIntegrationTestCase
{
    private const NATIVE_ENTRIES = [
        ['My profile', 'account_index'],
        ['My orders', 'account_orders'],
        ['My addresses', 'account_addresses'],
    ];

    private ?CustomerSessionInjector $injector = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (null === $this->getService(RouterInterface::class)->getRouteCollection()->get('account_returns')) {
            self::markTestSkipped('The installed front-office theme has no returns list.');
        }

        $this->injector = new CustomerSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $factory = new FixtureFactory($this->getPropelConnection());
        $this->injector->setCustomer($factory->customer($factory->customerTitle()));
    }

    protected function tearDown(): void
    {
        $this->injector?->clear();
        // The written setting goes with the transaction rollback; the static cache of
        // ConfigQuery does not.
        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testTheMenuIsUnchangedWhenReturnsAreOff(): void
    {
        ConfigQuery::write(ReturnEligibilityChecker::ENABLED_CONFIG_KEY, '0');

        $crawler = $this->accountPage();
        $expected = $this->expectedEntries(self::NATIVE_ENTRIES);

        self::assertSame($expected, $this->entries($crawler->filter('.Subheader-links a')));
        self::assertSame($expected, $this->dropdownEntries($crawler));
    }

    public function testMyReturnsFollowsMyOrdersWhenReturnsAreOn(): void
    {
        ConfigQuery::write(ReturnEligibilityChecker::ENABLED_CONFIG_KEY, '1');

        $crawler = $this->accountPage();
        $expected = $this->expectedEntries([
            self::NATIVE_ENTRIES[0],
            self::NATIVE_ENTRIES[1],
            ['My returns', 'account_returns'],
            self::NATIVE_ENTRIES[2],
        ]);

        self::assertSame($expected, $this->entries($crawler->filter('.Subheader-links a')));
        self::assertSame($expected, $this->dropdownEntries($crawler));
    }

    public function testBothMenusAreLabelledNavigationsMarkingTheCurrentPage(): void
    {
        $crawler = $this->accountPage();
        $profile = $this->getService(RouterInterface::class)->generate('account_index');

        foreach (['.Subheader-links', '#HeaderProfile-dropdown'] as $menu) {
            self::assertCount(1, $crawler->filter('nav[aria-label] '.$menu), $menu.' must sit in a labelled navigation.');

            $current = $crawler->filter($menu.' a[aria-current="page"]');
            self::assertCount(1, $current, $menu.' must mark exactly one entry as the current page.');
            self::assertSame($profile, $current->attr('href'));
        }
    }

    /**
     * A shop installed in a subdirectory: the generated hrefs carry the base path, and the
     * dropdown must still recognise the page it is on.
     */
    public function testTheCurrentEntryIsMarkedUnderABasePath(): void
    {
        $crawler = $this->client->request('GET', '/shop/account', server: [
            'SCRIPT_FILENAME' => '/var/www/html/public/index.php',
            'SCRIPT_NAME' => '/shop/index.php',
            'PHP_SELF' => '/shop/index.php',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $current = $crawler->filter('#HeaderProfile-dropdown a[aria-current="page"]');
        self::assertCount(1, $current, 'The dropdown must mark the current page under a base path.');
        self::assertSame('/shop/account', $current->attr('href'));
    }

    private function accountPage(): Crawler
    {
        $crawler = $this->client->request('GET', $this->getService(RouterInterface::class)->generate('account_index'));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $crawler;
    }

    /**
     * The dropdown ends on the logout link, which is not an account section.
     *
     * @return list<array{string, string}>
     */
    private function dropdownEntries(Crawler $crawler): array
    {
        $entries = $this->entries($crawler->filter('#HeaderProfile-dropdown a'));
        $logout = array_pop($entries);

        self::assertSame($this->getService(RouterInterface::class)->generate('customer_logout'), $logout[1] ?? null);

        return $entries;
    }

    /**
     * @return list<array{string, string}>
     */
    private function entries(Crawler $links): array
    {
        return $links->each(
            static fn (Crawler $link): array => [trim($link->text()), (string) $link->attr('href')],
        );
    }

    /**
     * @param list<array{string, string}> $entries text and route name
     *
     * @return list<array{string, string}>
     */
    private function expectedEntries(array $entries): array
    {
        $router = $this->getService(RouterInterface::class);

        return array_map(
            static fn (array $entry): array => [$entry[0], $router->generate($entry[1])],
            $entries,
        );
    }
}
