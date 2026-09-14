<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\MoySklad\Application\Action\EncryptConnectionSecretsAction;
use App\MoySklad\Command\EncryptConnectionSecretsCommand;
use App\MoySklad\Entity\MoySkladConnection;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\MoySklad\Infrastructure\Security\ConnectionTokenCodec;
use App\Shared\Security\Contract\FieldEncryptionServiceInterface;
use App\Shared\Security\ValueObject\EncryptedPayload;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class EncryptConnectionSecretsTest extends WebTestCaseBase
{
    private const COMPANY = '11111111-1111-1111-1111-111111111111';

    public function testDryRunDoesNotChangeEitherSecret(): void
    {
        $this->resetDb();
        $connection = $this->existing();
        $result = ($this->action())(self::COMPANY, false);
        $connection = $this->reload($connection);

        self::assertSame(['scanned' => 1, 'pending' => 1, 'encrypted' => 0], $result);
        self::assertSame('private-access', $connection->getAccessToken());
        self::assertSame('private-refresh', $connection->getRefreshToken());
        self::assertNull($connection->getAccessTokenEncrypted());
        self::assertNull($connection->getRefreshTokenEncrypted());
    }

    public function testExecuteEncryptsBothAndIsIdempotentAndCompanyScoped(): void
    {
        $this->resetDb();
        $connection = $this->existing();
        $foreign = $this->existing('22222222-2222-2222-2222-222222222222');
        $result = ($this->action())(self::COMPANY, true);
        $connection = $this->reload($connection);
        $foreign = $this->reload($foreign);

        self::assertSame(['scanned' => 1, 'pending' => 1, 'encrypted' => 1], $result);
        self::assertNull($connection->getAccessToken());
        self::assertNull($connection->getRefreshToken());
        $encryption = static::getContainer()->get(FieldEncryptionServiceInterface::class);
        self::assertIsString($connection->getAccessTokenEncrypted());
        self::assertIsString($connection->getRefreshTokenEncrypted());
        self::assertSame('private-access', $encryption->decrypt(EncryptedPayload::fromStorageJson($connection->getAccessTokenEncrypted())));
        self::assertSame('private-refresh', $encryption->decrypt(EncryptedPayload::fromStorageJson($connection->getRefreshTokenEncrypted())));
        self::assertSame('private-access', $foreign->getAccessToken());
        self::assertSame(['scanned' => 1, 'pending' => 0, 'encrypted' => 0], ($this->action())(self::COMPANY, true));
    }

    public function testFailedVerificationRollsBackBothSecretsAndCommandHidesPayload(): void
    {
        $this->resetDb();
        $connection = $this->existing();
        $connection->setRefreshTokenEncrypted('private-invalid-payload');
        $this->em()->flush();
        $tester = new CommandTester(new EncryptConnectionSecretsCommand($this->action()));
        $status = $tester->execute(['--company' => self::COMPANY, '--execute' => true]);
        $connection = $this->reload($connection);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringNotContainsString('private-', $tester->getDisplay());
        self::assertSame('private-access', $connection->getAccessToken());
        self::assertSame('private-refresh', $connection->getRefreshToken());
        self::assertNull($connection->getAccessTokenEncrypted());
    }

    public function testProcessesMoreThanOneBatch(): void
    {
        $this->resetDb();
        for ($i = 0; $i < 101; ++$i) {
            $connection = new MoySkladConnection(Uuid::uuid7()->toString(), self::COMPANY, 'Склад '.$i, 'https://example.test');
            $connection->setAccessToken('private-access');
            $this->em()->persist($connection);
        }
        $this->em()->flush();

        self::assertSame(['scanned' => 101, 'pending' => 101, 'encrypted' => 101], ($this->action())(self::COMPANY, true));
        self::assertSame(['scanned' => 101, 'pending' => 0, 'encrypted' => 0], ($this->action())(self::COMPANY, false));
    }

    public function testCommandRequiresCompanyAndDefaultsToDryRun(): void
    {
        $this->resetDb();
        $connection = $this->existing();
        $tester = new CommandTester(new EncryptConnectionSecretsCommand($this->action()));
        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertSame(Command::SUCCESS, $tester->execute(['--company' => self::COMPANY]));
        self::assertStringContainsString('DRY-RUN', $tester->getDisplay());
        $connection = $this->reload($connection);
        self::assertSame('private-access', $connection->getAccessToken());
    }

    private function existing(string $companyId = self::COMPANY): MoySkladConnection
    {
        $connection = new MoySkladConnection(Uuid::uuid7()->toString(), $companyId, 'Склад', 'https://example.test');
        $connection->setAccessToken('private-access')->setRefreshToken('private-refresh');
        $this->em()->persist($connection);
        $this->em()->flush();

        return $connection;
    }

    private function reload(MoySkladConnection $connection): MoySkladConnection
    {
        $fresh = static::getContainer()->get(MoySkladConnectionWriteRepository::class)->findByIdAndCompanyId($connection->getId(), $connection->getCompanyId());
        self::assertNotNull($fresh);

        return $fresh;
    }

    private function action(): EncryptConnectionSecretsAction
    {
        return new EncryptConnectionSecretsAction(
            static::getContainer()->get(MoySkladConnectionWriteRepository::class),
            $this->em(),
            static::getContainer()->get(ConnectionTokenCodec::class),
        );
    }
}
