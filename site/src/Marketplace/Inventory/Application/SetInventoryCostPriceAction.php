<?php

declare(strict_types=1);

namespace App\Marketplace\Inventory\Application;

use App\Marketplace\Entity\Inventory\MarketplaceInventoryCostPrice;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Inventory\Application\Command\SetInventoryCostPriceCommand;
use App\Marketplace\Inventory\Infrastructure\Repository\MarketplaceInventoryCostPriceRepository;
use App\Marketplace\Repository\MarketplaceListingRepository;
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
 *   1. Проверяем отсутствие перекрытия со следующей записью
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

    public function __invoke(SetInventoryCostPriceCommand $command): string
    {
        $this->assertCommandIsValid($command);

        // Получаем листинг с проверкой принадлежности к компании
        $listing = $this->listingRepository->findByIdAndCompany(
            $command->listingId,
            $command->companyId,
        );

        if ($listing === null) {
            throw new NotFoundHttpException('Листинг не найден.');
        }

        // Проверяем нет ли перекрытия со следующей по дате записью
        $nextPrice = $this->costPriceRepository->findNextAfterDate(
            $command->companyId,
            $command->listingId,
            $command->effectiveFrom,
        );

        if ($nextPrice !== null && $nextPrice->getEffectiveFrom() <= $command->effectiveFrom) {
            throw new \DomainException(sprintf(
                'Дата %s перекрывается с существующей записью от %s.',
                $command->effectiveFrom->format('d.m.Y'),
                $nextPrice->getEffectiveFrom()->format('d.m.Y'),
            ));
        }

        // Закрываем предыдущую активную запись
        $activePrice = $this->costPriceRepository->findActiveAtDate(
            $command->companyId,
            $command->listingId,
            $command->effectiveFrom,
        );

        if ($activePrice !== null) {
            $activePrice->closeAt(
                $command->effectiveFrom->sub(new \DateInterval('P1D')),
            );
        }

        // Создаём новую запись
        $newPrice = new MarketplaceInventoryCostPrice(
            id:            Uuid::uuid7()->toString(),
            companyId:     $command->companyId,
            listing:       $listing,
            effectiveFrom: $command->effectiveFrom,
            priceAmount:   $command->priceAmount,
            priceCurrency: strtoupper($command->currency),
            note:          $command->note,
        );

        $this->em->persist($newPrice);
        $this->em->flush();

        return $newPrice->getId();
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
