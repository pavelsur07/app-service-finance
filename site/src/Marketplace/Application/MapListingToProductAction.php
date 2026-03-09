<?php

namespace App\Marketplace\Application;

use App\Catalog\Facade\ProductFacade;
use App\Marketplace\DTO\MapListingToProductCommand;
use App\Marketplace\Repository\MarketplaceListingRepository;
use Psr\Log\LoggerInterface;

/**
 * Связать листинг маркетплейса с продуктом.
 *
 * Бизнес-правило: один товар — один листинг на маркетплейс в рамках компании.
 * Разные маркетплейсы могут быть привязаны к одному товару.
 */
final class MapListingToProductAction
{
    public function __construct(
        private readonly MarketplaceListingRepository $listingRepository,
        private readonly ProductFacade $productFacade,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws \InvalidArgumentException если листинг или продукт не найдены или не принадлежат компании
     * @throws \LogicException если нарушено бизнес-правило уникальности
     */
    public function __invoke(MapListingToProductCommand $cmd): void
    {
        $listing = $this->listingRepository->findByIdAndCompany(
            $cmd->listingId,
            $cmd->companyId,
        );

        if (!$listing) {
            throw new \InvalidArgumentException(
                "Listing not found or does not belong to company: {$cmd->listingId}",
            );
        }

        $product = $this->productFacade->findByIdAndCompany(
            $cmd->productId,
            $cmd->companyId,
        );

        if (!$product) {
            throw new \InvalidArgumentException(
                "Product not found or does not belong to company: {$cmd->productId}",
            );
        }

        // Проверяем бизнес-правило: товар уже привязан к другому листингу этого маркетплейса?
        $existingListing = $this->listingRepository->findByProductAndMarketplace(
            $cmd->companyId,
            $listing->getMarketplace(),
            $cmd->productId,
            $cmd->listingId, // исключаем текущий листинг (переназначение разрешено)
        );

        if ($existingListing !== null) {
            throw new \LogicException(sprintf(
                'Товар "%s" уже привязан к листингу %s (%s) на маркетплейсе %s. '
                . 'Один товар может быть привязан только к одному листингу на маркетплейс.',
                $product->getSku(),
                $existingListing->getMarketplaceSku(),
                $existingListing->getSize(),
                $listing->getMarketplace()->value,
            ));
        }

        $listing->setProduct($product);
        $this->listingRepository->save($listing);

        $this->logger->info('Listing mapped to product', [
            'company_id'    => $cmd->companyId,
            'actor_user_id' => $cmd->actorUserId,
            'listing_id'    => $cmd->listingId,
            'product_id'    => $cmd->productId,
            'marketplace'   => $listing->getMarketplace()->value,
            'nm_id'         => $listing->getMarketplaceSku(),
            'size'          => $listing->getSize(),
            'sku'           => $listing->getSupplierSku(),
        ]);
    }
}
