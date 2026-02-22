<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\DTO\SetPurchasePriceCommand;
use App\Catalog\Domain\PurchasePriceTimelinePolicy;
use App\Catalog\Entity\ProductPurchasePrice;
use App\Catalog\Infrastructure\ProductRepository;
use App\Catalog\Infrastructure\Repository\ProductPurchasePriceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

final class SetPurchasePriceAction
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly ProductPurchasePriceRepository $productPurchasePriceRepository,
        private readonly PurchasePriceTimelinePolicy $purchasePriceTimelinePolicy,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(SetPurchasePriceCommand $command): string
    {
        $this->assertCommandIsValid($command);

        $product = $this->productRepository->getOneForCompanyByIdsOrNull($command->companyId, $command->productId);
        if (null === $product) {
            throw new NotFoundHttpException();
        }

        $activePrice = $this->productPurchasePriceRepository->findActiveAtDate(
            $command->companyId,
            $command->productId,
            $command->effectiveFrom,
        );

        $nextPrice = $this->productPurchasePriceRepository->findNextAfterDate(
            $command->companyId,
            $command->productId,
            $command->effectiveFrom,
        );

        $this->purchasePriceTimelinePolicy->assertNoOverlapWithNext(
            $nextPrice?->getEffectiveFrom(),
            $command->effectiveFrom,
        );

        if (null !== $activePrice) {
            // Закрываем предыдущий активный интервал на день раньше новой цены.
            $activePrice->closeAt($command->effectiveFrom->sub(new \DateInterval('P1D')));
        }

        $newPrice = new ProductPurchasePrice(
            id: Uuid::v7()->toRfc4122(),
            company: $product->getCompany(),
            product: $product,
            effectiveFrom: $command->effectiveFrom,
            priceAmount: $command->priceAmount,
            priceCurrency: strtoupper($command->currency),
            note: $command->note,
        );

        $this->entityManager->persist($newPrice);
        $this->entityManager->flush();

        return $newPrice->getId();
    }

    private function assertCommandIsValid(SetPurchasePriceCommand $command): void
    {
        if ('' === trim($command->companyId ?? '')) {
            throw new \DomainException('companyId обязателен.');
        }

        if ('' === trim($command->productId ?? '')) {
            throw new \DomainException('productId обязателен.');
        }

        if ($command->priceAmount < 0) {
            throw new \DomainException('priceAmount не может быть отрицательным.');
        }

        if (3 !== mb_strlen(trim($command->currency ?? ''))) {
            throw new \DomainException('currency должен содержать 3 символа.');
        }
    }
}
