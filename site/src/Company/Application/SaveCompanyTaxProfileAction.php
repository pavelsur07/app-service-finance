<?php

declare(strict_types=1);

namespace App\Company\Application;

use App\Company\Application\Service\CompanyTaxProfileWriter;
use App\Company\Entity\CompanyTaxProfile;
use App\Company\Enum\TaxRegime;
use App\Company\Enum\VatMode;
use App\Company\Exception\InvalidCompanyTaxProfileException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

/**
 * Задаёт налоговый профиль компании с месяца `validFrom` (null — начальный профиль).
 * Повтор с тем же месяцем меняет тот же период, а не добавляет второй.
 */
final class SaveCompanyTaxProfileAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CompanyTaxProfileWriter $writer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws InvalidCompanyTaxProfileException
     */
    public function __invoke(string $companyId, TaxRegime $taxRegime, VatMode $vatMode, ?\DateTimeImmutable $validFrom): string
    {
        Assert::uuid($companyId);
        // Проверка до транзакции: исключение внутри wrapInTransaction закрывает EntityManager.
        if (!$taxRegime->allows($vatMode)) {
            throw InvalidCompanyTaxProfileException::vatModeNotAllowed($taxRegime->label(), $vatMode->label());
        }
        $validFrom = CompanyTaxProfile::normalizeValidFrom($validFrom);

        /** @var string $profileId */
        $profileId = $this->entityManager->wrapInTransaction(function () use ($companyId, $taxRegime, $vatMode, $validFrom): string {
            $this->writer->lockCompany($companyId);
            $profile = $this->writer->upsert($companyId, $taxRegime, $vatMode, $validFrom);
            $this->entityManager->flush();

            return $profile->getId();
        });

        $this->logger->info('Company tax profile saved', [
            'companyId' => $companyId,
            'profileId' => $profileId,
            'taxRegime' => $taxRegime->value,
            'vatMode' => $vatMode->value,
            'validFrom' => $validFrom?->format('Y-m-d'),
        ]);

        return $profileId;
    }
}
