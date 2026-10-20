<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company\Command;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\User;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PruneSpamAccountsCommandTest extends IntegrationTestCase
{
    private const WINDOW = ['--registered-from' => '2026-01-14', '--registered-to' => '2026-01-16'];
    private const IN_WINDOW = '2026-01-14 10:00:00';

    public function testDryRunReportsCandidatesAndRejectionsAndWritesNothing(): void
    {
        $spam = $this->spamAccount();
        $this->protectedAccounts();

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(self::WINDOW), $tester->getDisplay());

        $display = $tester->getDisplay();
        self::assertStringContainsString('dry-run', $display);
        self::assertMatchesRegularExpression('/Пользователей в окне\s+5/u', $display);
        self::assertMatchesRegularExpression('/Кандидатов на удаление\s+1/u', $display);
        self::assertStringContainsString('admin', $display);
        self::assertStringContainsString('counterparty.company_id', $display);
        self::assertStringContainsString('company_members.user_id', $display);
        self::assertStringContainsString('no_company', $display);
        self::assertStringNotContainsString('@example.test', $display, 'Адреса почты в вывод не попадают.');
        self::assertSame(1, $this->userExists($spam[0]));
        self::assertSame(3, $this->seedRows($spam[1]));
    }

    public function testExecuteRequiresMatchingExpectedCount(): void
    {
        $spam = $this->spamAccount();
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(self::WINDOW + ['--execute' => true]), $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->execute(self::WINDOW + ['--execute' => true, '--expected-count' => '2']), $tester->getDisplay());
        self::assertStringContainsString('повторите dry-run', $tester->getDisplay());
        self::assertSame(1, $this->userExists($spam[0]));
    }

    public function testExecuteDeletesOnlyEmptySpamAccounts(): void
    {
        $spam = $this->spamAccount();
        $protected = $this->protectedAccounts();

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(self::WINDOW + ['--execute' => true, '--expected-count' => '1']), $tester->getDisplay());

        self::assertSame(0, $this->userExists($spam[0]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM companies WHERE id = ?', [$spam[1]]));
        self::assertSame(0, $this->seedRows($spam[1]));
        self::assertMatchesRegularExpression('/Осталось кандидатов\s+0/u', $tester->getDisplay());

        foreach ($protected as $userId) {
            self::assertSame(1, $this->userExists($userId), 'Аккаунт с данными, админ или вне окна не удаляется.');
        }

        self::assertSame(Command::SUCCESS, $tester->execute(self::WINDOW), $tester->getDisplay());
        self::assertMatchesRegularExpression('/Кандидатов на удаление\s+0/u', $tester->getDisplay());
    }

    public function testInvalidWindowIsRejected(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertSame(Command::INVALID, $tester->execute(['--registered-from' => '2026-01-16', '--registered-to' => '2026-01-14']));
        self::assertSame(Command::INVALID, $tester->execute(['--registered-from' => '2026-02-30', '--registered-to' => '2026-03-01']));
    }

    /**
     * @return array{string, string} id пользователя и его кабинета
     */
    private function spamAccount(): array
    {
        [$user, $company] = $this->owner(self::IN_WINDOW);
        $this->em->flush();
        $this->seed($company);

        return [(string) $user->getId(), (string) $company->getId()];
    }

    /**
     * Аккаунты, которые команда обязана пропустить.
     *
     * @return list<string> id пользователей
     */
    private function protectedAccounts(): array
    {
        [$withData, $dataCompany] = $this->owner(self::IN_WINDOW);
        $this->em->persist(CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid7()->toString())->withCompany($dataCompany)->build());

        [$admin] = $this->owner(self::IN_WINDOW, ['ROLE_ADMIN']);

        [$member, $ownCompany] = $this->owner(self::IN_WINDOW);
        [, $foreignCompany] = $this->owner('2025-06-01 10:00:00');
        $this->em->persist(CompanyMemberBuilder::aMember()
            ->withId(Uuid::uuid7()->toString())
            ->withCompany($foreignCompany)
            ->withUser($member)
            ->withRole(CompanyMember::ROLE_OPERATOR)
            ->build());

        $withoutCompany = $this->user(self::IN_WINDOW);

        [$outsideWindow, $outsideCompany] = $this->owner('2026-01-16 00:00:00');
        $this->em->flush();
        $this->seed($dataCompany);
        $this->seed($ownCompany);
        $this->seed($outsideCompany);

        return array_map(static fn (User $u): string => (string) $u->getId(), [$withData, $admin, $member, $withoutCompany, $outsideWindow]);
    }

    /**
     * @param list<string> $roles
     *
     * @return array{User, Company}
     */
    private function owner(string $createdAt, array $roles = ['ROLE_COMPANY_OWNER']): array
    {
        $user = $this->user($createdAt, $roles);
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($user)->build();
        $this->em->persist($company);

        return [$user, $company];
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $createdAt, array $roles = ['ROLE_COMPANY_OWNER']): User
    {
        $user = UserBuilder::aUser()
            ->withId(Uuid::uuid7()->toString())
            ->withEmail(Uuid::uuid7()->toString().'@example.test')
            ->withRoles($roles)
            ->withCreatedAt(new \DateTimeImmutable($createdAt))
            ->build();
        $this->em->persist($user);

        return $user;
    }

    /**
     * Автосид кабинета, как у спам-регистраций на проде: направление, ЦФО и их связь.
     */
    private function seed(Company $company): void
    {
        $companyId = (string) $company->getId();
        $directionId = Uuid::uuid7()->toString();
        $centerId = Uuid::uuid7()->toString();

        $this->connection->executeStatement(
            'INSERT INTO project_directions (id, company_id, name, sort) VALUES (?, ?, ?, 0)',
            [$directionId, $companyId, 'Основное'],
        );
        $this->connection->executeStatement(
            "INSERT INTO financial_responsibility_centers (id, company_id, code, name, sort, status, version, created_at, updated_at)
             VALUES (?, ?, 'main', 'Основной', 0, 'active', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
            [$centerId, $companyId],
        );
        $this->connection->executeStatement(
            'INSERT INTO financial_responsibility_center_projects (id, company_id, project_direction_id, responsibility_center_id, created_at)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)',
            [Uuid::uuid7()->toString(), $companyId, $directionId, $centerId],
        );
    }

    private function seedRows(string $companyId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT (SELECT COUNT(*) FROM project_directions WHERE company_id = :c)
                  + (SELECT COUNT(*) FROM financial_responsibility_centers WHERE company_id = :c)
                  + (SELECT COUNT(*) FROM financial_responsibility_center_projects WHERE company_id = :c)',
            ['c' => $companyId],
        );
    }

    private function userExists(string $userId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM "user" WHERE id = ?', [$userId]);
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel ?? throw new \LogicException('Kernel not booted.'));

        return new CommandTester($application->find('app:company:prune-spam-accounts'));
    }
}
