<?php

namespace App\Marketplace\Application;

use App\Catalog\Repository\ProductRepository;
use App\Marketplace\DTO\MapListingToProductCommand;
use App\Marketplace\Repository\MarketplaceListingRepository;
use Psr\Log\LoggerInterface;

/**
 * Связать листинг маркетплейса с продуктом
 *
 * ВАЖНО - БЕЗОПАСНОСТЬ:
 * - Использует findByIdAndCompany (НЕ find!)
 * - ВСЕГДА проверяет принадлежность к companyId из Command
 * - Не зависит от HTTP/Session
 * - Работает в worker/CLI
 */
final class MapListingToProductAction
{
    public function __construct(
        private readonly MarketplaceListingRepository $listingRepository,
        private readonly ProductRepository $productRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @throws \InvalidArgumentException если листинг или продукт не найдены или не принадлежат компании
     * @throws \LogicException если листинг уже смаппен
     */
    public function __invoke(MapListingToProductCommand $cmd): void
    {
        // ✅ БЕЗОПАСНО: findByIdAndCompany проверяет company_id!
        $listing = $this->listingRepository->findByIdAndCompany(
            $cmd->listingId,
            $cmd->companyId
        );

        if (!$listing) {
            // Листинг не найден ИЛИ не принадлежит компании
            throw new \InvalidArgumentException(
                "Listing not found or does not belong to company: {$cmd->listingId}"
            );
        }

        // Проверяем что листинг еще не смаппен
        if ($listing->getProduct() !== null) {
            throw new \LogicException('Listing is already mapped to a product');
        }

        // ✅ БЕЗОПАСНО: findByIdAndCompany проверяет company_id!
        $product = $this->productRepository->findByIdAndCompany(
            $cmd->productId,
            $cmd->companyId
        );

        if (!$product) {
            // Продукт не найден ИЛИ не принадлежит компании
            throw new \InvalidArgumentException(
                "Product not found or does not belong to company: {$cmd->productId}"
            );
        }

        // Связываем (оба уже проверены что принадлежат companyId!)
        $listing->setProduct($product);
        $this->listingRepository->save($listing);

        $this->logger->info('Listing mapped to product', [
            'company_id' => $cmd->companyId,
            'actor_user_id' => $cmd->actorUserId,
            'listing_id' => $cmd->listingId,
            'product_id' => $cmd->productId,
            'nm_id' => $listing->getNmId(),
            'sku' => $listing->getSku(),
        ]);
    }
}
