<?php

declare(strict_types=1);

namespace App\Ingestion\Facade;

use App\Ingestion\Domain\Contract\SecretCodec;
use App\Ingestion\Entity\IngestionCredential;
use App\Ingestion\Exception\CredentialNotFoundException;
use App\Ingestion\Infrastructure\Credential\LegacyMarketplaceCredentialReader;
use App\Ingestion\Infrastructure\Doctrine\EncryptedJsonType;
use App\Ingestion\Infrastructure\Security\SecretPayloadMasker;
use App\Ingestion\Repository\IngestionCredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use Webmozart\Assert\Assert;

final readonly class CredentialFacade
{
    public function __construct(
        private IngestionCredentialRepository $credentialRepository,
        private LegacyMarketplaceCredentialReader $legacyMarketplaceCredentialReader,
        private EntityManagerInterface $entityManager,
        private SecretCodec $secretCodec,
        private SecretPayloadMasker $masker,
        private int $activeKeyVersion = 0,
    ) {
        EncryptedJsonType::setSecretCodec($this->secretCodec);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function store(string $companyId, string $connectionRef, array $payload): void
    {
        Assert::uuid($companyId);
        Assert::notEmpty($connectionRef);

        $credential = $this->credentialRepository->findOneByCompanyRefAndType(
            $companyId,
            $connectionRef,
            IngestionCredential::TYPE_API_CREDENTIALS,
        );

        if (null === $credential) {
            $credential = new IngestionCredential(
                companyId: $companyId,
                connectionRef: $connectionRef,
                payload: $payload,
                keyVersion: $this->activeKeyVersion,
            );
            $this->entityManager->persist($credential);
        } else {
            $credential->replacePayload($payload, $this->activeKeyVersion);
        }

        $this->entityManager->flush();
    }

    /**
     * @return array<string, mixed>
     */
    public function read(string $companyId, string $connectionRef): array
    {
        Assert::uuid($companyId);
        Assert::notEmpty($connectionRef);

        $credential = $this->credentialRepository->findOneByCompanyRefAndType(
            $companyId,
            $connectionRef,
            IngestionCredential::TYPE_API_CREDENTIALS,
        );

        if (null !== $credential) {
            return $credential->getPayload();
        }

        $legacyPayload = $this->legacyMarketplaceCredentialReader->read($companyId, $connectionRef);
        if (null !== $legacyPayload) {
            return $legacyPayload;
        }

        throw new CredentialNotFoundException('Credentials not found for requested company and connection reference.');
    }

    /**
     * @return array<string, mixed>
     */
    public function readMasked(string $companyId, string $connectionRef): array
    {
        return $this->masker->mask($this->read($companyId, $connectionRef));
    }
}
