<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\Contract\SecretCodec;
use App\Ingestion\Entity\IngestionCredential;
use App\Ingestion\Exception\CredentialNotFoundException;
use App\Ingestion\Facade\CredentialFacade;
use App\Ingestion\Infrastructure\Security\PlaintextSecretCodec;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class CredentialFacadeTest extends IntegrationTestCase
{
    public function testStoreThenReadReturnsOriginalPayloadAndStoresEncodedPayload(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = 'marketplace:ozon:seller';
        $payload = [
            'api_key' => 'ozon-secret-key',
            'client_id' => 'ozon-client-id',
        ];

        /** @var CredentialFacade $facade */
        $facade = self::getContainer()->get(CredentialFacade::class);
        /** @var SecretCodec $codec */
        $codec = self::getContainer()->get(SecretCodec::class);

        $facade->store($companyId, $connectionRef, $payload);
        $this->em->clear();

        self::assertSame($payload, $facade->read($companyId, $connectionRef));

        $row = $this->connection->fetchAssociative(
            'SELECT payload, key_version FROM ingestion_credentials WHERE company_id = :company_id AND connection_ref = :connection_ref',
            [
                'company_id' => $companyId,
                'connection_ref' => $connectionRef,
            ],
        );

        self::assertIsArray($row);
        self::assertSame($codec->encode($payload), $row['payload']);
        self::assertSame(PlaintextSecretCodec::KEY_VERSION, (int) $row['key_version']);
    }

    public function testReadMaskedDoesNotReturnSecretPayloadValues(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = 'marketplace:wildberries:seller';
        $payload = [
            'api_key' => 'wb-secret-key',
            'client_id' => null,
        ];

        /** @var CredentialFacade $facade */
        $facade = self::getContainer()->get(CredentialFacade::class);
        $facade->store($companyId, $connectionRef, $payload);

        $masked = $facade->readMasked($companyId, $connectionRef);
        $encodedMasked = json_encode($masked, JSON_THROW_ON_ERROR);

        self::assertSame(['api_key' => '***', 'client_id' => null], $masked);
        self::assertStringNotContainsString('wb-secret-key', $encodedMasked);
    }

    public function testTenantCannotReadAnotherCompanyCredential(): void
    {
        $companyA = Uuid::uuid7()->toString();
        $companyB = Uuid::uuid7()->toString();
        $connectionRef = 'marketplace:ozon:seller';

        /** @var CredentialFacade $facade */
        $facade = self::getContainer()->get(CredentialFacade::class);
        $facade->store($companyB, $connectionRef, ['api_key' => 'company-b-secret']);

        $this->expectException(CredentialNotFoundException::class);
        $facade->read($companyA, $connectionRef);
    }

    public function testReadFallsBackToLegacyMarketplaceConnectionByConnectionId(): void
    {
        $user = UserBuilder::aUser()->withIndex(1)->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $connection = new MarketplaceConnection(
            Uuid::uuid7()->toString(),
            $company,
            MarketplaceType::OZON,
        );
        $connection->setClientId('legacy-client-id');
        $connection->setApiKey('legacy-api-key');

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($connection);
        $this->em->flush();
        $this->em->clear();

        /** @var CredentialFacade $facade */
        $facade = self::getContainer()->get(CredentialFacade::class);

        self::assertSame([
            'api_key' => 'legacy-api-key',
            'client_id' => 'legacy-client-id',
        ], $facade->read((string) $company->getId(), $connection->getId()));
    }

    public function testCompanyFilterLimitsCredentialOrmReads(): void
    {
        $companyA = Uuid::uuid7()->toString();
        $companyB = Uuid::uuid7()->toString();

        /** @var CredentialFacade $facade */
        $facade = self::getContainer()->get(CredentialFacade::class);
        $facade->store($companyA, 'marketplace:ozon:seller', ['api_key' => 'company-a-secret']);
        $facade->store($companyB, 'marketplace:ozon:seller', ['api_key' => 'company-b-secret']);
        $this->em->clear();

        $this->em->getFilters()->enable('company')->setParameter('companyId', $companyA);

        $ids = $this->em->createQueryBuilder()
            ->select('credential.id')
            ->from(IngestionCredential::class, 'credential')
            ->getQuery()
            ->getSingleColumnResult();

        self::assertCount(1, $ids);

        $this->em->getFilters()->disable('company');
    }
}
