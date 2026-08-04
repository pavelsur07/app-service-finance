<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Infrastructure\Security;

use App\Marketplace\Command\RotateConnectionKeysCommand;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Security\ConnectionApiKeyCodec;
use App\Shared\Security\Service\FileBasedSecretKeyProvider;
use App\Shared\Security\Service\SecretRotationService;
use App\Shared\Security\Service\SodiumFieldEncryptionService;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Tester\CommandTester;

final class ConnectionApiKeyRotationTest extends IntegrationTestCase
{
    private const KEY_V1 = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='; // 32 нулевых байта (= APP_ENCRYPTION_FALLBACK_KEY в .env.test)
    private const KEY_V2 = 'AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE='; // 32 байта 0x01 (= v2 в .env.test)

    public function testRotateIfNeededReencryptsToActiveVersionKeepingPlaintext(): void
    {
        $connection = $this->seedEncryptedConnection('rotate-me-secret-key');
        self::assertSame('v1', $connection->getApiKeyKeyVersion());

        $codecV2 = $this->buildCodecForActiveVersion('v2');

        self::assertTrue($codecV2->rotateIfNeeded($connection));
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->find(MarketplaceConnection::class, $connection->getId());
        self::assertSame('v2', $reloaded->getApiKeyKeyVersion());

        // plaintext после ротации не изменился — читаем через стек с активной v2
        self::assertSame('rotate-me-secret-key', $codecV2->apiKeyFor($reloaded));

        // повторная ротация — no-op
        self::assertFalse($codecV2->rotateIfNeeded($reloaded));
    }

    public function testRotateIfNeededSkipsLegacyPlaintextRows(): void
    {
        $user = UserBuilder::aUser()->withIndex(5)->build();
        $company = CompanyBuilder::aCompany()->withIndex(5)->withOwner($user)->build();
        $legacy = new MarketplaceConnection(Uuid::uuid4()->toString(), $company, MarketplaceType::OZON);
        $legacy->setApiKey('plain-legacy-key');
        $legacy->setClientId('client-id');

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($legacy);
        $this->em->flush();

        $codecV2 = $this->buildCodecForActiveVersion('v2');

        self::assertFalse($codecV2->rotateIfNeeded($legacy));
        self::assertFalse($legacy->hasEncryptedApiKey());
        self::assertSame('plain-legacy-key', $legacy->getApiKey());
    }

    public function testRotateCommandDryRunExecuteAndIdempotency(): void
    {
        $this->seedEncryptedConnection('rotate-cmd-key');

        $providerV2 = $this->buildProviderForActiveVersion('v2');
        $command = new RotateConnectionKeysCommand(
            $this->em,
            $this->buildCodecForActiveVersion('v2'),
            $providerV2,
        );
        $tester = new CommandTester($command);

        // dry-run: показывает pending, не меняет данные
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('DRY-RUN', $display);
        self::assertStringContainsString('v1', $display);

        // execute: ротация на v2
        $tester->execute(['--execute' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('перешифровано', $tester->getDisplay());

        // повторный запуск — делать нечего
        $tester->execute(['--execute' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Делать нечего', $tester->getDisplay());

        // ключевой материал и значения api_key не утекают в вывод
        $fullOutput = $tester->getDisplay();
        self::assertStringNotContainsString(self::KEY_V1, $fullOutput);
        self::assertStringNotContainsString(self::KEY_V2, $fullOutput);
        self::assertStringNotContainsString('rotate-cmd-key', $fullOutput);
    }

    /**
     * Сидит подключение и шифрует его ключ текущим (v1) стеком из контейнера.
     */
    private function seedEncryptedConnection(string $apiKey): MarketplaceConnection
    {
        $user = UserBuilder::aUser()->withIndex(7)->build();
        $company = CompanyBuilder::aCompany()->withIndex(7)->withOwner($user)->build();
        $connection = new MarketplaceConnection(Uuid::uuid4()->toString(), $company, MarketplaceType::OZON);
        $connection->setApiKey($apiKey);
        $connection->setClientId('client-id');

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($connection);
        $this->em->flush();

        self::getContainer()->get(ConnectionApiKeyCodec::class)->encryptExisting($connection);
        $this->em->flush();

        return $connection;
    }

    private function buildCodecForActiveVersion(string $activeVersion): ConnectionApiKeyCodec
    {
        $provider = $this->buildProviderForActiveVersion($activeVersion);
        $encryption = new SodiumFieldEncryptionService($provider);

        return new ConnectionApiKeyCodec(
            $encryption,
            new SecretRotationService($provider, $encryption),
        );
    }

    private function buildProviderForActiveVersion(string $activeVersion): FileBasedSecretKeyProvider
    {
        return new FileBasedSecretKeyProvider(
            keyFile: '/nonexistent/keys.json',
            currentKeyVersion: $activeVersion,
            keysJsonFromEnv: (string) json_encode(['v1' => self::KEY_V1, 'v2' => self::KEY_V2]),
        );
    }
}
