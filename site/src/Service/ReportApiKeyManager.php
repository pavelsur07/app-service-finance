<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\ReportApiKey;
use App\Repository\ReportApiKeyRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ReportApiKeyManager
{
    private const PREFIX = 'rk_live_';

    public function __construct(
        private EntityManagerInterface $em,
        private ReportApiKeyRepository $repo,
    ) {
    }

    public function generateRawKey(): string
    {
        return self::PREFIX . \bin2hex(\random_bytes(16));
    }

    public function createOrRegenerateForCompany(Company $company): string
    {
        $this->repo->deactivateAll($company);

        $rawKey = $this->generateRawKey();
        $hash = \password_hash($rawKey, PASSWORD_ARGON2ID);

        $apiKey = new ReportApiKey($company, self::PREFIX, $hash);
        $this->em->persist($apiKey);
        $this->em->flush();

        return $rawKey;
    }

    public function revokeAll(Company $company): void
    {
        $this->repo->deactivateAll($company);
    }

    public function isValidRawKeyForCompany(Company $company, string $raw): bool
    {
        $raw = \trim($raw);
        if ($raw === '' || \strncmp($raw, 'rk_', 3) !== 0) {
            return false;
        }

        $prefix = \substr($raw, 0, 8);
        $candidates = $this->repo->findActiveByCompanyAndPrefix($company, $prefix);

        foreach ($candidates as $candidate) {
            if (!\password_verify($raw, $candidate->getKeyHash())) {
                continue;
            }

            $exp = $candidate->getExpiresAt();
            if ($exp && $exp < new \DateTimeImmutable('now')) {
                continue;
            }

            $candidate->markAsUsed();
            $this->em->flush();

            return true;
        }

        return false;
    }

    /**
     * Находит компанию по «сырому» ключу из query (?token=...).
     * Возвращает Company при валидном ключе, иначе null.
     */
    public function findCompanyByRawKey(string $raw): ?Company
    {
        $raw = \trim($raw);
        if ($raw === '' || \strncmp($raw, 'rk_', 3) !== 0) {
            return null;
        }

        $prefix = \substr($raw, 0, 8);
        $candidates = $this->repo->findActiveByPrefix($prefix);
        if (!$candidates) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if (!\password_verify($raw, $candidate->getKeyHash())) {
                continue;
            }

            $exp = $candidate->getExpiresAt();
            if ($exp && $exp < new \DateTimeImmutable('now')) {
                continue;
            }

            $candidate->markAsUsed();
            $this->em->flush();

            return $candidate->getCompany();
        }

        return null;
    }
}
