<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\ReportApiKey;
use App\Repository\CompanyRepository;
use App\Repository\ReportApiKeyRepository;
use Doctrine\ORM\EntityManagerInterface;

class ReportApiKeyManager
{
    private const PREFIX = 'rk_live_';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ReportApiKeyRepository $reportApiKeyRepository,
    ) {
    }

    public function generateRawKey(): string
    {
        return self::PREFIX . \bin2hex(\random_bytes(16));
    }

    public function createOrRegenerateForCompany(Company $company): string
    {
        $this->reportApiKeyRepository->deactivateAll($company);

        $rawKey = $this->generateRawKey();
        $hash = \password_hash($rawKey, PASSWORD_ARGON2ID);

        $apiKey = new ReportApiKey($company, self::PREFIX, $hash);
        $this->entityManager->persist($apiKey);
        $this->entityManager->flush();

        return $rawKey;
    }

    public function revokeAll(Company $company): void
    {
        $this->reportApiKeyRepository->deactivateAll($company);
    }

    public function isValidRawKeyForCompany(Company $company, string $raw): bool
    {
        if (!\str_starts_with($raw, 'rk_')) {
            return false;
        }

        $prefix = \substr($raw, 0, 8);
        $candidates = $this->reportApiKeyRepository->findActiveByCompanyAndPrefix($company, $prefix);

        foreach ($candidates as $candidate) {
            if (\password_verify($raw, $candidate->getKeyHash())) {
                $candidate->markAsUsed();
                $this->entityManager->flush();

                return true;
            }
        }

        return false;
    }

    public function findCompanyByRawKey(string $raw, CompanyRepository $repo): ?Company
    {
        if (!\str_starts_with($raw, 'rk_')) {
            return null;
        }

        $prefix = \substr($raw, 0, 8);
        $candidates = $this->reportApiKeyRepository->findActiveByPrefix($prefix);

        foreach ($candidates as $candidate) {
            if (!\password_verify($raw, $candidate->getKeyHash())) {
                continue;
            }

            $companyId = $candidate->getCompany()->getId();
            $candidate->markAsUsed();
            $this->entityManager->flush();

            if ($companyId === null) {
                return null;
            }

            $company = $repo->find($companyId);

            return $company ?? $candidate->getCompany();
        }

        return null;
    }
}
