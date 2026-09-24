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

use PHPUnit\Framework\Attributes\DataProvider;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;

/**
 * The public listings read their page number straight from the query string. Anything a
 * visitor types there must still render the listing: a page too large for API Platform
 * to compute an offset, and a page that is not a number at all.
 */
final class ListingPageNumberTest extends WebIntegrationTestCase
{
    /**
     * @return iterable<string, array{string|list<string>}>
     */
    public static function pageNumbers(): iterable
    {
        yield 'too large for an offset' => ['999999999999999999'];
        yield 'not a number' => ['abc'];
        yield 'an array' => [['1']];
    }

    /**
     * @param string|list<string> $page
     */
    #[DataProvider('pageNumbers')]
    public function testAFolderRendersWhateverThePage(string|array $page): void
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $folder = $factory->folder();
        $factory->content($folder);

        $this->client->request('GET', '/folder?'.http_build_query(['folder_id' => $folder->getId(), 'page' => $page]));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @param string|list<string> $page
     */
    #[DataProvider('pageNumbers')]
    public function testACategoryRendersWhateverThePage(string|array $page): void
    {
        $category = (new FixtureFactory($this->getPropelConnection()))->category();
        $category->setLocale('en_US')->setTitle('Paged category')->save($this->getPropelConnection());

        $this->client->request('GET', '/category?'.http_build_query(['category_id' => $category->getId(), 'page' => $page]));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }
}
