<?php

declare(strict_types=1);

namespace App\Company\Application;

use App\Company\Application\Service\CompanyTaxProfileWriter;
use App\Company\Exception\CompanyTaxProfileNotFoundException;
use App\Company\Repository\CompanyTaxProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

final class DeleteCompanyTaxProfileAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CompanyTaxProfileWriter $writer,
        private readonly CompanyTaxProfileRepository $repository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws CompanyTaxProfileNotFoundException
     */
    public function __invoke(string $companyId, string $profileId): void
    {
        Assert::uuid($companyId);
        if (!Uuid::isValid($profileId)) {
            throw new CompanyTaxProfileNotFoundException();
        }

        // Поиск до транзакции: исключение «не найден» не должно закрывать EntityManager.
        if (null === $this->repository->findOneByIdAndCompany($profileId, $companyId)) {
            throw new CompanyTaxProfileNotFoundException();
        }

        $this->entityManager->wrapInTransaction(function () use ($companyId, $profileId): void {
            $this->writer->lockCompany($companyId);
            $profile = $this->repository->findOneByIdAndCompany($profileId, $companyId);
            if (null !== $profile) {
                $this->entityManager->remove($profile);
                $this->entityManager->flush();
            }
        });

        $this->logger->info('Company tax profile deleted', ['companyId' => $companyId, 'profileId' => $profileId]);
    }
}
