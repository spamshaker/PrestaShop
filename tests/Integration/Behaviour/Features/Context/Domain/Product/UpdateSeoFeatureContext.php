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
use PHPUnit\Framework\Assert;
use PrestaShop\PrestaShop\Core\Domain\Product\Command\UpdateProductSeoCommand;
use PrestaShop\PrestaShop\Core\Domain\Product\Exception\ProductException;
use PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductRedirectOption;

class UpdateSeoFeatureContext extends AbstractProductFeatureContext
{
    /**
     * @When I update product :productReference SEO information with following values:
     *
     * @param string $productReference
     * @param TableNode $tableNode
     */
    public function updateSeo(string $productReference, TableNode $tableNode)
    {
        $dataRows = $tableNode->getRowsHash();
        $productId = $this->getSharedStorage()->get($productReference);

        try {
            $command = new UpdateProductSeoCommand($productId);
            $unhandledData = $this->fillUpdateSeoCommand($dataRows, $command);
            Assert::assertEmpty(
                $unhandledData,
                sprintf('Not all provided data was handled in scenario. Unhandled: %s', var_export($unhandledData, true))
            );
            $this->getCommandBus()->handle($command);
        } catch (ProductException $e) {
            $this->setLastException($e);
        }
    }

    /**
     * @Then product :productReference should not have a redirect target
     *
     * @param string $productReference
     */
    public function assertHasNoRedirectTargetId(string $productReference)
    {
        $productForEditing = $this->getProductForEditing($productReference);

        Assert::assertEquals(
            ProductRedirectOption::NO_TARGET_VALUE,
            $productForEditing->getProductSeoInformation()->getRedirectTargetId(),
            'Product "%s" expected to have no redirect target'
        );
    }

    /**
     * @Then product :productReference redirect target should be :targetReference
     *
     * @param string $productReference
     * @param string $targetReference
     */
    public function assertRedirectTarget(string $productReference, string $targetReference)
    {
        $productSeo = $this->getProductForEditing($productReference)->getProductSeoInformation();
        $targetId = $this->getSharedStorage()->get($targetReference);

        Assert::assertEquals($targetId, $productSeo->getRedirectTargetId(), 'Unexpected product redirect target');
    }

    /**
     * Fills command with data and returns all additional data that wasn't handled if there is any
     *
     * @param array $dataRows
     * @param UpdateProductSeoCommand $command
     *
     * @return array
     */
    private function fillUpdateSeoCommand(array $dataRows, UpdateProductSeoCommand $command): array
    {
        if (isset($dataRows['meta_title'])) {
            $command->setLocalizedMetaTitles($this->parseLocalizedArray($dataRows['meta_title']));
            unset($dataRows['meta_title']);
        }

        if (isset($dataRows['meta_description'])) {
            $command->setLocalizedMetaDescriptions($this->parseLocalizedArray($dataRows['meta_description']));
            unset($dataRows['meta_description']);
        }

        if (isset($dataRows['link_rewrite'])) {
            $command->setLocalizedLinkRewrites($this->parseLocalizedArray($dataRows['link_rewrite']));
            unset($dataRows['link_rewrite']);
        }

        if (isset($dataRows['redirect_type'], $dataRows['redirect_target'])) {
            if ($this->getSharedStorage()->exists($dataRows['redirect_target'])) {
                $targetId = $this->getSharedStorage()->get($dataRows['redirect_target']);
            }

            $command->setRedirectOption($dataRows['redirect_type'], $targetId ?? 0);
            unset($dataRows['redirect_type'], $dataRows['redirect_target']);
        }

        return $dataRows;
    }
}
