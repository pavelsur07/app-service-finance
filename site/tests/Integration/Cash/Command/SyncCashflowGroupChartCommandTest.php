<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Command;

use App\Cash\Application\Category\EnsureCashflowSystemCategoriesAction;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Company\Entity\Company;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncCashflowGroupChartCommandTest extends IntegrationTestCase
{
    public function testPlanThenExecuteThenNothingToDo(): void
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('cmd-'.Uuid::uuid4()->toString().'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();
        self::getContainer()->get(EnsureCashflowSystemCategoriesAction::class)((string) $company->getId());
        $this->em->flush();

        $plan = $this->runSync((string) $company->getId(), []);
        self::assertStringContainsString('Эталон: базовое дерево (core.yaml)', $plan->getDisplay());
        self::assertStringContainsString('Будет создано: 37', $plan->getDisplay());
        self::assertStringContainsString('Режим просмотра', $plan->getDisplay());
        self::assertSame(0, $this->coded((string) $company->getId()));

        $applied = $this->runSync((string) $company->getId(), ['--execute' => true]);
        self::assertSame(Command::SUCCESS, $applied->getStatusCode(), $applied->getDisplay());
        self::assertStringContainsString('создано: 37', $applied->getDisplay());
        $this->em->clear();
        self::assertSame(37, $this->coded((string) $company->getId()));

        self::assertStringContainsString('менять нечего', $this->runSync((string) $company->getId(), ['--execute' => true])->getDisplay());
    }

    /** Остались только конфликты — команда не говорит, что дерево совпадает с эталоном. */
    public function testOnlyConflictsAreNotReportedAsInSync(): void
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('cmd-'.Uuid::uuid4()->toString().'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();
        self::getContainer()->get(EnsureCashflowSystemCategoriesAction::class)((string) $company->getId());
        $this->em->flush();
        $this->runSync((string) $company->getId(), ['--execute' => true]);
        // Код с ветки «Выплаты» снят, рядом вторая «Выплаты»: привязать нечего, остаётся конфликт.
        $this->em->getConnection()->executeStatement("UPDATE cashflow_categories SET group_code = NULL WHERE company_id = ? AND group_code LIKE 'OP_OUT%'", [$company->getId()]);
        $this->em->clear();
        $managed = $this->em->find(Company::class, $company->getId());
        \assert($managed instanceof Company);
        $op = $this->em->getRepository(CashflowCategory::class)->findOneBy(['company' => $managed, 'systemCode' => CashflowCategory::CODE_OPERATING]);
        \assert($op instanceof CashflowCategory);
        $duplicate = (new CashflowCategory(Uuid::uuid7()->toString(), $managed))->setName('Выплаты')->setParent($op);
        $duplicate->syncFlowKindWithParent();
        $this->em->persist($duplicate);
        $this->em->flush();
        $this->em->clear();

        $display = $this->runSync((string) $company->getId(), [])->getDisplay();

        self::assertStringContainsString('Конфликты', $display);
        self::assertStringNotContainsString('совпадает с эталоном', $display);
        self::assertStringContainsString('сопоставление не завершено', $display);
    }

    public function testUnknownCompanyFails(): void
    {
        $tester = $this->runSync(Uuid::uuid7()->toString(), []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('не найдена', $tester->getDisplay());
    }

    /** @param array<string, bool> $options */
    private function runSync(string $companyId, array $options): CommandTester
    {
        \assert(null !== self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('app:cash:group-chart:sync'));
        $tester->execute(['companyId' => $companyId] + $options);

        return $tester;
    }

    private function coded(string $companyId): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cashflow_categories WHERE company_id = ? AND group_code IS NOT NULL', [$companyId]);
    }
}
