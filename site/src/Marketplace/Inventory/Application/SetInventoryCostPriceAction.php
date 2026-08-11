<?php

declare(strict_types=1);

namespace App\Marketplace\Inventory\Application;

use App\Marketplace\Entity\Inventory\MarketplaceInventoryCostPrice;
use App\Marketplace\Inventory\Application\Command\SetInventoryCostPriceCommand;
use App\Marketplace\Inventory\Application\DTO\SetInventoryCostPriceResult;
use App\Marketplace\Inventory\Infrastructure\Repository\MarketplaceInventoryCostPriceRepository;
use App\Marketplace\Repository\MarketplaceListingRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Устанавливает себестоимость листинга на дату.
 *
 * Себестоимость привязана к листингу — не к продукту.
 * Листинг без привязки к продукту тоже может иметь себестоимость.
 *
 * Логика таймлайна:
 *   1. Перезаписываем существующую запись на точную дату
 *   2. Закрываем активную запись: effectiveTo = effectiveFrom - 1 день
 *   3. Создаём новую запись
 */
final class SetInventoryCostPriceAction
{
    public function __construct(
        private readonly MarketplaceListingRepository $listingRepository,
        private readonly MarketplaceInventoryCostPriceRepository $costPriceRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(SetInventoryCostPriceCommand $command): SetInventoryCostPriceResult
    {
        $this->assertCommandIsValid($command);
        $effectiveFrom = $command->effectiveFrom->setTime(0, 0);

        // Получаем листинг с проверкой принадлежности к компании до открытия транзакции:
        // импорт обрабатывает ошибки отдельных строк и продолжает работу с EntityManager.
        $listing = $this->listingRepository->findByIdAndCompany(
            $command->listingId,
            $command->companyId,
        );

        if (null === $listing) {
            throw new NotFoundHttpException('Листинг не найден.');
        }

        return $this->em->wrapInTransaction(function () use ($command, $effectiveFrom, $listing): SetInventoryCostPriceResult {
            $this->em->lock($listing, LockMode::PESSIMISTIC_WRITE);

            $existingPrice = $this->costPriceRepository->findAtExactDate(
                $command->companyId,
                $command->listingId,
                $effectiveFrom,
            );

            if (null !== $existingPrice) {
                $existingPrice->overwrite($command->priceAmount, $command->currency, $command->note);

                return new SetInventoryCostPriceResult($existingPrice->getId(), true);
            }

            // Закрываем предыдущую активную запись
            $activePrice = $this->costPriceRepository->findActiveAtDate(
                $command->companyId,
                $command->listingId,
                $effectiveFrom,
            );

            if (null !== $activePrice) {
                $activePrice->closeAt(
                    $effectiveFrom->sub(new \DateInterval('P1D')),
                );
            }

            // Создаём новую запись
            $newPrice = new MarketplaceInventoryCostPrice(
                id: Uuid::uuid7()->toString(),
                companyId: $command->companyId,
                listing: $listing,
                effectiveFrom: $effectiveFrom,
                priceAmount: $command->priceAmount,
                priceCurrency: strtoupper($command->currency),
                note: $command->note,
            );

            $this->em->persist($newPrice);

            return new SetInventoryCostPriceResult($newPrice->getId(), false);
        });
    }

    private function assertCommandIsValid(SetInventoryCostPriceCommand $command): void
    {
        if (trim($command->companyId) === '') {
            throw new \DomainException('companyId обязателен.');
        }

        if (trim($command->listingId) === '') {
            throw new \DomainException('listingId обязателен.');
        }

        if (!is_numeric($command->priceAmount) || (float) $command->priceAmount < 0) {
            throw new \DomainException('priceAmount должен быть неотрицательным числом.');
        }

        if (mb_strlen(trim($command->currency)) !== 3) {
            throw new \DomainException('currency должен содержать 3 символа.');
        }
    }
}
