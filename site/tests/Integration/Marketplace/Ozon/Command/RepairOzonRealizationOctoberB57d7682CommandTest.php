<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Ozon\Command;

use App\Marketplace\Application\CloseMonthStageAction;
use App\Marketplace\Ozon\Application\Action\ProcessOzonRealizationAction;
use App\Marketplace\Ozon\Command\RepairOzonRealizationOctoberB57d7682Command;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Tester\CommandTester;

final class RepairOzonRealizationOctoberB57d7682CommandTest extends IntegrationTestCase
{
    private const COMPANY = 'b57d7682-505f-4b74-86f8-953d32d59874';
    private const RAW = 'dda3485d-1aa8-48b5-9e12-bc8f06ddb239';
    private string $hash;

    public function testDryRunDoesNotWriteAndExecuteRestoresOneCopyAndTheRegister(): void
    {
        $this->seed();
        $before = $this->snapshot();
        $tester = $this->tester();
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('doubled', $tester->getDisplay());
        self::assertSame($before, $this->snapshot());
        self::assertSame(0, $tester->execute(['--execute' => true, '--expected-raw-hash' => $this->hash]));
        self::assertSame(3, $this->rows());
        self::assertSame(['REV_SPP_RETURNS' => '-2.00', 'REV_SPP_SALES' => '20.00'], $this->report());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM documents WHERE company_id = ?', [self::COMPANY]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_ozon_realizations WHERE company_id = ? AND pl_document_id IS NULL', [self::COMPANY]));
        $after = $this->snapshot();
        self::assertSame(0, $tester->execute(['--execute' => true, '--expected-raw-hash' => $this->hash]));
        self::assertStringContainsString('already repaired', $tester->getDisplay());
        self::assertSame($after, $this->snapshot());
    }

    public function testExecuteRequiresUnchangedRawHashAndUnlockedPeriod(): void
    {
        $this->seed();
        $before = $this->snapshot();
        self::assertSame(1, $this->tester()->execute(['--execute' => true]));
        self::assertSame($before, $this->snapshot());
        self::assertSame(1, $this->tester()->execute(['--execute' => true, '--expected-raw-hash' => str_repeat('0', 32)]));
        self::assertSame($before, $this->snapshot());
        $this->connection->executeStatement('UPDATE companies SET finance_lock_before = ? WHERE id = ?', ['2026-10-31', self::COMPANY]);
        self::assertSame(1, $this->tester()->execute(['--execute' => true, '--expected-raw-hash' => $this->hash]));
        self::assertSame($before, $this->snapshot());
    }

    public function testPartialDuplicatesAreRejectedWithoutDeletingRows(): void
    {
        $this->seed();
        $this->connection->executeStatement('DELETE FROM marketplace_ozon_realizations WHERE id = (SELECT id FROM marketplace_ozon_realizations WHERE company_id = ? LIMIT 1)', [self::COMPANY]);
        $before = $this->snapshot();
        self::assertSame(1, $this->tester()->execute(['--execute' => true, '--expected-raw-hash' => $this->hash]));
        self::assertSame($before, $this->snapshot());
    }

    public function testUnexpectedUncategorizedDocumentOperationCannotBeLost(): void
    {
        $this->seed();
        $this->connection->executeStatement('INSERT INTO document_operations (id,document_id,amount) SELECT ?, id, 1.00 FROM documents WHERE company_id = ?', [Uuid::uuid7()->toString(), self::COMPANY]);
        $before = $this->snapshot();
        self::assertSame(1, $this->tester()->execute(['--execute' => true, '--expected-raw-hash' => $this->hash]));
        self::assertSame($before, $this->snapshot());
    }

    public function testAlreadyRepairedCannotHideAnIncorrectRegister(): void
    {
        $this->seed();
        self::assertSame(0, $this->tester()->execute(['--execute' => true, '--expected-raw-hash' => $this->hash]));
        $this->connection->executeStatement('UPDATE pl_daily_totals SET amount_income = 19.00 WHERE company_id = ? AND amount_income > 0', [self::COMPANY]);
        $before = $this->snapshot();
        self::assertSame(1, $this->tester()->execute([]));
        self::assertSame($before, $this->snapshot());
    }

    public function testAlreadyRepairedCannotHideBrokenDocumentLinks(): void
    {
        $this->seed();
        self::assertSame(0, $this->tester()->execute(['--execute' => true, '--expected-raw-hash' => $this->hash]));
        foreach ([null, Uuid::uuid7()->toString()] as $wrongDocument) {
            $this->connection->executeStatement('UPDATE marketplace_ozon_realizations SET pl_document_id = ? WHERE company_id = ? AND id = (SELECT id FROM marketplace_ozon_realizations WHERE company_id = ? ORDER BY id LIMIT 1)', [$wrongDocument, self::COMPANY, self::COMPANY]);
            $before = $this->snapshot();
            self::assertSame(1, $this->tester()->execute([]));
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testChangedRawSincePreviewAndForeignOwnerAreRejected(): void
    {
        $this->seed();
        $this->connection->executeStatement('UPDATE marketplace_raw_documents SET raw_data = (raw_data::jsonb || ?::jsonb)::json WHERE company_id = ? AND id = ?', ['{"version":"changed"}', self::COMPANY, self::RAW]);
        $before = $this->snapshot();
        self::assertSame(1, $this->tester()->execute(['--execute' => true, '--expected-raw-hash' => $this->hash]));
        self::assertSame($before, $this->snapshot());
        $user = UserBuilder::aUser()->withIndex(2)->build();
        $company = CompanyBuilder::aCompany()->withIndex(2)->withOwner($user)->build();
        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->flush();
        $this->connection->executeStatement('UPDATE marketplace_raw_documents SET company_id = ? WHERE id = ?', [(string) $company->getId(), self::RAW]);
        self::assertSame(1, $this->tester()->execute([]));
        self::assertSame($before, $this->snapshot());
    }

    public function testFailureWhileReclosingRollsBackRowsDocumentAndMonth(): void
    {
        $this->seed();
        $before = $this->snapshot();
        $key = 'ozon_fail_'.str_replace('-', '', Uuid::uuid7()->toString());
        $this->connection->executeStatement("CREATE FUNCTION $key() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''repair test failure''; END'");
        $this->connection->executeStatement("CREATE TRIGGER $key BEFORE INSERT ON documents FOR EACH ROW EXECUTE FUNCTION $key()");
        self::assertSame(1, $this->tester()->execute(['--execute' => true, '--expected-raw-hash' => $this->hash]));
        self::assertSame($before, $this->snapshot());
    }

    public function testRepairAcrossBatchesPreservesByDayWbCostsAndCfo(): void
    {
        $hash = \App\Tests\Support\Marketplace\OzonRealizationRepairFixture::seed($this->em,
            self::getContainer()->get(ProcessOzonRealizationAction::class),
            self::getContainer()->get(CloseMonthStageAction::class), 500, true);
        $otherSql = "SELECT d.id,d.source,d.stream,d.project_direction_id,d.responsibility_center_id,o.id operation_id,o.amount FROM documents d JOIN document_operations o ON o.document_id=d.id WHERE d.company_id = ? AND (d.source = 'marketplace_wb' OR d.stream='costs') ORDER BY d.id,o.id";
        $dailySql = 'SELECT id,quantity,price_per_unit,total_revenue,cost_price FROM marketplace_sales WHERE company_id = ? ORDER BY id';
        $costsSql = 'SELECT stage_costs_status,stage_costs_pl_document_ids::text FROM marketplace_month_closes WHERE company_id = ?';
        $beforeOther = $this->connection->fetchAllAssociative($otherSql, [self::COMPANY]);
        $beforeDaily = $this->connection->fetchAllAssociative($dailySql, [self::COMPANY]);
        $beforeCosts = $this->connection->fetchAllAssociative($costsSql, [self::COMPANY]);
        $beforeCfo = $this->connection->fetchOne("SELECT responsibility_center_id FROM documents WHERE company_id = ? AND source='marketplace_ozon' AND stream='revenue'", [self::COMPANY]);
        self::assertCount(2, $beforeOther);
        self::assertNotNull($beforeOther[0]['responsibility_center_id']);
        self::assertSame(0, $this->tester()->execute(['--execute' => true, '--expected-raw-hash' => $hash]));
        self::assertSame(501, $this->rows());
        self::assertSame($beforeOther, $this->connection->fetchAllAssociative($otherSql, [self::COMPANY]));
        self::assertSame($beforeDaily, $this->connection->fetchAllAssociative($dailySql, [self::COMPANY]));
        self::assertSame($beforeCosts, $this->connection->fetchAllAssociative($costsSql, [self::COMPANY]));
        self::assertSame($beforeCfo, $this->connection->fetchOne("SELECT responsibility_center_id FROM documents WHERE company_id = ? AND source='marketplace_ozon' AND stream='revenue'", [self::COMPANY]));
        self::assertSame(['COGS_PRODUCT_REV' => '-8.00', 'REV_NOT_SPP' => '30.00', 'REV_SPP_RETURNS' => '-2.00', 'REV_SPP_SALES' => '5007.00'], $this->report());
    }

    public function testRepairRejectsLosingAnExistingDocumentCfo(): void
    {
        $this->seed();
        $center = new \App\Company\Entity\FinancialResponsibilityCenter(self::COMPANY, 'CENTER', 'Test center');
        $this->em->persist($center);
        $this->em->flush();
        $this->connection->executeStatement('UPDATE documents SET responsibility_center_id = ? WHERE company_id = ?', [$center->getId(), self::COMPANY]);
        $docId = (string) $this->connection->fetchOne('SELECT id FROM documents WHERE company_id = ?', [self::COMPANY]);
        $this->em->clear();
        self::getContainer()->get(\App\Finance\Facade\FinanceFacade::class)->updatePLRegisterForDocument($docId);
        $before = $this->snapshot();
        self::assertSame(1, $this->tester()->execute(['--execute' => true, '--expected-raw-hash' => $this->hash]));
        self::assertSame($before, $this->snapshot());
        self::assertSame($center->getId(), $this->connection->fetchOne('SELECT responsibility_center_id FROM documents WHERE company_id = ?', [self::COMPANY]));
    }

    public function testIsoHeaderIsAcceptedByPreviewAndRepair(): void
    {
        $this->seed();
        $this->connection->executeStatement("UPDATE marketplace_raw_documents SET raw_data = jsonb_set(jsonb_set(raw_data::jsonb, '{result,header,start_date}', to_jsonb(?::text)), '{result,header,stop_date}', to_jsonb(?::text))::json WHERE company_id = ? AND id = ?", ['2026-10-01T00:00:00Z', '2026-10-31T23:59:59+00:00', self::COMPANY, self::RAW]);
        $hash = (string) $this->connection->fetchOne('SELECT md5(raw_data::text) FROM marketplace_raw_documents WHERE company_id = ? AND id = ?', [self::COMPANY, self::RAW]);
        self::assertSame(0, $this->tester()->execute([]));
        self::assertSame(0, $this->tester()->execute(['--execute' => true, '--expected-raw-hash' => $hash]));
        self::assertSame(3, $this->rows());
    }

    private function seed(): void
    {
        $this->hash = \App\Tests\Support\Marketplace\OzonRealizationRepairFixture::seed($this->em, self::getContainer()->get(ProcessOzonRealizationAction::class), self::getContainer()->get(CloseMonthStageAction::class));
    }

    private function tester(): CommandTester
    {
        return new CommandTester(self::getContainer()->get(RepairOzonRealizationOctoberB57d7682Command::class));
    }

    private function rows(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_ozon_realizations WHERE company_id = ? AND raw_document_id = ?', [self::COMPANY, self::RAW]);
    }

    /** @return array<string, string> */
    private function report(): array
    {
        return $this->connection->fetchAllKeyValue('SELECT c.code, (SUM(t.amount_income)-SUM(t.amount_expense))::text FROM pl_daily_totals t JOIN pl_categories c ON c.id=t.pl_category_id AND c.company_id=t.company_id WHERE t.company_id = ? GROUP BY c.code ORDER BY c.code', [self::COMPANY]);
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        return [
            'realizations' => $this->connection->fetchAllAssociative('SELECT id,pl_document_id,total_amount,return_amount FROM marketplace_ozon_realizations WHERE company_id = ? ORDER BY id', [self::COMPANY]),
            'report' => $this->report(),
            'month' => $this->connection->fetchAllAssociative('SELECT id,stage_sales_returns_status,stage_sales_returns_pl_document_ids::text ids FROM marketplace_month_closes WHERE company_id = ?', [self::COMPANY]),
            'operations' => $this->connection->fetchAllAssociative('SELECT o.id,o.document_id,o.category_id,o.amount FROM document_operations o JOIN documents d ON d.id=o.document_id WHERE d.company_id = ? ORDER BY o.id', [self::COMPANY]),
        ];
    }
}
