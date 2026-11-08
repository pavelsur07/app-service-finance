<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance\Command;

use App\Company\Entity\Company;
use App\Company\Enum\BusinessProfile;
use App\Finance\Application\Action\ApplyDefaultPLTemplateAction;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLTemplateApplicationSource;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class UpgradeUniversalPlTreeV4CommandTest extends IntegrationTestCase
{
    public function testDryRunThenExecuteBringsV3TreeToV4OnceAndKeepsUserName(): void
    {
        $companyId = $this->v3Company();
        $this->connection->executeStatement("UPDATE pl_categories SET name = 'Производство' WHERE company_id = ? AND code = 'OVERHEAD_PROD_TOTAL'", [$companyId]);
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:finance:pl-tree:upgrade-universal-v4'));

        self::assertSame(Command::SUCCESS, $tester->execute(['companyId' => $companyId]));
        self::assertStringContainsString('plan (dry run): rename 1, delete 2, create 1', $tester->getDisplay());
        self::assertStringContainsString('«Производство» — название изменено пользователем', $tester->getDisplay());
        self::assertSame(44, $this->categories($companyId));

        self::assertSame(Command::SUCCESS, $tester->execute(['companyId' => $companyId, '--execute' => true]));
        self::assertStringContainsString('applied: rename 1, delete 2, create 1', $tester->getDisplay());
        self::assertSame(43, $this->categories($companyId));
        self::assertSame(
            ['BELOW_EBITDA_OTHER_EXPENSE' => 'Штрафы, пени и разовые убытки', 'OVERHEAD_PROD_TOTAL' => 'Производство', 'OVERHEAD_TOTAL' => 'Косвенные расходы'],
            $this->connection->fetchAllKeyValue("SELECT code, name FROM pl_categories WHERE company_id = ? AND code IN ('OVERHEAD_TOTAL', 'OVERHEAD_PROD_TOTAL', 'BELOW_EBITDA_OTHER_EXPENSE', 'OVERHEAD_SALES_CONTENT', 'OVERHEAD_SALES_SUBS') ORDER BY code", [$companyId]),
        );

        $this->em->clear();
        self::assertSame(Command::SUCCESS, $tester->execute(['companyId' => $companyId, '--execute' => true]));
        self::assertStringContainsString('applied: rename 0, delete 0, create 0', $tester->getDisplay());
        self::assertSame(43, $this->categories($companyId));
    }

    public function testTreeWithMarketplaceCodesIsRefused(): void
    {
        $companyId = $this->v3Company();
        $company = $this->em->find(Company::class, $companyId);
        self::assertInstanceOf(Company::class, $company);
        $this->em->persist(PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Хранение')->withCode('OPEX_WH_STORAGE')->build());
        $this->em->flush();
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:finance:pl-tree:upgrade-universal-v4'));

        self::assertSame(Command::FAILURE, $tester->execute(['companyId' => $companyId, '--execute' => true]));
        self::assertStringContainsString('коды вне шаблона «Базовый»: OPEX_WH_STORAGE', $tester->getDisplay());
        self::assertSame(45, $this->categories($companyId));
    }

    /**
     * Компания с деревом «Базовый» v3: 44 статьи, старые названия групп, без статьи разовых убытков.
     */
    private function v3Company(): string
    {
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $company->setBusinessProfile(BusinessProfile::UNIVERSAL);
        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->flush();
        $companyId = (string) $company->getId();
        (self::getContainer()->get(ApplyDefaultPLTemplateAction::class))($companyId, false, PLTemplateApplicationSource::ONBOARDING);

        $db = $this->connection;
        $db->executeStatement("DELETE FROM pl_categories WHERE company_id = ? AND code = 'BELOW_EBITDA_OTHER_EXPENSE'", [$companyId]);
        $db->executeStatement("UPDATE pl_categories SET name = 'Операционные расходы' WHERE company_id = ? AND code = 'OVERHEAD_TOTAL'", [$companyId]);
        $db->executeStatement("UPDATE pl_categories SET name = 'Производственные' WHERE company_id = ? AND code = 'OVERHEAD_PROD_TOTAL'", [$companyId]);
        $this->em->clear();
        $company = $this->em->find(Company::class, $companyId);
        self::assertInstanceOf(Company::class, $company);
        $sales = $this->em->getRepository(PLCategory::class)->findOneBy(['company' => $company, 'code' => 'OVERHEAD_SALES_TOTAL']);
        self::assertInstanceOf(PLCategory::class, $sales);
        foreach (['OVERHEAD_SALES_CONTENT' => 'Фото, видео, дизайн', 'OVERHEAD_SALES_SUBS' => 'Сервисы и подписки (коммерческие)'] as $code => $name) {
            $this->em->persist(PLCategoryBuilder::aPLCategory()->forCompany($company)->withParent($sales)->withName($name)->withCode($code)->build());
        }
        $this->em->flush();
        $this->em->clear();
        self::assertSame(44, $this->categories($companyId));

        return $companyId;
    }

    private function categories(string $companyId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pl_categories WHERE company_id = ?', [$companyId]);
    }
}
