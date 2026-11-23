<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Finance\Enum\PLDocumentSource;
use App\Finance\Enum\PLDocumentStream;
use App\Finance\Enum\PLFlow;
use App\Finance\Facade\FinanceFacade;
use App\Marketplace\DTO\PLEntryDTO;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Строка marketplace_pl хранит значение для отчёта: доход +, расход −. Этап выручки
 * приводится к этому правилу по природе статьи — флаг is_negative маппинга продаж
 * противоречив и на знак не влияет; затраты уже в знаке отчёта и не меняются.
 */
final class CreatePLDocumentSignTest extends IntegrationTestCase
{
    public function testRevenueStreamLinesTakeSignFromCategorySide(): void
    {
        [$companyId, $income, $expense] = $this->company();

        $id = $this->facade()->createPLDocument($companyId, PLDocumentSource::MARKETPLACE_OZON, PLDocumentStream::REVENUE, '2026-10-01', '2026-10-31', [
            $this->entry($income, '500.00', false),
            $this->entry($income, '30.00', true),
            $this->entry($expense, '100.00', false),
            $this->entry($expense, '40.00', true),
        ]);

        self::assertSame(['500.00', '30.00', '-40.00', '-100.00'], $this->amounts($id));
    }

    public function testCostsStreamLinesKeepMappingSign(): void
    {
        [$companyId, $income, $expense] = $this->company();

        $id = $this->facade()->createPLDocument($companyId, PLDocumentSource::MARKETPLACE_OZON, PLDocumentStream::COSTS, '2026-10-01', '2026-10-31', [
            $this->entry($expense, '400.00', true),
            $this->entry($expense, '1000.00', false),
            $this->entry($income, '1800.00', true),
        ]);

        self::assertSame(['1000.00', '-400.00', '-1800.00'], $this->amounts($id));
    }

    /** @return array{string, string, string} */
    private function company(): array
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid4()->toString().'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $income = PLCategoryBuilder::aPLCategory()->withId(Uuid::uuid7()->toString())->forCompany($company)->withName('Доход')->withFlow(PLFlow::INCOME)->build();
        $expense = PLCategoryBuilder::aPLCategory()->withId(Uuid::uuid7()->toString())->forCompany($company)->withName('Расход')->withFlow(PLFlow::EXPENSE)->build();
        foreach ([$owner, $company, $income, $expense] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        return [(string) $company->getId(), (string) $income->getId(), (string) $expense->getId()];
    }

    private function entry(string $categoryId, string $amount, bool $isNegative): PLEntryDTO
    {
        return new PLEntryDTO($categoryId, null, $amount, '2026-10-31', 'строка', $isNegative);
    }

    /** @return list<string> */
    private function amounts(string $documentId): array
    {
        /* @var list<string> */
        return $this->em->getConnection()->fetchFirstColumn(
            'SELECT amount::text FROM document_operations WHERE document_id = ? ORDER BY amount DESC',
            [$documentId],
        );
    }

    private function facade(): FinanceFacade
    {
        return self::getContainer()->get(FinanceFacade::class);
    }
}
