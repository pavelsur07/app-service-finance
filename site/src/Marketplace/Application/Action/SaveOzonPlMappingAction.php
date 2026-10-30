<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Action;

use App\Marketplace\Entity\MarketplaceSaleMapping;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceSaleMappingRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Only Ozon writes use this guard; other marketplaces keep their existing path. */
final readonly class SaveOzonPlMappingAction
{
    public function __construct(private EntityManagerInterface $em, private MarketplaceSaleMappingRepository $mappings)
    {
    }

    public function __invoke(MarketplaceSaleMapping $mapping): void
    {
        if (MarketplaceType::OZON !== $mapping->getMarketplace()) {
            throw new \InvalidArgumentException('Ожидалось правило Ozon.');
        }
        $this->em->wrapInTransaction(function () use ($mapping): void {
            $companyId = (string) $mapping->getCompany()->getId();
            $connection = $this->em->getConnection();
            $connection->executeQuery('SELECT pg_advisory_xact_lock(hashtext(:key))', ['key' => 'ozon_pl_mapping:'.$companyId]);
            if ($mapping->isActive()) {
                $source = $mapping->getAmountSource();
                $code = $mapping->getPlCategory()->getCode();
                if (('REV_SPP_SALES' === $code && AmountSource::SALE_REALIZATION !== $source)
                    || ('REV_SPP_RETURNS' === $code && AmountSource::RETURN_REALIZATION !== $source)) {
                    throw new \DomainException('Для статьи с СПП выберите отчёт «Реализация» Ozon. Начисления без СПП нельзя включать в эту статью.');
                }
                $otherSource = match ($source) {
                    AmountSource::SALE_REVENUE => AmountSource::SALE_REALIZATION,
                    AmountSource::SALE_REALIZATION => AmountSource::SALE_REVENUE,
                    AmountSource::RETURN_REFUND => AmountSource::RETURN_REALIZATION,
                    AmountSource::RETURN_REALIZATION => AmountSource::RETURN_REFUND,
                    default => null,
                };
                if (null !== $otherSource && $connection->fetchOne(
                    "SELECT 1 FROM marketplace_sale_mappings WHERE company_id = :company AND marketplace = 'ozon' AND pl_category_id = :category AND amount_source = :source AND is_active = true LIMIT 1",
                    ['company' => $companyId, 'category' => $mapping->getPlCategory()->getId(), 'source' => $otherSource->value],
                )) {
                    throw new \DomainException('В этой статье уже включён другой источник Ozon. Сначала устраните двойной учёт.');
                }
                $this->mappings->deactivateOtherActive($mapping->getCompany(), MarketplaceType::OZON, $mapping->getOperationType(), $source, $mapping->getId());
            }
            $this->em->persist($mapping);
        });
    }
}
