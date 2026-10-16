<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Company\Entity\Company;
use App\Finance\Application\Service\DocumentPlContribution;
use App\Finance\DTO\DocumentListDTO;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\DocumentType;
use App\Finance\Enum\PLFlow;
use App\Finance\Repository\DocumentOperationRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;

/**
 * Вкладка «Операции»: строки — операции, фильтры — по значениям операции, иначе документа;
 * итог — по всей выборке и равен сумме строк.
 */
final class DocumentOperationRepositoryListTest extends IntegrationTestCase
{
    use DocumentListFixturesTrait;

    private DocumentOperationRepository $repository;
    private Company $company;
    private Company $foreignCompany;
    private PLCategory $revenue;
    private PLCategory $rent;
    private PLCategory $neutral;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = self::getContainer()->get(DocumentOperationRepository::class);
        $this->company = $this->persistCompany(1);
        $this->foreignCompany = $this->persistCompany(2);
        $this->revenue = $this->persistCategory($this->company, 'Выручка Шоурум', PLFlow::INCOME);
        $this->rent = $this->persistCategory($this->company, 'Аренда помещения', PLFlow::EXPENSE);
        $this->neutral = $this->persistCategory($this->company, 'Перенос', PLFlow::NONE);
    }

    public function testEveryOperationIsARowInStableOrderAcrossPages(): void
    {
        for ($i = 0; $i < 12; ++$i) {
            // Одна дата у всех: порядок держится на id документа и операции.
            $this->persistDocument(sprintf('D-%02d', $i), operations: [[$this->revenue, '1.00'], [$this->rent, '2.00']]);
        }

        $seen = [];
        foreach ([1, 2] as $page) {
            foreach ($this->rows(page: $page, limit: 20) as $operation) {
                $seen[] = (string) $operation->getId();
            }
        }

        self::assertCount(24, $seen);
        self::assertCount(24, array_unique($seen), 'Операция попала на две страницы.');
        self::assertSame(24, $this->repository->paginateForList($this->companyId(), $this->dto())->getNbResults());
    }

    public function testRowsKeepDocumentOrderNewestFirst(): void
    {
        $this->persistDocument('OLD', operations: [[$this->rent, '1.00']], date: '2026-10-01');
        $this->persistDocument('NEW', operations: [[$this->rent, '1.00']], date: '2026-10-12');

        self::assertSame(['NEW', 'OLD'], array_map(static fn (DocumentOperation $op): ?string => $op->getDocument()?->getNumber(), $this->rows()));
    }

    public function testProjectCenterAndCounterpartyFallBackToDocument(): void
    {
        $showroom = $this->persistProject($this->company, 'Шоурум');
        $online = $this->persistProject($this->company, 'Интернет-магазин');
        $centerId = $this->persistCenter($this->company, 'CFO_SALES');
        $bank = $this->persistCounterparty($this->company, 'АО «Альфа-Банк»');
        $leasing = $this->persistCounterparty($this->company, 'АО «Балтийский лизинг»');
        $this->persistDocument('DOC', counterparty: $bank, project: $showroom, responsibilityCenterId: $centerId, operations: [
            [$this->revenue, '61079.35'],                                   // всё — от документа
            [$this->revenue, '30000.00', $online, null, $leasing, 'опт'],   // проект и контрагент — свои
        ]);

        self::assertSame(['61079.35'], $this->amounts(projectDirectionId: (string) $showroom->getId()));
        self::assertSame(['30000.00'], $this->amounts(projectDirectionId: (string) $online->getId()));
        self::assertSame(['30000.00', '61079.35'], $this->amounts(responsibilityCenterId: $centerId));
        self::assertSame(['61079.35'], $this->amounts(counterparty: 'альфа'));
        self::assertSame(['30000.00'], $this->amounts(q: 'балтийский'));
        self::assertSame(['30000.00'], $this->amounts(q: 'опт'));
        self::assertSame(['30000.00', '61079.35'], $this->amounts(q: 'шоурум'), 'Поиск по статье.');
    }

    public function testDirectionFollowsNatureIncludingNoneFlowBySign(): void
    {
        $this->persistDocument('MIXED', operations: [
            [$this->revenue, '10.00'],
            [$this->rent, '3.00'],
            [$this->neutral, '7.00'],
            [$this->neutral, '-2.00'],
            [$this->neutral, '0.00'],
            [null, '5.00'],
        ]);

        self::assertSame(['7.00', '10.00'], $this->amounts(direction: 'income'));
        self::assertSame(['-2.00', '3.00'], $this->amounts(direction: 'expense'));
        self::assertCount(6, $this->rows(), 'Без фильтра видны все операции, и без статьи тоже.');
    }

    public function testCategoryAndAmountFilters(): void
    {
        $this->persistDocument('DOC', operations: [[$this->rent, '99.99'], [$this->rent, '-150.00'], [$this->revenue, '150.00']]);

        self::assertSame(['-150.00', '99.99'], $this->amounts(categoryId: (string) $this->rent->getId()));
        self::assertSame(['-150.00', '150.00'], $this->amounts(amountFrom: '100.00'));
        self::assertSame(['99.99'], $this->amounts(amountTo: '99.99'));
    }

    public function testTotalsCoverWholeSelectionAndCountOnlyFoundOperations(): void
    {
        for ($i = 0; $i < 15; ++$i) {
            $this->persistDocument(sprintf('D-%02d', $i), operations: [[$this->revenue, '100.10'], [$this->rent, '40.05'], [null, '9.00']]);
        }

        $totals = $this->repository->summarizeForList($this->companyId(), $this->dto());
        self::assertSame(45, $totals->operations);
        self::assertSame(15, $totals->documents);
        self::assertSame('1501.50', $totals->income);
        self::assertSame('600.75', $totals->expense);
        self::assertSame('900.75', $totals->result);

        $expense = $this->repository->summarizeForList($this->companyId(), $this->dto(direction: 'expense'));
        self::assertSame(15, $expense->operations);
        self::assertSame('0.00', $expense->income);
        self::assertSame('600.75', $expense->expense);
    }

    /**
     * marketplace_pl: списание −100 и сторно +20 одной статьи — расход 80; строки
     * со знаком (100 и −20) складываются в тот же итог.
     */
    public function testMarketplaceRowsCarrySignAndSumToTotals(): void
    {
        $this->persistDocument('MP', type: DocumentType::MARKETPLACE_PL, operations: [
            [$this->rent, '-100.00'],
            [$this->rent, '20.00'],
            [$this->revenue, '500.00'],
        ]);
        $this->persistDocument('PLAIN', operations: [[$this->rent, '-30.00']]);

        $dto = $this->dto();
        $totals = $this->repository->summarizeForList($this->companyId(), $dto);
        self::assertSame('500.00', $totals->income);
        self::assertSame('110.00', $totals->expense);

        $rows = $this->rows();
        $documentIds = array_values(array_unique(array_map(static fn (DocumentOperation $op): string => (string) $op->getDocument()?->getId(), $rows)));
        $sums = $this->repository->marketplaceBucketSums($this->companyId(), $documentIds);
        $contribution = self::getContainer()->get(DocumentPlContribution::class);

        $byNature = ['INCOME' => '0.00', 'EXPENSE' => '0.00'];
        $amounts = [];
        foreach ($rows as $operation) {
            $row = $contribution->forOperation($operation, $sums);
            self::assertNotNull($row);
            $byNature[$row['nature']] = bcadd($byNature[$row['nature']], $row['amount'], 2);
            $amounts[] = $row['amount'];
        }
        sort($amounts);

        self::assertSame(['-20.00', '30.00', '100.00', '500.00'], $amounts);
        self::assertSame($totals->income, $byNature['INCOME']);
        self::assertSame($totals->expense, $byNature['EXPENSE']);
    }

    /**
     * Фильтр оставил одно сторно (+200) статьи, где итог — списание (−800): сторно уменьшает
     * расход, как в регистре и на странице документа, а не становится расходом.
     */
    public function testFilteredStornoKeepsSignOfWholeCategory(): void
    {
        $this->persistDocument('MP', type: DocumentType::MARKETPLACE_PL, operations: [
            [$this->rent, '-1000.00'],
            [$this->rent, '200.00'],
        ]);

        $dto = $this->dto(amountTo: '500');
        $totals = $this->repository->summarizeForList($this->companyId(), $dto);
        self::assertSame(1, $totals->operations);
        self::assertSame('-200.00', $totals->expense);

        $rows = $this->rows(amountTo: '500');
        self::assertCount(1, $rows);
        $sums = $this->repository->marketplaceBucketSums($this->companyId(), [(string) $rows[0]->getDocument()?->getId()]);
        $row = self::getContainer()->get(DocumentPlContribution::class)->forOperation($rows[0], $sums);
        self::assertSame(['nature' => 'EXPENSE', 'amount' => '-200.00', 'currency' => 'RUB'], $row);
    }

    public function testCurrenciesAreNotSummedTogether(): void
    {
        $this->persistDocument('RUB', operations: [[$this->revenue, '100.00']]);
        $this->persistDocument('USD', currency: 'USD', operations: [[$this->revenue, '40.00']]);

        $totals = $this->repository->summarizeForList($this->companyId(), $this->dto());

        self::assertSame('100.00', $totals->income);
        self::assertSame('USD', $totals->others()[0]->currency);
        self::assertSame('40.00', $totals->others()[0]->income);
    }

    public function testCountByDocumentsCountsAllOperationsRegardlessOfFilter(): void
    {
        $document = $this->persistDocument('DOC', operations: [[$this->revenue, '1.00'], [$this->rent, '2.00'], [$this->rent, '3.00']]);
        $foreign = $this->persistDocument('FOREIGN', company: $this->foreignCompany, operations: [[null, '1.00']]);

        self::assertCount(1, $this->rows(direction: 'income'));
        self::assertSame(
            [(string) $document->getId() => 3],
            $this->repository->countByDocuments($this->companyId(), [(string) $document->getId(), (string) $foreign->getId()]),
        );
        self::assertSame([], $this->repository->countByDocuments($this->companyId(), []));
    }

    public function testRowsAndTotalsStayInsideCompanyAndSkipDeletedDocuments(): void
    {
        $centerId = $this->persistCenter($this->foreignCompany, 'CFO_FOREIGN');
        $foreignProject = $this->persistProject($this->foreignCompany, 'Чужой проект');
        $foreignCategory = $this->persistCategory($this->foreignCompany, 'Чужая выручка', PLFlow::INCOME);
        $foreign = $this->persistDocument(
            'FOREIGN',
            description: 'Аренда',
            company: $this->foreignCompany,
            project: $foreignProject,
            responsibilityCenterId: $centerId,
            type: DocumentType::MARKETPLACE_PL,
            operations: [[$foreignCategory, '999.00']],
        );
        $deleted = $this->persistDocument('DELETED', operations: [[$this->revenue, '50.00']]);
        $deleted->markDeleted(null, 'test');
        $this->em->flush();

        $filters = [
            [],
            ['q' => 'аренда'],
            ['projectDirectionId' => (string) $foreignProject->getId()],
            ['responsibilityCenterId' => $centerId],
            ['direction' => 'income'],
            ['categoryId' => (string) $foreignCategory->getId()],
            ['amountFrom' => '50.00'],
        ];

        foreach ($filters as $filter) {
            $label = json_encode($filter, \JSON_THROW_ON_ERROR);
            self::assertSame([], $this->rows(...$filter), $label);
            $totals = $this->repository->summarizeForList($this->companyId(), $this->dto(...$filter));
            self::assertSame(0, $totals->operations, $label);
            self::assertSame('0.00', $totals->income, $label);
            self::assertSame([], $totals->others(), $label);
        }

        self::assertSame([], $this->repository->marketplaceBucketSums($this->companyId(), [(string) $foreign->getId()]));
    }

    /**
     * @return list<DocumentOperation>
     */
    private function rows(
        int $page = 1,
        int $limit = 50,
        ?string $q = null,
        ?string $projectDirectionId = null,
        ?string $responsibilityCenterId = null,
        ?string $direction = null,
        ?string $categoryId = null,
        ?string $amountFrom = null,
        ?string $amountTo = null,
        ?string $counterparty = null,
    ): array {
        $this->em->clear();
        $dto = $this->dto($page, $limit, $q, $projectDirectionId, $responsibilityCenterId, $direction, $categoryId, $amountFrom, $amountTo, $counterparty);

        return array_values(iterator_to_array($this->repository->paginateForList($this->companyId(), $dto)->getCurrentPageResults()));
    }

    /**
     * @return list<string>
     */
    private function amounts(
        ?string $q = null,
        ?string $projectDirectionId = null,
        ?string $responsibilityCenterId = null,
        ?string $direction = null,
        ?string $categoryId = null,
        ?string $amountFrom = null,
        ?string $amountTo = null,
        ?string $counterparty = null,
    ): array {
        $amounts = array_map(
            static fn (DocumentOperation $operation): string => $operation->getAmount(),
            $this->rows(1, 50, $q, $projectDirectionId, $responsibilityCenterId, $direction, $categoryId, $amountFrom, $amountTo, $counterparty),
        );
        sort($amounts);

        return $amounts;
    }

    private function dto(
        int $page = 1,
        int $limit = 50,
        ?string $q = null,
        ?string $projectDirectionId = null,
        ?string $responsibilityCenterId = null,
        ?string $direction = null,
        ?string $categoryId = null,
        ?string $amountFrom = null,
        ?string $amountTo = null,
        ?string $counterparty = null,
    ): DocumentListDTO {
        $company = $this->em->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);

        return new DocumentListDTO(
            $company,
            $page,
            $limit,
            counterparty: $counterparty,
            q: $q,
            projectDirectionId: $projectDirectionId,
            responsibilityCenterId: $responsibilityCenterId,
            direction: $direction,
            categoryId: $categoryId,
            amountFrom: $amountFrom,
            amountTo: $amountTo,
        );
    }

    private function companyId(): string
    {
        return (string) $this->company->getId();
    }
}
