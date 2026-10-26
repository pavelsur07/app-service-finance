<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Security;

use App\BankSync\Infrastructure\Security\BankCredentialsCodec;
use App\BankSync\Infrastructure\Security\BankSyncSecretRotator;
use App\BankSync\Infrastructure\Security\EncryptedBankCredentials;
use App\Company\Entity\Company;
use App\Marketplace\Command\RotateConnectionKeysCommand;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Security\ConnectionApiKeyCodec;
use App\Marketplace\Infrastructure\Security\MarketplaceSecretRotator;
use App\MoySklad\Infrastructure\Security\ConnectionTokenCodec;
use App\MoySklad\Infrastructure\Security\MoySkladSecretRotator;
use App\Shared\Command\RotateEncryptionKeysCommand;
use App\Shared\Security\Contract\SecretRotationServiceInterface;
use App\Shared\Security\Service\FileBasedSecretKeyProvider;
use App\Shared\Security\Service\SecretRotationService;
use App\Shared\Security\Service\SodiumFieldEncryptionService;
use App\Shared\Security\ValueObject\EncryptedPayload;
use App\Tests\Builders\BankSync\BankConnectionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MoySklad\MoySkladConnectionBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RotateEncryptionKeysCommandTest extends IntegrationTestCase
{
    private const KEY_V1 = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
    private const KEY_V2 = 'AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE=';

    private const MARKETPLACE_KEY = 'rotate-marketplace-api-key';
    private const MARKETPLACE_KEY_ON_V2 = 'already-v2-marketplace-key';
    private const MOYSKLAD_ACCESS = 'rotate-moysklad-access';
    private const MOYSKLAD_REFRESH = 'rotate-moysklad-refresh';
    private const BANK_TOKEN = 'rotate-bank-token';

    private string $companyId;
    private string $marketplaceId;
    private string $marketplaceOnV2Id;
    private string $moySkladId;
    private string $bankId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = $this->companyId = Uuid::uuid4()->toString();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('rotate-'.$companyId.'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        $v1 = new SodiumFieldEncryptionService($this->provider('v1'));
        $v2 = new SodiumFieldEncryptionService($this->provider('v2'));

        $marketplace = new MarketplaceConnection(Uuid::uuid4()->toString(), $company, MarketplaceType::OZON);
        $marketplace->setClientId('client-id');
        (new ConnectionApiKeyCodec($v1))->applyApiKey($marketplace, self::MARKETPLACE_KEY);
        $this->em->persist($marketplace);
        $this->marketplaceId = $marketplace->getId();

        $onV2 = new MarketplaceConnection(Uuid::uuid4()->toString(), $company, MarketplaceType::WILDBERRIES);
        $onV2->setClientId('client-id-2');
        (new ConnectionApiKeyCodec($v2))->applyApiKey($onV2, self::MARKETPLACE_KEY_ON_V2);
        $this->em->persist($onV2);
        $this->marketplaceOnV2Id = $onV2->getId();

        $moySklad = MoySkladConnectionBuilder::aConnection()->withIndex(4242)->withCompanyId($companyId)->build();
        (new ConnectionTokenCodec($v1))->replaceAccessToken($moySklad, self::MOYSKLAD_ACCESS);
        $moySklad->setRefreshTokenEncrypted($v1->encrypt(self::MOYSKLAD_REFRESH)->toStorageJson());
        $this->em->persist($moySklad);
        $this->moySkladId = $moySklad->getId();

        $bank = BankConnectionBuilder::aConnection()
            ->withId(Uuid::uuid7()->toString())
            ->withCompanyId($companyId)
            ->withCredentials((new BankCredentialsCodec($v1))->encryptStaticToken(self::BANK_TOKEN))
            ->build();
        $this->em->persist($bank);
        $this->bankId = $bank->getId();

        $this->em->flush();
        $this->em->clear();
    }

    public function testDryRunShowsEveryModuleAndWritesNothing(): void
    {
        $before = $this->secrets();
        $tester = new CommandTester($this->command('v2'));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('DRY-RUN', $display);
        foreach (['marketplace', 'api_key', 'moysklad', 'access_token', 'refresh_token', 'bank_sync', 'credentials'] as $label) {
            self::assertStringContainsString($label, $display);
        }
        self::assertSame($before, $this->secrets());
        $this->assertNoSecretsIn($display, $before);
    }

    public function testExecuteRotatesEveryModuleAndRepeatIsNoop(): void
    {
        $before = $this->secrets();
        $tester = new CommandTester($this->command('v2'));

        $tester->execute(['--execute' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('перешифровано 4', $tester->getDisplay());
        $after = $this->secrets();
        $v2 = new SodiumFieldEncryptionService($this->provider('v2'));

        self::assertSame('v2', $after['marketplace']['api_key_key_version']);
        self::assertSame(self::MARKETPLACE_KEY, (new ConnectionApiKeyCodec($v2))->decrypt($after['marketplace']['api_key_encrypted'], 'v2'));
        self::assertSame($before['marketplace_on_v2'], $after['marketplace_on_v2'], 'запись на активной версии не тронута');
        self::assertSame(self::MARKETPLACE_KEY, $after['marketplace']['api_key'], 'legacy plaintext не меняется');

        foreach (['access_token_encrypted' => self::MOYSKLAD_ACCESS, 'refresh_token_encrypted' => self::MOYSKLAD_REFRESH] as $column => $plaintext) {
            $payload = EncryptedPayload::fromStorageJson($after['moysklad'][$column]);
            self::assertSame('v2', $payload->keyVersion());
            self::assertSame($plaintext, $v2->decrypt($payload));
        }

        self::assertSame('v2', $after['bank']['credentials_key_version']);
        self::assertSame(self::BANK_TOKEN, (new BankCredentialsCodec($v2))->token(
            BankConnectionBuilder::aConnection()->withCredentials(new EncryptedBankCredentials($after['bank']['credentials_encrypted'], 'v2'))->build(),
        ));
        self::assertSame($before['bank']['updated_at'], $after['bank']['updated_at'], 'ротация не меняет updated_at');

        $tester->execute(['--execute' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Делать нечего', $tester->getDisplay());
        self::assertSame($after, $this->secrets());
        $this->assertNoSecretsIn($tester->getDisplay(), $after);
    }

    public function testMissingKeyForStoredVersionFailsBeforeAnyWrite(): void
    {
        $before = $this->secrets();
        // Активна v2, а v1 из карты уже убрали — так выглядит преждевременная «уборка».
        $provider = new FileBasedSecretKeyProvider(
            keyFile: '/nonexistent/keys.json',
            currentKeyVersion: 'v2',
            keysJsonFromEnv: (string) json_encode(['v2' => self::KEY_V2]),
        );
        $tester = new CommandTester($this->command('v2', $provider));

        $exitCode = $tester->execute(['--execute' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('НЕТ', $tester->getDisplay());
        self::assertStringContainsString('Нет ключа для версий: v1', $tester->getDisplay());
        self::assertSame($before, $this->secrets());
    }

    public function testDecryptionFailureRollsBackTheWholeBatch(): void
    {
        // Вторая строка маркетплейса на v1 с шифротекстом, который не расшифровать; id
        // после исправной строки, поэтому исправная в порции перешифровывается первой.
        $company = $this->em->find(Company::class, $this->companyId);
        self::assertNotNull($company);
        $broken = new MarketplaceConnection('ffffffff-ffff-4fff-8fff-ffffffffffff', $company, MarketplaceType::YANDEX_MARKET);
        $broken->setApiKey('broken');
        $broken->setClientId('broken');
        $broken->setEncryptedApiKey(base64_encode(str_repeat('x', 64)), 'v1');
        $this->em->persist($broken);
        $this->em->flush();
        $before = $this->secrets();

        $exitCode = (new CommandTester($this->command('v2')))->execute(['--execute' => true, '--module' => ['marketplace']]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame($before, $this->secrets(), 'исправная строка той же порции откатилась');
    }

    public function testValueChangedConcurrentlyIsNotOverwritten(): void
    {
        $v2 = new SodiumFieldEncryptionService($this->provider('v2'));
        $fresh = $v2->encrypt('saved-by-user-meanwhile');
        $rotation = new SecretRotationService($this->provider('v2'), $v2);
        // Пока ротатор перешифровывает значение, пользователь сохраняет новый ключ.
        $racingRotation = new class($rotation, $this->connection, $this->marketplaceId, $fresh) implements SecretRotationServiceInterface {
            public function __construct(
                private SecretRotationServiceInterface $inner,
                private Connection $connection,
                private string $id,
                private EncryptedPayload $fresh,
            ) {
            }

            public function requiresReencryption(EncryptedPayload $payload): bool
            {
                return $this->inner->requiresReencryption($payload);
            }

            public function rotate(EncryptedPayload $payload): EncryptedPayload
            {
                $this->connection->executeStatement(
                    'UPDATE marketplace_connections SET api_key_encrypted = :c, api_key_key_version = :v WHERE id = :id',
                    ['c' => $this->fresh->ciphertext(), 'v' => $this->fresh->keyVersion(), 'id' => $this->id],
                );

                return $this->inner->rotate($payload);
            }
        };

        $result = $this->connection->transactional(
            fn () => (new MarketplaceSecretRotator($this->connection, $racingRotation))->rotateBatch('v2', 100),
        );

        self::assertSame(1, $result->selected);
        self::assertSame(0, $result->rotated);
        $after = $this->secrets();
        self::assertSame($fresh->ciphertext(), $after['marketplace']['api_key_encrypted']);
        self::assertSame('saved-by-user-meanwhile', $v2->decrypt(new EncryptedPayload($after['marketplace']['api_key_encrypted'], 'v2', new \DateTimeImmutable())));
    }

    public function testModuleOptionRotatesOnlyThatModule(): void
    {
        $before = $this->secrets();

        (new CommandTester($this->command('v2')))->execute(['--execute' => true, '--module' => ['marketplace']]);

        $after = $this->secrets();
        self::assertSame('v2', $after['marketplace']['api_key_key_version']);
        self::assertSame($before['moysklad'], $after['moysklad']);
        self::assertSame($before['bank'], $after['bank']);
    }

    public function testUnknownModuleIsInvalid(): void
    {
        $tester = new CommandTester($this->command('v2'));

        self::assertSame(Command::INVALID, $tester->execute(['--module' => ['nope']]));
    }

    public function testLegacyMarketplaceCommandKeepsItsResult(): void
    {
        $before = $this->secrets();
        $tester = new CommandTester(new RotateConnectionKeysCommand($this->command('v2')));

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('DRY-RUN', $tester->getDisplay());
        self::assertSame($before, $this->secrets());

        $tester->execute(['--execute' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('перешифровано', $tester->getDisplay());
        $after = $this->secrets();
        self::assertSame('v2', $after['marketplace']['api_key_key_version']);
        self::assertSame($before['moysklad'], $after['moysklad'], 'старая команда — только маркетплейсы');

        $tester->execute(['--execute' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Делать нечего', $tester->getDisplay());
        $this->assertNoSecretsIn($tester->getDisplay(), $after);
    }

    private function command(string $activeVersion, ?FileBasedSecretKeyProvider $provider = null): RotateEncryptionKeysCommand
    {
        $provider ??= $this->provider($activeVersion);
        $rotation = new SecretRotationService($provider, new SodiumFieldEncryptionService($provider));

        return new RotateEncryptionKeysCommand(
            [
                new MarketplaceSecretRotator($this->connection, $rotation),
                new MoySkladSecretRotator($this->connection, $rotation),
                new BankSyncSecretRotator($this->connection, $rotation),
            ],
            $provider,
            $this->connection,
            new NullLogger(),
        );
    }

    private function provider(string $activeVersion): FileBasedSecretKeyProvider
    {
        return new FileBasedSecretKeyProvider(
            keyFile: '/nonexistent/keys.json',
            currentKeyVersion: $activeVersion,
            keysJsonFromEnv: (string) json_encode(['v1' => self::KEY_V1, 'v2' => self::KEY_V2]),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function secrets(): array
    {
        $marketplace = 'SELECT api_key, api_key_encrypted, api_key_key_version FROM marketplace_connections WHERE id = :id';

        return [
            'marketplace' => $this->row($marketplace, $this->marketplaceId),
            'marketplace_on_v2' => $this->row($marketplace, $this->marketplaceOnV2Id),
            'moysklad' => $this->row('SELECT access_token, refresh_token, access_token_encrypted, refresh_token_encrypted, updated_at FROM moysklad_connections WHERE id = :id', $this->moySkladId),
            'bank' => $this->row('SELECT credentials_encrypted, credentials_key_version, updated_at FROM bank_sync_connection WHERE id = :id', $this->bankId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $sql, string $id): array
    {
        $row = $this->connection->fetchAssociative($sql, ['id' => $id]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @param array<string, array<string, mixed>> $secrets
     */
    private function assertNoSecretsIn(string $display, array $secrets): void
    {
        foreach ([self::KEY_V1, self::KEY_V2, self::MARKETPLACE_KEY, self::MARKETPLACE_KEY_ON_V2, self::MOYSKLAD_ACCESS, self::MOYSKLAD_REFRESH, self::BANK_TOKEN] as $secret) {
            self::assertStringNotContainsString($secret, $display);
        }
        self::assertStringNotContainsString((string) $secrets['marketplace']['api_key_encrypted'], $display);
        self::assertStringNotContainsString(substr((string) $secrets['bank']['credentials_encrypted'], 0, 40), $display);
    }
}
