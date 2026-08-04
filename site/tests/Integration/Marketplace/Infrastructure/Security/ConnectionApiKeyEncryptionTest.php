<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Infrastructure\Security;

use App\Marketplace\Command\EncryptConnectionKeysCommand;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\MarketplaceCredentialsQuery;
use App\Marketplace\Infrastructure\Security\ConnectionApiKeyCodec;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Tester\CommandTester;

final class ConnectionApiKeyEncryptionTest extends IntegrationTestCase
{
    public function testApplyApiKeyWritesPlaintextAndEncryptedPair(): void
    {
        $connection = $this->seedConnection('super-secret-api-key');

        $codec = self::getContainer()->get(ConnectionApiKeyCodec::class);
        $codec->applyApiKey($connection, 'super-secret-api-key');
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->find(MarketplaceConnection::class, $connection->getId());
        self::assertNotNull($reloaded);

        // Expand: оба представления записаны
        self::assertSame('super-secret-api-key', $reloaded->getApiKey());
        self::assertTrue($reloaded->hasEncryptedApiKey());
        self::assertNotSame('super-secret-api-key', $reloaded->getApiKeyEncrypted());
        self::assertSame('v1', $reloaded->getApiKeyKeyVersion());

        // Чтение через кодек возвращает исходный plaintext
        self::assertSame('super-secret-api-key', $codec->apiKeyFor($reloaded));
    }

    public function testApiKeyForFallsBackToPlaintextForLegacyRows(): void
    {
        $connection = $this->seedConnection('legacy-plaintext-key');

        $codec = self::getContainer()->get(ConnectionApiKeyCodec::class);

        self::assertFalse($connection->hasEncryptedApiKey());
        self::assertSame('legacy-plaintext-key', $codec->apiKeyFor($connection));
    }

    public function testCredentialsQueryDecryptsEncryptedRows(): void
    {
        $connection = $this->seedConnection('dbal-secret-key');
        $connection->setIsActive(true);

        $codec = self::getContainer()->get(ConnectionApiKeyCodec::class);
        $codec->encryptExisting($connection);
        $this->em->flush();
        $this->em->clear();

        $query = self::getContainer()->get(MarketplaceCredentialsQuery::class);
        $credentials = $query->getCredentials(
            (string) $connection->getCompany()->getId(),
            MarketplaceType::OZON,
        );

        self::assertNotNull($credentials);
        self::assertSame('dbal-secret-key', $credentials['api_key']);
    }

    public function testBackfillCommandDryRunAndExecute(): void
    {
        $legacyA = $this->seedConnection('backfill-key-a', 1);
        $legacyB = $this->seedConnection('backfill-key-b', 2);

        $command = self::getContainer()->get(EncryptConnectionKeysCommand::class);
        $tester = new CommandTester($command);

        // dry-run: ничего не шифрует
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('DRY-RUN', $tester->getDisplay());

        $this->em->clear();
        self::assertFalse($this->em->find(MarketplaceConnection::class, $legacyA->getId())->hasEncryptedApiKey());

        // execute: шифрует все pending
        $tester->execute(['--execute' => true]);
        $tester->assertCommandIsSuccessful();

        $this->em->clear();
        $reloaded = $this->em->find(MarketplaceConnection::class, $legacyB->getId());
        self::assertTrue($reloaded->hasEncryptedApiKey());

        $codec = self::getContainer()->get(ConnectionApiKeyCodec::class);
        self::assertSame('backfill-key-b', $codec->apiKeyFor($reloaded));

        // повторный запуск — идемпотентно, делать нечего
        $tester->execute(['--execute' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Делать нечего', $tester->getDisplay());

        // plaintext не утёк в вывод команды
        self::assertStringNotContainsString('backfill-key', $tester->getDisplay());
    }

    private function seedConnection(string $apiKey, int $index = 1): MarketplaceConnection
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $connection = new MarketplaceConnection(
            Uuid::uuid4()->toString(),
            $company,
            MarketplaceType::OZON,
        );
        $connection->setApiKey($apiKey);
        $connection->setClientId('client-id');

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($connection);
        $this->em->flush();

        return $connection;
    }
}
