<?php

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Tests\Integration\Classes;

use Address;
use Carrier;
use Cart;
use CartRule;
use Configuration;
use Context;
use Currency;
use Db;
use Exception;
use Group;
use Order;
use PHPUnit\Framework\TestCase;
use Product;
use Tax;
use TaxRule;
use TaxRulesGroup;
use Tools;

class CartTest extends TestCase
{
    /**
     * @var int
     */
    private static $id_address;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Some tests might have cleared the configuration
        Configuration::loadConfiguration();

        // We'll base all our computations on the invoice address
        Configuration::updateValue('PS_TAX_ADDRESS_TYPE', 'id_address_invoice');

        // We don't care about stock, abstract this away by allowing ordering out of stock products
        Configuration::updateValue('PS_ORDER_OUT_OF_STOCK', true);

        // Create the address only once
        self::$id_address = self::makeAddress()->id;
    }

    private static function setRoundingMode(string $modeStr): int
    {
        switch ($modeStr) {
            case 'up':
                $mode = PS_ROUND_UP;
                break;
            case 'down':
                $mode = PS_ROUND_DOWN;
                break;
            case 'half_up':
                $mode = PS_ROUND_HALF_UP;
                break;
            case 'half_down':
            case 'half_even':
                $mode = PS_ROUND_HALF_DOWN;
                break;
            case 'hald_odd':
                $mode = PS_ROUND_HALF_ODD;
                break;
            default:
                throw new Exception(sprintf('Unknown rounding mode `%s`.', $modeStr));
        }

        Configuration::set('PS_PRICE_ROUND_MODE', $mode);

        return $mode;
    }

    private static function setRoundingType(string $typeStr): int
    {
        switch ($typeStr) {
            case 'item':
                $type = Order::ROUND_ITEM;
                break;
            case 'line':
                $type = Order::ROUND_LINE;
                break;
            case 'total':
                $type = Order::ROUND_TOTAL;
                break;
            default:
                throw new Exception(sprintf('Unknown rounding type `%s`.', $typeStr));
        }

        Configuration::set('PS_ROUND_TYPE', $type);

        return $type;
    }

    /**
     * $rate is e.g. 5.5, 20...
     * This is cached by $rate.
     */
    private static function getIdTax(int $rate): string
    {
        static $taxes = [];

        $name = $rate . '% TAX';

        if (!array_key_exists($name, $taxes)) {
            $tax = new Tax(null, (int) Configuration::get('PS_LANG_DEFAULT'));
            $tax->name = $name;
            $tax->rate = $rate;
            $tax->active = true;
            self::assertTrue((bool) $tax->save()); // casting because actually returns 1, but not the point here.
            $taxes[$name] = $tax->id;
        }

        return $taxes[$name];
    }

    /**
     * This is cached by $rate.
     */
    private static function getIdTaxRulesGroup(int $rate): int
    {
        static $groups = [];

        $name = $rate . '% TRG';

        if (!array_key_exists($name, $groups)) {
            $taxRulesGroup = new TaxRulesGroup(null, (int) Configuration::get('PS_LANG_DEFAULT'));
            $taxRulesGroup->name = $name;
            $taxRulesGroup->active = true;
            self::assertTrue((bool) $taxRulesGroup->save());

            $taxRule = new TaxRule(null, (int) Configuration::get('PS_LANG_DEFAULT'));
            $taxRule->id_tax = self::getIdTax($rate);
            $taxRule->id_country = Configuration::get('PS_COUNTRY_DEFAULT');
            $taxRule->id_tax_rules_group = $taxRulesGroup->id;

            self::assertTrue($taxRule->save());

            $groups[$name] = $taxRulesGroup->id;
        }

        return (int) $groups[$name];
    }

    /**
     * This is cached by $name.
     */
    private static function makeProduct(string $name, float $price, int $id_tax_rules_group): Product
    {
        $product = new Product(null, false, (int) Configuration::get('PS_LANG_DEFAULT'));
        $product->id_tax_rules_group = $id_tax_rules_group;
        $product->name = $name;
        $product->price = $price;
        $product->link_rewrite = Tools::str2url($name);
        self::assertTrue($product->save());

        return $product;
    }

    private static function makeAddress(): Address
    {
        $address = new Address();
        $address->id_country = (int) Configuration::get('PS_COUNTRY_DEFAULT');
        $address->firstname = 'Unit';
        $address->lastname = 'Tester';
        $address->address1 = '55 rue Raspail';
        $address->alias = microtime() . getmypid();
        $address->city = 'Levallois';
        self::assertTrue($address->save());

        return $address;
    }

    private static function makeCart(): Cart
    {
        $cart = new Cart(null, (int) Configuration::get('PS_LANG_DEFAULT'));
        $cart->id_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        $cart->id_address_invoice = self::$id_address;
        self::assertTrue($cart->save());
        Context::getContext()->cart = $cart;

        return $cart;
    }

    /**
     * null $shippingCost is interpreted as free shipping
     * Carriers are cached by $name.
     */
    private static function getIdCarrier(string $name, int $shippingCost = null, int $id_tax_rules_group = null): int
    {
        static $carriers = [];

        if (!array_key_exists($name, $carriers)) {
            $carrier = new Carrier(null, (int) Configuration::get('PS_LANG_DEFAULT'));

            $carrier->name = $name;
            $carrier->delay = '28 days later';

            if (null === $shippingCost) {
                $carrier->is_free = true;
            } else {
                $carrier->range_behavior = false; // take highest range
                $carrier->shipping_method = Carrier::SHIPPING_METHOD_PRICE;
            }

            $carrier->shipping_handling = false;

            self::assertTrue($carrier->save());

            if (null !== $id_tax_rules_group) {
                $carrier->setTaxRulesGroup($id_tax_rules_group);
            }

            if (null !== $shippingCost) {
                // Populate one range
                Db::getInstance()->execute('INSERT INTO ' . _DB_PREFIX_ . 'range_price (id_carrier, delimiter1, delimiter2) VALUES (
                    ' . (int) $carrier->id . ',
                    0,1
                )');

                $id_range_price = Db::getInstance()->Insert_ID();
                self::assertGreaterThan(0, $id_range_price);

                // apply our shippingCost to all zones
                Db::getInstance()->execute(
                    'INSERT INTO ' . _DB_PREFIX_ . 'delivery (id_carrier, id_range_price, id_range_weight, id_zone, price)
                     SELECT ' . (int) $carrier->id . ', ' . (int) $id_range_price . ', 0, id_zone, ' . (float) $shippingCost . '
                     FROM ' . _DB_PREFIX_ . 'zone'
                );

                // enable all zones
                Db::getInstance()->execute(
                    'INSERT INTO ' . _DB_PREFIX_ . 'carrier_zone (id_carrier, id_zone)
                     SELECT ' . (int) $carrier->id . ', id_zone FROM ' . _DB_PREFIX_ . 'zone'
                );
            }

            $carriers[$name] = (int) $carrier->id;
        }

        return $carriers[$name];
    }

    private static function makeCartRule(int $amount, string $type): CartRule
    {
        $cartRule = new CartRule(null, (int) Configuration::get('PS_LANG_DEFAULT'));

        $cartRule->name = $amount . ' ' . $type . ' Cart Rule';

        $date_from = new \DateTime();
        $date_to = new \DateTime();

        $date_from->modify('-2 days');
        $date_to->modify('+2 days');

        $cartRule->date_from = $date_from->format('Y-m-d H:i:s');
        $cartRule->date_to = $date_to->format('Y-m-d H:i:s');

        $cartRule->quantity = 1;

        if ($type === 'before tax') {
            $cartRule->reduction_amount = $amount;
            $cartRule->reduction_tax = false;
        } elseif ($type === 'after tax') {
            $cartRule->reduction_amount = $amount;
            $cartRule->reduction_tax = true;
        } elseif ($type === '%') {
            $cartRule->reduction_percent = $amount;
        } else {
            throw new Exception(sprintf('Invalid CartRule type `%s`.', $type));
        }

        self::assertTrue($cartRule->save());

        return $cartRule;
    }

    /**
     * Provide sensible defaults for tests that don't specify them.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Context needs a currency but doesn't set it by itself, use default one.
        Context::getContext()->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));

        Group::clearCachedValues();
        self::setRoundingType('line');
        self::setRoundingMode('half_up');
        Configuration::set('PS_PRICE_DISPLAY_PRECISION', 2);
        // Pre-existing cart rules might mess up our test
        Db::getInstance()->execute('UPDATE ' . _DB_PREFIX_ . 'cart_rule SET active = 0');
        // Something might have disabled CartRules :)
        Configuration::set('PS_CART_RULE_FEATURE_ACTIVE', true);
        Configuration::set('PS_GROUP_FEATURE_ACTIVE', true);
        Configuration::set('PS_ATCP_SHIPWRAP', false);
    }

    public function testBasicOnlyProducts(): void
    {
        $product = self::makeProduct('Hello Product', 10, self::getIdTaxRulesGroup(20));
        $cart = self::makeCart();

        $cart->updateQty(1, $product->id);

        $this->assertEquals(10, $cart->getOrderTotal(false, Cart::ONLY_PRODUCTS));
        $this->assertEquals(12, $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS));
    }

    public function testCartBothWithFreeCarrier(): void
    {
        $product = self::makeProduct('Hello Product', 10, self::getIdTaxRulesGroup(20));
        $cart = self::makeCart();

        $id_carrier = self::getIdCarrier('free');

        $cart->updateQty(1, $product->id);
        $this->assertEquals(10, $cart->getOrderTotal(false, Cart::BOTH, null, $id_carrier));
        $this->assertEquals(12, $cart->getOrderTotal(true, Cart::BOTH, null, $id_carrier));
    }

    public function testCartBothWithPaidCarrier(): void
    {
        $product = self::makeProduct('Hello Product', 10, self::getIdTaxRulesGroup(10));
        $cart = self::makeCart();

        $id_carrier = self::getIdCarrier('costs 2', 2, self::getIdTaxRulesGroup(10));

        $cart->updateQty(1, $product->id);
        $this->assertEquals(12, $cart->getOrderTotal(false, Cart::BOTH, null, $id_carrier));
        $this->assertEquals(13.2, $cart->getOrderTotal(true, Cart::BOTH, null, $id_carrier));
    }

    public function testBasicRoundTypeLine(): void
    {
        self::setRoundingType('line');

        $product_a = self::makeProduct('A Product', 1.236, self::getIdTaxRulesGroup(20));
        $product_b = self::makeProduct('B Product', 2.345, self::getIdTaxRulesGroup(20));

        $cart = self::makeCart();

        $cart->updateQty(1, $product_a->id);
        $cart->updateQty(1, $product_b->id);

        $this->assertEquals(3.59, $cart->getOrderTotal(false, Cart::ONLY_PRODUCTS));
        $this->assertEquals(4.29, $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS));
    }

    public function testBasicRoundTypeTotal(): void
    {
        self::setRoundingType('total');

        $product_a = self::makeProduct('A Product', 1.236, self::getIdTaxRulesGroup(20));
        $product_b = self::makeProduct('B Product', 2.345, self::getIdTaxRulesGroup(20));

        $cart = self::makeCart();

        $cart->updateQty(1, $product_a->id);
        $cart->updateQty(1, $product_b->id);

        $this->assertEquals(3.58, $cart->getOrderTotal(false, Cart::ONLY_PRODUCTS));
        $this->assertEquals(4.3, $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS));
    }

    public function testBasicCartRuleAmountBeforeTax(): void
    {
        $id_carrier = self::getIdCarrier('free');

        $product = self::makeProduct('Yo Product', 10, self::getIdTaxRulesGroup(20));

        self::makeCartRule(5, 'before tax');
        $cart = self::makeCart();

        $cart->updateQty(1, $product->id);

        // Control the result without the CartRule
        $this->assertEquals(10, $cart->getOrderTotal(false, Cart::ONLY_PRODUCTS));

        // Check that the CartRule is applied
        $this->assertEquals(5, $cart->getOrderTotal(false, Cart::BOTH, null, $id_carrier));
        $this->assertEquals(6, $cart->getOrderTotal(true, Cart::BOTH, null, $id_carrier));
    }

    /**
     * This test checks that if PS_ATCP_SHIPWRAP is set to true then:
     * - the shipping cost of the carrier is understood as tax included instead of tax excluded
     * - the tax excluded shipping cost is deduced from the tax included shipping cost
     * 	 by removing the average tax rate of the cart
     */
    public function testAverageTaxOfCartProductsShippingTax(): void
    {
        Configuration::set('PS_ATCP_SHIPWRAP', true);

        $highProduct = self::makeProduct('High Product', 10, self::getIdTaxRulesGroup(20));
        $lowProduct = self::makeProduct('Low Product', 10, self::getIdTaxRulesGroup(10));
        $cart = self::makeCart();

        $id_carrier = self::getIdCarrier('costs 5 with tax', 5, null);

        $cart->updateQty(1, $highProduct->id);
        $cart->updateQty(3, $lowProduct->id);

        $preTax = round(5 / (1 + (3 * 10 + 1 * 20) / (4 * 100)), 2);

        $this->assertEquals($preTax, $cart->getOrderTotal(false, Cart::ONLY_SHIPPING, null, $id_carrier));
        $this->assertEquals(5, $cart->getOrderTotal(true, Cart::ONLY_SHIPPING, null, $id_carrier));
    }

    /**
     * Check getOrderTotal return the same value with and without when PS_TAX is disable
     */
    public function testSameTotalWithoutTax(): void
    {
        Configuration::set('PS_TAX', false);
        $product = self::makeProduct('Hello Product', 10, self::getIdTaxRulesGroup(20));
        $cart = self::makeCart();

        $cart->updateQty(1, $product->id);

        $this->assertEquals(
            $cart->getOrderTotal(false, Cart::ONLY_PRODUCTS),
            $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS)
        );

        $this->assertEquals(
            $cart->getOrderTotal(false, Cart::BOTH),
            $cart->getOrderTotal(true, Cart::BOTH)
        );

        $this->assertEquals(
            $cart->getOrderTotal(false, Cart::ONLY_SHIPPING),
            $cart->getOrderTotal(true, Cart::ONLY_SHIPPING)
        );

        $this->assertEquals(
            $cart->getOrderTotal(false, Cart::ONLY_DISCOUNTS),
            $cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS)
        );
    }
}
