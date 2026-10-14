<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company\Command;

use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RefreshCounterpartyDisplayNamesCommandTest extends IntegrationTestCase
{
    public function testDryRunPrintsReportAndWritesNothing(): void
    {
        // Given
        $owner = UserBuilder::aUser()->withEmail('owner-display-cmd@example.com')->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist(CounterpartyBuilder::aCounterparty()->withCompany($company)->withName('ООО "ИНТЕРНЕТ МАРКЕТИНГ"')->withInn(null)->build());
        $this->em->flush();

        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:counterparty:refresh-display-names'));

        // When
        $exitCode = $tester->execute(['--dry-run' => true]);

        // Then
        self::assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        self::assertStringContainsString('ООО «ИНТЕРНЕТ МАРКЕТИНГ»', $output);
        self::assertStringContainsString('Dry-run', $output);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM "counterparty" WHERE display_rules_version IS NOT NULL'));
    }

    public function testInvalidSamplesOptionIsRejected(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:counterparty:refresh-display-names'));

        self::assertSame(Command::INVALID, $tester->execute(['--samples' => '-1']));
    }
}
