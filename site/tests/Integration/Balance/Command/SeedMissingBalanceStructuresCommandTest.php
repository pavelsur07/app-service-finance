<?php

declare(strict_types=1);

namespace App\Tests\Integration\Balance\Command;

use App\Balance\Application\SeedBalanceStructureAction;
use App\Balance\Application\SeedMissingBalanceStructuresAction;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class SeedMissingBalanceStructuresCommandTest extends IntegrationTestCase
{
    public function testDryRunListsOnlyCompaniesWithoutStructureAndWritesNothing(): void
    {
        [$bare, $seeded, $initialized] = $this->threeCompanies();

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]), $tester->getDisplay());
        self::assertStringContainsString('dry-run', $tester->getDisplay());
        self::assertStringContainsString($bare, $tester->getDisplay());
        self::assertStringNotContainsString($seeded, $tester->getDisplay());
        self::assertStringNotContainsString($initialized, $tester->getDisplay());
        self::assertSame(0, $this->articleCount($bare));
    }

    public function testExecuteRequiresAndChecksExpectedCountBeforeAnyWrite(): void
    {
        [$bare] = $this->threeCompanies();
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['--execute' => true]), $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->execute(['--execute' => true, '--expected-count' => (string) ($this->candidateCount() + 1)]), $tester->getDisplay());
        self::assertStringContainsString('повторите dry-run', $tester->getDisplay());
        self::assertSame(0, $this->articleCount($bare));
    }

    public function testExecuteSeedsOnlyMissingAndRerunIsNoop(): void
    {
        [$bare, $seeded, $initialized] = $this->threeCompanies();
        $seededBefore = $this->articleCount($seeded);
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--execute' => true, '--expected-count' => (string) $this->candidateCount()]), $tester->getDisplay());

        self::assertSame(33, $this->articleCount($bare));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM balance_audit_events WHERE company_id=? AND action='system_seed'", [$bare]));
        self::assertSame($seededBefore, $this->articleCount($seeded));
        self::assertSame(0, $this->articleCount($initialized), 'Инициализированная книга без статей — решение пользователя, не трогаем.');

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertSame(0, $this->candidateCount());
    }

    /**
     * @return array{string, string, string} компания без структуры, с готовой структурой, с инициализированной книгой
     */
    private function threeCompanies(): array
    {
        $ids = [];
        foreach ([1, 2, 3] as $i) {
            $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
            $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
            $this->em->persist($owner);
            $this->em->persist($company);
            $ids[] = (string) $company->getId();
        }
        $this->em->flush();

        self::getContainer()->get(SeedBalanceStructureAction::class)($ids[1]);
        $this->connection->executeStatement("INSERT INTO balance_books (id,company_id,currency,start_date,initialized,version,next_document_number,next_posting_sequence,created_at,updated_at) VALUES (?,?,'RUB','2025-01-01',true,0,1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)", [Uuid::uuid7()->toString(), $ids[2]]);

        return [$ids[0], $ids[1], $ids[2]];
    }

    private function candidateCount(): int
    {
        return \count((self::getContainer()->get(SeedMissingBalanceStructuresAction::class))(false, null)['candidates']);
    }

    private function articleCount(string $companyId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM balance_articles WHERE company_id=?', [$companyId]);
    }

    private function tester(): CommandTester
    {
        self::assertNotNull(self::$kernel);
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:balance:seed-missing-structure'));
    }
}
