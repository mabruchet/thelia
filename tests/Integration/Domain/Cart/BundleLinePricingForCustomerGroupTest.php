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

namespace Thelia\Tests\Integration\Domain\Cart;

use Thelia\Core\Event\Cart\CartEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Cart\Service\CartLinePricer;
use Thelia\Domain\Catalog\Bundle\BundleComposition;
use Thelia\Domain\Catalog\Bundle\BundleCompositionService;
use Thelia\Domain\Catalog\Bundle\BundlePriceMode;
use Thelia\Domain\Pricing\BundleQuoter;
use Thelia\Domain\Pricing\PricingActivityChecker;
use Thelia\Model\Cart;
use Thelia\Model\CartItemQuery;
use Thelia\Model\Country;
use Thelia\Model\Currency;
use Thelia\Model\Customer;
use Thelia\Model\CustomerGroup;
use Thelia\Model\Product;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Model\TaxRuleCountry;
use Thelia\Test\ActionIntegrationTestCase;
use Thelia\Tests\Integration\Domain\Catalog\Bundle\BuildsBundles;

/**
 * A bundle priced by its components, sold to a member of a discounted group: the price of the
 * composition (two locks: 600 struck, 540 charged), then the discount of the group (20 %), then
 * the one of the customer (5 %). A price the group has on the sale element of that bundle is
 * ignored; a bundle sold at a fixed price is a product like any other.
 */
final class BundleLinePricingForCustomerGroupTest extends ActionIntegrationTestCase
{
    use BuildsBundles;

    private BundleCompositionService $service;

    private Customer $member;

    private CustomerGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->getService(BundleCompositionService::class);
        $this->group = $this->factory->customerGroup('pro', 20.0);
        $this->member = $this->factory->customer($this->factory->customerTitle(), ['customerGroup' => $this->group]);
        $this->member->setDiscount('5')->save();
    }

    public function testAMemberPaysAComputedBundleLessTheDiscountOfTheGroupThenTheOneOfTheCustomer(): void
    {
        $example = $this->exampleBundle($this->factory, $this->service);
        $this->forgetResolvedPrices();

        $line = $this->add($this->cartOf($this->member), $example['bundle'], $this->composition($example, 2))->getCartItem();

        self::assertEqualsWithDelta(456.0, (float) $line->getPrice(), 0.000001, '600 less 20 % less 5 %.');
        self::assertEqualsWithDelta(410.4, (float) $line->getPromoPrice(), 0.000001, '540 less 20 % less 5 %.');
        self::assertSame(1, (int) $line->getPromo());
    }

    public function testAPriceOfTheGroupOnTheSaleElementOfAComputedBundleIsIgnored(): void
    {
        $example = $this->exampleBundle($this->factory, $this->service);
        $this->factory->customerGroupProductPrice($this->group, $this->saleElementsOf($example['bundle']), Currency::getDefaultCurrency(), 50.0);
        $this->forgetResolvedPrices();

        $line = $this->add($this->cartOf($this->member), $example['bundle'], $this->composition($example, 2))->getCartItem();

        self::assertEqualsWithDelta(410.4, (float) $line->getPromoPrice(), 0.000001);
    }

    public function testABundleSoldAtAFixedPriceIsAProductLikeAnyOtherForAGroup(): void
    {
        $example = $this->exampleBundle($this->factory, $this->service, BundlePriceMode::Fixed);
        $this->forgetResolvedPrices();

        $line = $this->add($this->cartOf($this->member), $example['bundle'], $this->composition($example, 2))->getCartItem();

        self::assertEqualsWithDelta(759.24, $line->getRealPrice(), 0.000001, '999 less 20 % less 5 %.');

        $this->factory->customerGroupProductPrice($this->group, $this->saleElementsOf($example['bundle']), Currency::getDefaultCurrency(), 700.0);
        $this->forgetResolvedPrices();

        $own = $this->add($this->cartOf($this->member), $example['bundle'], $this->composition($example, 2))->getCartItem();

        self::assertEqualsWithDelta(665.0, $own->getRealPrice(), 0.000001, 'The price of the group, less 5 %.');
    }

    public function testThePageQuotesWhatTheLineOfTheCartCosts(): void
    {
        $example = $this->exampleBundle($this->factory, $this->service);
        $taxRule = $this->factory->taxRule(['isDefault' => false]);
        $tax = $this->factory->tax(['requirements' => ['percent' => '20'], 'title' => 'VAT 20']);
        (new TaxRuleCountry())
            ->setTaxRuleId($taxRule->getId())
            ->setCountryId(Country::getDefaultCountry()->getId())
            ->setTaxId($tax->getId())
            ->setPosition(1)
            ->save();
        $example['bundle']->setTaxRuleId($taxRule->getId())->save();
        $this->forgetResolvedPrices();

        $composition = $this->composition($example, 2);
        $line = $this->add($this->cartOf($this->member), $example['bundle'], $composition)->getCartItem();
        $quote = $this->getService(BundleQuoter::class)->quote(
            (int) $example['bundle']->getId(),
            (int) $this->saleElementsOf($example['bundle'])->getId(),
            $composition,
            Currency::getDefaultCurrency(),
            $this->member,
        );

        self::assertNotNull($quote);
        self::assertEqualsWithDelta((float) $line->getPromoPrice(), $quote->untaxedPrice, 0.000001, 'The page shows what the cart charges.');
        self::assertEqualsWithDelta((float) $line->getPrice(), $quote->untaxedFullPrice, 0.000001);
        self::assertEqualsWithDelta(492.48, $quote->taxedPrice, 0.001, '410,40 and 20 % of tax.');
        self::assertEqualsWithDelta(547.2, $quote->taxedFullPrice, 0.001);
    }

    public function testARepricingOfTheBundleLinesLeavesABundleSoldAtAFixedPriceAlone(): void
    {
        $computed = $this->exampleBundle($this->factory, $this->service);
        $fixed = $this->exampleBundle($this->factory, $this->service, BundlePriceMode::Fixed);
        $cart = $this->cartOf(null);
        $computedLine = $this->add($cart, $computed['bundle'], $this->composition($computed, 2))->getCartItem();
        $fixedLine = $this->add($cart, $fixed['bundle'], $this->composition($fixed, 2))->getCartItem();
        self::assertSame(999.0, (float) $fixedLine->getPrice());

        ProductPriceQuery::create()->filterByProductSaleElementsId($this->saleElementsOf($fixed['bundle'])->getId())->findOne()->setPrice('1200')->setPromoPrice('1200')->save();
        ProductPriceQuery::create()->filterByProductSaleElementsId($this->saleElementsOf($computed['lockProduct'])->getId())->findOne()->setPrice('30')->setPromoPrice('30')->save();
        $this->forgetResolvedPrices();

        $moved = $this->getService(CartLinePricer::class)->reprice(CartItemQuery::create()->findPk($fixedLine->getId())->getCart(), Currency::getDefaultCurrency(), null, true);

        self::assertSame([(int) $computedLine->getId()], array_map(static fn ($line): int => (int) $line->getId(), $moved), 'Only the line priced by its components moves.');
        self::assertSame(999.0, (float) CartItemQuery::create()->findPk($fixedLine->getId())->getPrice(), 'The fixed bundle keeps the price it was sold at, as a simple line does.');
        self::assertSame(558.0, (float) CartItemQuery::create()->findPk($computedLine->getId())->getPromoPrice());
    }

    public function testTouchingACartThatHoldsABundleSoldAtAFixedPriceDoesNotSettleIt(): void
    {
        // A shop where no group discounts anything: the cart is then settled for its bundle lines only.
        $this->group->setDiscount('0')->save();
        $this->exampleBundle($this->factory, $this->service);
        $fixed = $this->exampleBundle($this->factory, $this->service, BundlePriceMode::Fixed);
        $other = $this->component($this->factory, '12', 5);
        $cart = $this->cartOf(null);
        $fixedLine = $this->add($cart, $fixed['bundle'], $this->composition($fixed, 2))->getCartItem();

        ProductPriceQuery::create()->filterByProductSaleElementsId($this->saleElementsOf($fixed['bundle'])->getId())->findOne()->setPrice('1200')->setPromoPrice('1200')->save();
        $this->forgetResolvedPrices();

        $this->add($cart, $other);

        self::assertSame(999.0, (float) CartItemQuery::create()->findPk($fixedLine->getId())->getPrice());
    }

    private function cartOf(?Customer $customer): Cart
    {
        return $this->factory->cart($customer, ['currency' => Currency::getDefaultCurrency()]);
    }

    /**
     * @param array<string, mixed> $example
     */
    private function composition(array $example, int $locks): BundleComposition
    {
        return BundleComposition::fromArray([
            ['bundleItemId' => $example['bike']->getId(), 'quantity' => 1],
            ['bundleItemId' => $example['helmet']->getId(), 'quantity' => 2],
            ['bundleItemId' => $example['lock']->getId(), 'quantity' => $locks],
        ]);
    }

    private function add(Cart $cart, Product $product, ?BundleComposition $composition = null): CartEvent
    {
        $event = (new CartEvent($cart))
            ->setProductId((int) $product->getId())
            ->setProductSaleElementsId((int) ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne()->getId())
            ->setQuantity(1)
            ->setNewness(true)
            ->setAppend(true)
            ->setBundleComposition($composition);

        return $this->dispatch($event, TheliaEvents::CART_ADDITEM);
    }

    /**
     * A new request remembers nothing of what the previous one resolved or of what the shop sold.
     */
    private function forgetResolvedPrices(): void
    {
        $this->getService(PricingActivityChecker::class)->reset();
        $this->getService('services_resetter')->reset();
    }
}
