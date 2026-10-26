<?php

declare(strict_types=1);

namespace App\Tests\Integration\BankSync;

use App\BankSync\Entity\BankConnection;
use App\BankSync\Infrastructure\Security\BankCredentialsCodec;
use App\Shared\Security\Contract\FieldEncryptionServiceInterface;
use App\Tests\Builders\BankSync\BankConnectionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class BankConnectionStorageTest extends IntegrationTestCase
{
    private const TOKEN = 'integration-secret-bank-token';

    public function testStoredRowHasOnlyCiphertextAndTokenDecryptsBack(): void
    {
        $companyId = Uuid::uuid4()->toString();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('bank-sync-'.$companyId.'@example.test')->build();
        $this->em->persist($owner);
        $this->em->persist(CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build());

        // Сервисы модуля ещё никем не используются — контейнер их вырезал; ключ — боевой из test env.
        /** @var FieldEncryptionServiceInterface $encryption */
        $encryption = self::getContainer()->get(FieldEncryptionServiceInterface::class);
        $codec = new BankCredentialsCodec($encryption);
        $connection = BankConnectionBuilder::aConnection()
            ->withId(Uuid::uuid7()->toString())
            ->withCompanyId($companyId)
            ->withCredentials($codec->encryptStaticToken(self::TOKEN))
            ->build();
        $this->em->persist($connection);
        $this->em->flush();
        $this->em->clear();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT id, company_id, bank_code, base_url, credentials_encrypted, credentials_key_version, is_active, created_at, updated_at
             FROM bank_sync_connection WHERE id = :id',
            ['id' => $connection->getId()],
        );
        self::assertIsArray($row);
        self::assertStringNotContainsString(self::TOKEN, (string) json_encode($row, \JSON_THROW_ON_ERROR));
        self::assertSame('alfa', $row['bank_code']);

        $repository = $this->em->getRepository(BankConnection::class);
        $reloaded = $repository->findOneByIdAndCompanyId($connection->getId(), $companyId);
        self::assertNotNull($reloaded);
        self::assertSame(self::TOKEN, $codec->token($reloaded));
        self::assertNull($repository->findOneByIdAndCompanyId($connection->getId(), Uuid::uuid4()->toString()));
        self::assertNull($repository->findOneByIdAndCompanyId('not-a-uuid', $companyId));
    }
}
