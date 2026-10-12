<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance\Command;

use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ApplyDefaultPLTreeCommandTest extends IntegrationTestCase
{
    public function testDryRunWritesNothingAndExecuteCreatesTheTemplateOnce(): void
    {
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->flush();
        $companyId = (string) $company->getId();

        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:finance:pl-tree:apply-default'));

        self::assertSame(Command::SUCCESS, $tester->execute(['companyId' => $companyId]));
        self::assertStringContainsString('plan (dry run): create 70, keep existing 0', $tester->getDisplay());
        self::assertSame(0, $this->categories($companyId));

        self::assertSame(Command::SUCCESS, $tester->execute(['companyId' => $companyId, '--execute' => true]));
        self::assertStringContainsString('applied: create 70, keep existing 0', $tester->getDisplay());
        self::assertSame(70, $this->categories($companyId));

        $this->em->clear();
        self::assertSame(Command::SUCCESS, $tester->execute(['companyId' => $companyId, '--execute' => true]));
        self::assertStringContainsString('applied: create 0, keep existing 70', $tester->getDisplay());
        self::assertSame(70, $this->categories($companyId));
    }

    public function testUnknownCompanyFails(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:finance:pl-tree:apply-default'));

        self::assertSame(Command::FAILURE, $tester->execute(['companyId' => '00000000-0000-4000-8000-000000000000']));
        self::assertStringContainsString('Целевая компания не найдена.', $tester->getDisplay());
    }

    public function testNonUuidCompanyIsRejectedBeforeAnyQuery(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:finance:pl-tree:apply-default'));

        self::assertSame(Command::INVALID, $tester->execute(['companyId' => '78f0cc9f']));
    }

    private function categories(string $companyId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pl_categories WHERE company_id = ?', [$companyId]);
    }
}
