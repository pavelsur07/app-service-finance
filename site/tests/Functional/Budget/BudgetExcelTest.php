<?php

declare(strict_types=1);

namespace App\Tests\Functional\Budget;

use App\Budget\Enum\BudgetVersionStatus;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Budget\BudgetVersionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Бюджет ОПиУ в Excel: выгрузка варианта (этап 6) и загрузка обратно (этап 7).
 */
final class BudgetExcelTest extends WebTestCaseBase
{
    private const COMPANY_ID = '0193eeee-0000-7000-8000-00000000f001';
    private const OTHER_COMPANY_ID = '0193eeee-0000-7000-8000-00000000f002';
    private const PROFIT_ID = '0193eeee-0000-7000-8000-00000000f011';
    private const REVENUE_ID = '0193eeee-0000-7000-8000-00000000f012';
    private const RENT_ID = '0193eeee-0000-7000-8000-00000000f013';
    private const PROJECT_ID = '0193eeee-0000-7000-8000-00000000f021';
    private const SHOP_ID = '0193eeee-0000-7000-8000-00000000f022';

    private KernelBrowser $client;
    private User $owner;
    private User $writer;
    private User $reader;
    private string $centerId;
    private ?string $lastFile = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('budget-xlsx-owner@example.test')->build();
        $this->writer = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('budget-xlsx-writer@example.test')->build();
        $this->reader = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('budget-xlsx-reader@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($this->owner)->build();
        $writeRole = new CompanyRole(Uuid::uuid7()->toString(), 'Финансы — запись', ['finance' => 'write'], $company);
        $readRole = new CompanyRole(Uuid::uuid7()->toString(), 'Финансы — чтение', ['finance' => 'read'], $company);
        $profit = PLCategoryBuilder::aPLCategory()->withId(self::PROFIT_ID)->forCompany($company)->withName('Прибыль')->withCode('PROFIT')->withType(PLCategoryType::SUBTOTAL)->build();
        $revenue = PLCategoryBuilder::aPLCategory()->withId(self::REVENUE_ID)->forCompany($company)->withName('Выручка')->withCode('REV')->withFlow(PLFlow::INCOME)->withParent($profit)->build();
        $rent = PLCategoryBuilder::aPLCategory()->withId(self::RENT_ID)->forCompany($company)->withName('=Аренда')->withCode('RENT')->withFlow(PLFlow::EXPENSE)->withParent($profit)->build();
        $project = new ProjectDirection(self::PROJECT_ID, $company, 'Общий', ProjectDirection::CODE_GENERAL);
        $shop = new ProjectDirection(self::SHOP_ID, $company, 'Магазин');
        $center = new FinancialResponsibilityCenter(self::COMPANY_ID, FinancialResponsibilityCenter::CODE_GENERAL, FinancialResponsibilityCenter::NAME_GENERAL);
        $this->centerId = $center->getId();
        $otherOwner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('budget-xlsx-other@example.test')->build();

        foreach ([
            $this->owner, $this->writer, $this->reader, $company, $writeRole, $readRole,
            CompanyMemberBuilder::aMember()->withId(Uuid::uuid7()->toString())->withCompany($company)->withUser($this->writer)->withAccessRole($writeRole)->build(),
            CompanyMemberBuilder::aMember()->withId(Uuid::uuid7()->toString())->withCompany($company)->withUser($this->reader)->withAccessRole($readRole)->build(),
            $profit, $revenue, $rent, $project, $shop, $center,
            new FinancialResponsibilityCenterProject(self::COMPANY_ID, $project, $center),
            new FinancialResponsibilityCenterProject(self::COMPANY_ID, $shop, $center),
            $otherOwner, CompanyBuilder::aCompany()->withId(self::OTHER_COMPANY_ID)->withOwner($otherOwner)->build(),
        ] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
    }

    public function testExportPairWritesPlanSheetWithExpensesPositive(): void
    {
        $id = $this->version('Базовый', BudgetVersionStatus::DRAFT);
        $this->line($id, self::REVENUE_ID, self::PROJECT_ID, '2027-01-01', '1000.50');
        $this->line($id, self::RENT_ID, self::PROJECT_ID, '2027-01-01', '-300.00');
        $this->line($id, self::RENT_ID, self::PROJECT_ID, '2027-12-01', '-100.00');
        $this->line($id, self::REVENUE_ID, self::SHOP_ID, '2027-01-01', '5000.00');
        $this->login($this->reader);

        $rows = $this->export($id, sprintf('?project=%s&center=%s', self::PROJECT_ID, $this->centerId));

        self::assertStringContainsString('attachment; filename=budget_2027_', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('Базовый', (string) $rows[1][0]);
        self::assertStringContainsString('Общий × ', implode(' ', array_map('strval', $rows[2])));
        self::assertSame(sprintf('year=2027;project=%s;center=%s', self::PROJECT_ID, $this->centerId), $rows[4][1]);
        self::assertSame(['Код', 'Статья'], \array_slice($rows[6], 0, 2));
        self::assertSame('ID статьи', $rows[6][15]);

        $byId = [];
        foreach (\array_slice($rows, 6) as $row) {
            $byId[(string) ($row[15] ?? '')] = $row;
        }
        self::assertEqualsWithDelta(1000.5, $byId[self::REVENUE_ID][2], 0.001);
        self::assertEqualsWithDelta(300.0, $byId[self::RENT_ID][2], 0.001, 'расход — в знаке ввода');
        self::assertEqualsWithDelta(100.0, $byId[self::RENT_ID][13], 0.001);
        self::assertEqualsWithDelta(400.0, $byId[self::RENT_ID][14], 0.001, 'год');
        self::assertEqualsWithDelta(700.5, $byId[self::PROFIT_ID][2], 0.001, 'итог — как в сетке');
        self::assertSame('RENT', $byId[self::RENT_ID][0]);
        self::assertStringContainsString('=Аренда', (string) $byId[self::RENT_ID][1]);
        self::assertStringNotContainsString('<f>', $this->sheetXml(), 'название на «=» — текст, не формула');
    }

    public function testExportWholeCompanySumsPairs(): void
    {
        $id = $this->version('Базовый', BudgetVersionStatus::APPROVED);
        $this->line($id, self::REVENUE_ID, self::PROJECT_ID, '2027-01-01', '1000.00');
        $this->line($id, self::REVENUE_ID, self::SHOP_ID, '2027-01-01', '5000.00');
        $this->login($this->reader);

        $rows = $this->export($id, '');

        self::assertStringContainsString('Вся компания', implode(' ', array_map('strval', $rows[2])));
        self::assertSame('year=2027;project=;center=', $rows[4][1]);
        $revenue = array_values(array_filter($rows, static fn (array $row): bool => self::REVENUE_ID === ($row[15] ?? null)));
        self::assertEqualsWithDelta(6000.0, $revenue[0][2], 0.001);
    }

    public function testExportRejectsForeignVersionInvalidPairAndGuest(): void
    {
        $foreign = $this->version('Чужой', BudgetVersionStatus::DRAFT, self::OTHER_COMPANY_ID);
        $own = $this->version('Свой', BudgetVersionStatus::DRAFT);

        $this->client->request('GET', '/finance/budget/versions/'.$own.'/export');
        self::assertResponseRedirects();

        $this->login($this->reader);
        $this->client->request('GET', '/finance/budget/versions/'.$foreign.'/export');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', sprintf('/finance/budget/versions/%s/export?project=%s&center=%s', $own, self::PROJECT_ID, Uuid::uuid7()->toString()));
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return array<int, list<mixed>> номер строки листа с 1 → значения
     */
    private function export(string $versionId, string $query): array
    {
        $this->client->request('GET', '/finance/budget/versions/'.$versionId.'/export'.$query);
        self::assertResponseIsSuccessful();
        $file = tempnam(sys_get_temp_dir(), 'budget_xlsx_test_');
        self::assertIsString($file);
        file_put_contents($file, $this->client->getInternalResponse()->getContent());
        $this->lastFile = $file;

        $options = new Options();
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $reader = new Reader($options);
        $reader->open($file);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            self::assertSame('План', $sheet->getName());
            foreach ($sheet->getRowIterator() as $index => $row) {
                $rows[$index] = array_map(static fn ($cell): mixed => $cell->getValue(), $row->getCells());
            }
            break;
        }
        $reader->close();

        return $rows;
    }

    private function sheetXml(): string
    {
        self::assertNotNull($this->lastFile);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->lastFile));
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        return $xml;
    }

    protected function tearDown(): void
    {
        if (null !== $this->lastFile && is_file($this->lastFile)) {
            unlink($this->lastFile);
        }
        parent::tearDown();
    }

    private function line(string $versionId, string $categoryId, string $projectId, string $month, string $amount): void
    {
        $this->em()->getConnection()->executeStatement(
            "INSERT INTO budget_lines (id, company_id, version_id, pl_category_id, month, project_direction_id, responsibility_center_id, amount, source, comment, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'manual', NULL, NOW())",
            [Uuid::uuid7()->toString(), self::COMPANY_ID, $versionId, $categoryId, $month, $projectId, $this->centerId, $amount],
        );
    }

    private function version(string $name, BudgetVersionStatus $status, string $companyId = self::COMPANY_ID): string
    {
        $id = Uuid::uuid7()->toString();
        $this->em()->persist(BudgetVersionBuilder::aVersion()->withId($id)->forCompany($companyId)->withYear(2027)->withName($name)->withStatus($status)->build());
        $this->em()->flush();
        $this->em()->clear();

        return $id;
    }

    private function login(User $user): void
    {
        $this->client->loginUser($user);
        $this->setClientSessionValue($this->client, 'active_company_id', self::COMPANY_ID);
    }
}
