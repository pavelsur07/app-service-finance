<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Регрессия для прод-инцидента 2026-04-26 — `app:finance:recalc-pl-register`
 * без позиционного `companyId` падал с
 *   SQLSTATE[42P01]: Undefined table: relation "company" does not exist
 * потому что `CompanyRepository::getAllActiveCompanyIds()` запрашивает
 * `SELECT id FROM company WHERE is_active = true`, а реальная таблица —
 * `companies` без поля `is_active`. Фикс убрал зависимость команды
 * от этого репо-метода.
 */
final class RecalcPlRegisterCommandTest extends IntegrationTestCase
{
    private const COMPANY_ID_A = '11111111-1111-1111-1111-000000000201';
    private const COMPANY_ID_B = '11111111-1111-1111-1111-000000000202';
    private const OWNER_ID_A = '22222222-2222-2222-2222-000000000201';
    private const OWNER_ID_B = '22222222-2222-2222-2222-000000000202';

    public function testResolveCompaniesReturnsAllCompaniesWhenNoIdGiven(): void
    {
        $this->seedCompany(self::COMPANY_ID_A, self::OWNER_ID_A, 'recalc-a@example.test', 'Company A');
        $this->seedCompany(self::COMPANY_ID_B, self::OWNER_ID_B, 'recalc-b@example.test', 'Company B');
        $this->em->flush();

        $tester = $this->makeCommandTester();
        $exit = $tester->execute([
            '--from' => '2026-03-01',
            '--to' => '2026-03-31',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit);

        $display = $tester->getDisplay();
        self::assertStringContainsString(self::COMPANY_ID_A, $display);
        self::assertStringContainsString(self::COMPANY_ID_B, $display);
    }

    public function testResolveCompaniesWithExplicitIdReturnsOneCompany(): void
    {
        $this->seedCompany(self::COMPANY_ID_A, self::OWNER_ID_A, 'recalc-a@example.test', 'Company A');
        $this->seedCompany(self::COMPANY_ID_B, self::OWNER_ID_B, 'recalc-b@example.test', 'Company B');
        $this->em->flush();

        $tester = $this->makeCommandTester();
        $exit = $tester->execute([
            'companyId' => self::COMPANY_ID_A,
            '--from' => '2026-03-01',
            '--to' => '2026-03-31',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit);

        $display = $tester->getDisplay();
        self::assertStringContainsString(self::COMPANY_ID_A, $display);
        self::assertStringNotContainsString(self::COMPANY_ID_B, $display);
    }

    public function testResolveCompaniesReturnsErrorWhenIdNotFound(): void
    {
        $missingId = '11111111-1111-1111-1111-000000000999';

        $tester = $this->makeCommandTester();
        $exit = $tester->execute([
            'companyId' => $missingId,
            '--from' => '2026-03-01',
            '--to' => '2026-03-31',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString($missingId, $tester->getDisplay());
        self::assertStringContainsString('не найдена', $tester->getDisplay());
    }

    public function testEmptyDatabaseExitsCleanly(): void
    {
        $tester = $this->makeCommandTester();
        $exit = $tester->execute([
            '--from' => '2026-03-01',
            '--to' => '2026-03-31',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Не найдено ни одной компании', $tester->getDisplay());
    }

    private function makeCommandTester(): CommandTester
    {
        $app = new Application(self::$kernel);
        $command = $app->find('app:finance:recalc-pl-register');

        return new CommandTester($command);
    }

    private function seedCompany(string $companyId, string $ownerId, string $email, string $name): void
    {
        $owner = UserBuilder::aUser()
            ->withId($ownerId)
            ->withEmail($email)
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId($companyId)
            ->withOwner($owner)
            ->withName($name)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);
    }
}
