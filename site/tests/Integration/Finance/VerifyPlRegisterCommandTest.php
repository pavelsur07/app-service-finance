<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Finance\Application\Service\PLRegisterUpdater;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\DocumentStatus;
use App\Finance\Enum\DocumentType;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLFlow;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class VerifyPlRegisterCommandTest extends IntegrationTestCase
{
    private const DAY = '2026-01-10';

    private CommandTester $tester;
    private Company $company;
    private ProjectDirection $project;

    protected function setUp(): void
    {
        parent::setUp();

        $application = new Application(self::$kernel ?? throw new \LogicException('Kernel is not booted.'));
        $this->tester = new CommandTester($application->find('app:finance:verify-pl-register'));

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail(Uuid::uuid4()->toString().'@example.com');
        $user->setPassword('password');

        $this->company = new Company(Uuid::uuid4()->toString(), $user);
        $this->company->setName('Verify register company');
        $this->project = new ProjectDirection(Uuid::uuid4()->toString(), $this->company, 'Main');

        $root = new PLCategory(Uuid::uuid4()->toString(), $this->company);
        $root->setName('Revenue');
        $root->setType(PLCategoryType::SUBTOTAL);

        $leaf = new PLCategory(Uuid::uuid4()->toString(), $this->company);
        $leaf->setName('Sales');
        $leaf->setParent($root);
        $leaf->setCode('REV_SALES');
        $leaf->setFlow(PLFlow::INCOME);

        $document = new Document(Uuid::uuid4()->toString(), $this->company);
        $document->setStatus(DocumentStatus::ACTIVE);
        $document->setDate(new \DateTimeImmutable(self::DAY));
        $document->setType(DocumentType::OTHER);
        $document->setProjectDirection($this->project);

        $operation = new DocumentOperation();
        $operation->setPlCategory($leaf);
        $operation->setProjectDirection($this->project);
        $operation->setAmount('150.00');
        $document->addOperation($operation);

        foreach ([$user, $this->company, $this->project, $root, $leaf, $document] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $day = new \DateTimeImmutable(self::DAY);
        self::getContainer()->get(PLRegisterUpdater::class)->recalcRange($this->company, $day, $day);
        $this->em->clear();
    }

    public function testConsistentRegisterPasses(): void
    {
        $exitCode = $this->verify();

        self::assertSame(Command::SUCCESS, $exitCode, $this->tester->getDisplay());
        self::assertStringContainsString('Регистр ОПиУ совпадает с документами', $this->tester->getDisplay());
    }

    public function testChangedAmountFailsAndNothingIsWritten(): void
    {
        $this->connection->executeStatement(
            "UPDATE pl_daily_totals SET amount_income = '140.00' WHERE company_id = :company",
            ['company' => $this->company->getId()],
        );
        $before = $this->registerSnapshot();

        $exitCode = $this->verify();

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertMatchesRegularExpression('/Расходятся суммы\s+1/u', $this->tester->getDisplay());
        self::assertStringContainsString('10.00', $this->tester->getDisplay());
        self::assertSame($before, $this->registerSnapshot());
    }

    public function testMissingRowFails(): void
    {
        $this->connection->executeStatement('DELETE FROM pl_daily_totals WHERE company_id = :company', ['company' => $this->company->getId()]);

        self::assertSame(Command::FAILURE, $this->verify());
        self::assertMatchesRegularExpression('/Нет в регистре\s+1/u', $this->tester->getDisplay());
    }

    public function testUncategorizedRowOnlyInRegisterIsReportedButPasses(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO pl_daily_totals (id, company_id, pl_category_id, date, project_direction_id, responsibility_center_id, amount_income, amount_expense, created_at, updated_at)
             VALUES (:id, :company, NULL, :day, :project, NULL, '0.00', '25.00', now(), now())",
            ['id' => Uuid::uuid4()->toString(), 'company' => $this->company->getId(), 'day' => self::DAY, 'project' => $this->project->getId()],
        );

        self::assertSame(Command::SUCCESS, $this->verify(), $this->tester->getDisplay());
        self::assertStringContainsString('1 (Σ 25.00)', $this->tester->getDisplay());
    }

    public function testWindowOutsideDocumentsChecksNothing(): void
    {
        $this->connection->executeStatement('DELETE FROM pl_daily_totals WHERE company_id = :company', ['company' => $this->company->getId()]);

        self::assertSame(Command::SUCCESS, $this->verify(['--from' => '2026-02-01', '--to' => '2026-02-28']));
    }

    public function testRejectsInvalidCompany(): void
    {
        self::assertSame(Command::INVALID, $this->tester->execute(['--company' => 'not-a-uuid']));
    }

    public function testRejectsReversedWindow(): void
    {
        self::assertSame(Command::INVALID, $this->verify(['--from' => '2026-02-01', '--to' => '2026-01-01']));
    }

    /**
     * @param array<string, string> $options
     */
    private function verify(array $options = []): int
    {
        return $this->tester->execute(['--company' => (string) $this->company->getId()] + $options);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function registerSnapshot(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, amount_income, amount_expense, updated_at FROM pl_daily_totals WHERE company_id = :company ORDER BY id',
            ['company' => $this->company->getId()],
        );
    }
}
