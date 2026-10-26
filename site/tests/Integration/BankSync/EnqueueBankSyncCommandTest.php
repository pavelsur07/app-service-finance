<?php

declare(strict_types=1);

namespace App\Tests\Integration\BankSync;

use App\BankSync\Message\SyncBankConnectionMessage;
use App\Tests\Builders\BankSync\BankConnectionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class EnqueueBankSyncCommandTest extends IntegrationTestCase
{
    public function testActiveConnectionsAreEnqueuedWithCompany(): void
    {
        $companyId = Uuid::uuid4()->toString();
        $active = BankConnectionBuilder::aConnection()->withId(Uuid::uuid7()->toString())->withCompanyId($companyId)->build();
        $inactive = BankConnectionBuilder::aConnection()->withId(Uuid::uuid7()->toString())->withCompanyId($companyId)->inactive()->build();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('bank-enqueue-'.$companyId.'@example.test')->build();
        $this->em->persist($owner);
        $this->em->persist(CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build());
        $this->em->persist($active);
        $this->em->persist($inactive);
        $this->em->flush();

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async_sync');
        $transport->reset();

        $tester = $this->commandTester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--bank' => 'alfa']));

        $messages = array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), iterator_to_array($transport->getSent())),
            static fn (object $message): bool => $message instanceof SyncBankConnectionMessage && $message->companyId === $companyId,
        ));
        self::assertCount(1, $messages);
        self::assertSame($active->getId(), $messages[0]->connectionId);
    }

    public function testUnknownBankIsRejected(): void
    {
        $tester = $this->commandTester();

        self::assertSame(Command::INVALID, $tester->execute(['--bank' => 'unknown']));
    }

    private function commandTester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester((new Application($kernel))->find('bank-sync:enqueue'));
    }
}
