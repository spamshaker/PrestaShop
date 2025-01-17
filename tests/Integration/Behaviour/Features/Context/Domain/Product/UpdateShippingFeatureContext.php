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

namespace Tests\Integration\Behaviour\Features\Context\Domain\Product;

use Behat\Gherkin\Node\TableNode;
use Carrier;
use PHPUnit\Framework\Assert;
use PrestaShop\Decimal\DecimalNumber;
use PrestaShop\PrestaShop\Core\Domain\Product\Command\SetCarriersCommand;
use PrestaShop\PrestaShop\Core\Domain\Product\Command\UpdateProductShippingCommand;
use PrestaShop\PrestaShop\Core\Domain\Product\Exception\ProductException;
use PrestaShop\PrestaShop\Core\Domain\Product\QueryResult\ProductShippingInformation;
use PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\DeliveryTimeNoteType;
use PrestaShop\PrestaShop\Core\Domain\Shop\ValueObject\ShopConstraint;
use RuntimeException;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Tests\Integration\Behaviour\Features\Context\Util\PrimitiveUtils;

class UpdateShippingFeatureContext extends AbstractProductFeatureContext
{
    /**
     * @When I update product :productReference shipping information with following values:
     *
     * @param string $productReference
     * @param TableNode $table
     */
    public function updateProductShipping(string $productReference, TableNode $table): void
    {
        $this->updateShipping($productReference, $table, ShopConstraint::shop($this->getDefaultShopId()));
    }

    /**
     * @When I update product :productReference shipping information for shop :shopReference with following values:
     *
     * @param string $productReference
     * @param string $shopReference
     * @param TableNode $table
     */
    public function updateProductShippingForShop(string $productReference, string $shopReference, TableNode $table): void
    {
        $shopId = $this->getSharedStorage()->get(trim($shopReference));
        $this->updateShipping($productReference, $table, ShopConstraint::shop($shopId));
    }

    /**
     * @When I update product :productReference shipping information for all shops with following values:
     *
     * @param string $productReference
     * @param TableNode $table
     */
    public function updateProductShippingForAllShops(string $productReference, TableNode $table): void
    {
        $this->updateShipping($productReference, $table, ShopConstraint::allShops());
    }

    /**
     * @When I assign product :productReference with following carriers:
     *
     * @param string $productReference
     * @param TableNode $table
     */
    public function setProductCarriersForDefaultShop(string $productReference, TableNode $table): void
    {
        $this->setCarriers($productReference, $table, ShopConstraint::shop($this->getDefaultShopId()));
    }

    /**
     * @When I assign product :productReference with following carriers for shop :shopReference:
     *
     * @param string $productReference
     * @param string $shopReference
     * @param TableNode $table
     */
    public function setProductCarriersForShop(string $productReference, string $shopReference, TableNode $table): void
    {
        $this->setCarriers($productReference, $table, ShopConstraint::shop((int) $this->getSharedStorage()->get($shopReference)));
    }

    /**
     * @When I assign product :productReference with following carriers for all shops:
     *
     * @param string $productReference
     * @param TableNode $table
     */
    public function setProductCarriersForAllShops(string $productReference, TableNode $table): void
    {
        $this->setCarriers($productReference, $table, ShopConstraint::allShops());
    }

    /**
     * @param string $productReference
     * @param TableNode $table
     * @param ShopConstraint $shopConstraint
     */
    private function setCarriers(string $productReference, TableNode $table, ShopConstraint $shopConstraint): void
    {
        $carrierReferences = $this->getCarrierReferenceIds(array_keys($table->getRowsHash()));

        $this->getCommandBus()->handle(new SetCarriersCommand(
            (int) $this->getSharedStorage()->get($productReference),
            $carrierReferences,
            $shopConstraint
        ));
    }

    /**
     * @Then product :productReference should have no carriers assigned
     *
     * @param string $productReference
     */
    public function assertProductHasNoCarriers(string $productReference): void
    {
        $productForEditing = $this->getProductForEditing($productReference);

        Assert::assertEmpty(
            $productForEditing->getShippingInformation()->getCarrierReferences(),
            sprintf('Expected product "%s" to have no carriers assigned', $productReference)
        );
    }

    /**
     * @Then product :productReference should have following shipping information:
     *
     * @param string $productReference
     * @param TableNode $tableNode
     */
    public function assertShippingInformationForDefaultShop(string $productReference, TableNode $tableNode): void
    {
        $this->assertShippingInfo($productReference, $tableNode);
    }

    /**
     * @Then product :productReference should have following shipping information for shops ":shopReferences":
     *
     * @param string $productReference
     * @param TableNode $tableNode
     * @param string $shopReferences
     */
    public function assertShippingInfoForShops(string $productReference, TableNode $tableNode, string $shopReferences): void
    {
        $shopReferences = explode(',', $shopReferences);

        foreach ($shopReferences as $shopReference) {
            $shopId = $this->getSharedStorage()->get(trim($shopReference));
            $this->assertShippingInfo($productReference, $tableNode, $shopId);
        }
    }

    /**
     * @param string $productReference
     * @param TableNode $table
     * @param ShopConstraint $shopConstraint
     */
    private function updateShipping(string $productReference, TableNode $table, ShopConstraint $shopConstraint): void
    {
        $data = $this->localizeByRows($table);
        $productId = $this->getSharedStorage()->get($productReference);

        try {
            $command = new UpdateProductShippingCommand($productId, $shopConstraint);
            $unhandledData = $this->setUpdateShippingCommandData($data, $command);

            Assert::assertEmpty(
                $unhandledData,
                sprintf('Not all provided values handled in scenario. %s', var_export($unhandledData, true))
            );

            $this->getCommandBus()->handle($command);
        } catch (ProductException $e) {
            $this->setLastException($e);
        }
    }

    /**
     * @param array $expectedValues
     * @param ProductShippingInformation $actualValues
     */
    private function assertNumberShippingFields(array &$expectedValues, ProductShippingInformation $actualValues): void
    {
        $numberShippingFields = [
            'width',
            'height',
            'depth',
            'weight',
            'additional_shipping_cost',
        ];

        $propertyAccessor = PropertyAccess::createPropertyAccessor();

        foreach ($numberShippingFields as $field) {
            if (isset($expectedValues[$field])) {
                $expectedNumber = new DecimalNumber((string) $expectedValues[$field]);
                $actualNumber = $propertyAccessor->getValue($actualValues, $field);

                if (!$expectedNumber->equals($actualNumber)) {
                    throw new RuntimeException(
                        sprintf('Product %s expected to be "%s", but is "%s"', $field, $expectedNumber, $actualNumber)
                    );
                }

                unset($expectedValues[$field]);
            }
        }
    }

    /**
     * @param string $productReference
     * @param TableNode $tableNode
     * @param int|null $shopId
     */
    private function assertShippingInfo(string $productReference, TableNode $tableNode, ?int $shopId = null): void
    {
        $data = $this->localizeByRows($tableNode);
        $productShippingInformation = $this->getProductForEditing(
            $productReference,
            $shopId
        )->getShippingInformation();

        if (isset($data['carriers'])) {
            $expectedReferenceIds = $this->getCarrierReferenceIds(PrimitiveUtils::castStringArrayIntoArray($data['carriers']));
            $actualReferenceIds = $productShippingInformation->getCarrierReferences();

            Assert::assertEquals(
                $expectedReferenceIds,
                $actualReferenceIds,
                'Unexpected carrier references in product shipping information'
            );

            unset($data['carriers']);
        }

        $this->assertNumberShippingFields($data, $productShippingInformation);
        $this->assertDeliveryTimeNotes($data, $productShippingInformation);

        // Assertions checking isset() can hide some errors if it doesn't find array key,
        // to make sure all provided fields were checked we need to unset every asserted field
        // and finally, if provided data is not empty, it means there are some unnasserted values left
        Assert::assertEmpty($data, sprintf('Some provided product shipping fields haven\'t been asserted: %s', var_export($data, true)));
    }

    /**
     * @param array $data
     * @param ProductShippingInformation $productShippingInformation
     */
    private function assertDeliveryTimeNotes(array &$data, ProductShippingInformation $productShippingInformation): void
    {
        $notesTypeNamedValues = [
            'none' => DeliveryTimeNoteType::TYPE_NONE,
            'default' => DeliveryTimeNoteType::TYPE_DEFAULT,
            'specific' => DeliveryTimeNoteType::TYPE_SPECIFIC,
        ];

        if (isset($data['delivery time notes type'])) {
            $expectedType = $notesTypeNamedValues[$data['delivery time notes type']];
            $actualType = $productShippingInformation->getDeliveryTimeNoteType();
            Assert::assertEquals($expectedType, $actualType, 'Unexpected delivery time notes type value');

            unset($data['delivery time notes type']);
        }

        if (isset($data['delivery time in stock notes'])) {
            $actualLocalizedOutOfStockNotes = $productShippingInformation->getLocalizedDeliveryTimeInStockNotes();
            Assert::assertEquals(
                $data['delivery time in stock notes'],
                $actualLocalizedOutOfStockNotes,
                'Unexpected product delivery time in stock notes'
            );

            unset($data['delivery time in stock notes']);
        }

        if (isset($data['delivery time out of stock notes'])) {
            $actualLocalizedOutOfStockNotes = $productShippingInformation->getLocalizedDeliveryTimeOutOfStockNotes();
            Assert::assertEquals(
                $data['delivery time out of stock notes'],
                $actualLocalizedOutOfStockNotes,
                'Unexpected product delivery time out of stock notes'
            );

            unset($data['delivery time out of stock notes']);
        }
    }

    /**
     * @param array $data
     * @param UpdateProductShippingCommand $command
     *
     * @return array values that was provided, but wasn't handled
     */
    private function setUpdateShippingCommandData(array $data, UpdateProductShippingCommand $command): array
    {
        $unhandledValues = $data;

        if (isset($data['width'])) {
            $command->setWidth($data['width']);
            unset($unhandledValues['width']);
        }

        if (isset($data['height'])) {
            $command->setHeight($data['height']);
            unset($unhandledValues['height']);
        }

        if (isset($data['depth'])) {
            $command->setDepth($data['depth']);
            unset($unhandledValues['depth']);
        }

        if (isset($data['weight'])) {
            $command->setWeight($data['weight']);
            unset($unhandledValues['weight']);
        }

        if (isset($data['additional_shipping_cost'])) {
            $command->setAdditionalShippingCost($data['additional_shipping_cost']);
            unset($unhandledValues['additional_shipping_cost']);
        }

        if (isset($data['delivery time notes type'])) {
            $command->setDeliveryTimeNoteType(DeliveryTimeNoteType::ALLOWED_TYPES[$data['delivery time notes type']]);
            unset($unhandledValues['delivery time notes type']);
        }

        if (isset($data['delivery time in stock notes'])) {
            $command->setLocalizedDeliveryTimeInStockNotes($data['delivery time in stock notes']);
            unset($unhandledValues['delivery time in stock notes']);
        }

        if (isset($data['delivery time out of stock notes'])) {
            $command->setLocalizedDeliveryTimeOutOfStockNotes($data['delivery time out of stock notes']);
            unset($unhandledValues['delivery time out of stock notes']);
        }

        return $unhandledValues;
    }

    /**
     * @param string[] $carrierReferences
     *
     * @return int[]
     */
    private function getCarrierReferenceIds(array $carrierReferences): array
    {
        $referenceIds = [];
        foreach ($carrierReferences as $carrierReference) {
            $carrier = new Carrier($this->getSharedStorage()->get($carrierReference));
            $referenceIds[] = (int) $carrier->id_reference;
        }

        return $referenceIds;
    }
}
