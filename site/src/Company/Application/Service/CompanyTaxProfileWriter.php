<?php

declare(strict_types=1);

namespace App\Company\Application\Service;

use App\Company\Entity\CompanyTaxProfile;
use App\Company\Enum\TaxRegime;
use App\Company\Enum\VatMode;
use App\Company\Repository\CompanyTaxProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Создаёт или меняет период налогового профиля; только persist — транзакцией владеет вызывающий.
 * Конкурентная запись по одной компании сериализуется блокировкой lockCompany() в той же транзакции.
 */
final readonly class CompanyTaxProfileWriter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompanyTaxProfileRepository $repository,
        private ClockInterface $clock,
    ) {
    }

    public function lockCompany(string $companyId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))',
            ['key' => 'company_tax_profile:'.$companyId],
        );
    }

    public function upsert(string $companyId, TaxRegime $taxRegime, VatMode $vatMode, ?\DateTimeImmutable $validFrom): CompanyTaxProfile
    {
        $validFrom = CompanyTaxProfile::normalizeValidFrom($validFrom);
        $now = $this->clock->now();

        $existing = $this->repository->findOneByCompanyAndValidFrom($companyId, $validFrom);
        if (null !== $existing) {
            $existing->change($taxRegime, $vatMode, $now);

            return $existing;
        }

        $profile = new CompanyTaxProfile($companyId, $taxRegime, $vatMode, $validFrom, $now);
        $this->entityManager->persist($profile);

        return $profile;
    }
}
