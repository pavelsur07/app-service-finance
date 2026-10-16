<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\ProjectDirection;
use App\Finance\DTO\DocumentListDTO;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLFlow;
use App\Finance\Repository\DocumentRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Фильтры и итог списка «Документы ОПиУ». Условия на операции не должны урезать
 * коллекцию операций документа: строка списка и итог считают по всем его операциям.
 */
final class DocumentRepositoryListTest extends IntegrationTestCase
{
    private DocumentRepository $repository;
    private Company $company;
    private Company $foreignCompany;
    private PLCategory $revenue;
    private PLCategory $rent;
    private PLCategory $neutral;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = self::getContainer()->get(DocumentRepository::class);
        $this->company = $this->persistCompany(1);
        $this->foreignCompany = $this->persistCompany(2);
        $this->revenue = $this->persistCategory($this->company, 'Выручка Шоурум', PLFlow::INCOME);
        $this->rent = $this->persistCategory($this->company, 'Аренда помещения', PLFlow::EXPENSE);
        $this->neutral = $this->persistCategory($this->company, 'Перенос', PLFlow::NONE);
    }

    public function testSearchFindsDescriptionNumberCounterpartyAndOperationCategory(): void
    {
        $leasing = $this->persistCounterparty($this->company, 'АО «Балтийский лизинг»');
        $this->persistDocument('BY-DESCRIPTION', description: 'Лизинговый платёж за октябрь');
        $this->persistDocument('NR-1204-Л');
        $this->persistDocument('BY-COUNTERPARTY', counterparty: $leasing);
        $this->persistDocument('BY-CATEGORY', operations: [[$this->rent, '100.00']]);
        $this->persistDocument('UNRELATED', description: 'Прочее', operations: [[$this->revenue, '5.00']]);

        self::assertSame(['BY-DESCRIPTION'], $this->numbers(q: 'лизинговый'));
        self::assertSame(['NR-1204-Л'], $this->numbers(q: '1204'));
        self::assertSame(['BY-COUNTERPARTY'], $this->numbers(q: 'балтийский'));
        self::assertSame(['BY-CATEGORY'], $this->numbers(q: 'аренда'));
    }

    public function testSearchTreatsLikeWildcardsAsLiterals(): void
    {
        $this->persistDocument('PCT', description: 'Скидка 50% на услуги');
        $this->persistDocument('PLAIN', description: 'Скидка 500 на услуги');
        $this->persistDocument('UNDERSCORE', description: 'code_x');
        $this->persistDocument('NO-UNDERSCORE', description: 'codeAx');

        self::assertSame(['PCT'], $this->numbers(q: '50%'));
        self::assertSame(['UNDERSCORE'], $this->numbers(q: 'code_'));
        // Буквальный «%» находит только документ, где он есть, а не весь список.
        self::assertSame(['PCT'], $this->numbers(q: '%'));
    }

    public function testProjectFilterMatchesDocumentOrAnyOperationAndKeepsAllOperations(): void
    {
        $showroom = $this->persistProject($this->company, 'Шоурум');
        $online = $this->persistProject($this->company, 'Интернет-магазин');
        $this->persistDocument('DOC-LEVEL', project: $showroom);
        $mixed = $this->persistDocument('OP-LEVEL', operations: [
            [$this->revenue, '61079.35', $showroom],
            [$this->revenue, '30000.00', $online],
        ]);
        $this->persistDocument('OTHER', project: $online);

        $result = $this->list(projectDirectionId: (string) $showroom->getId());

        self::assertSame(['DOC-LEVEL', 'OP-LEVEL'], self::sortedNumbers($result));
        $found = array_values(array_filter($result, static fn (Document $d): bool => $d->getId() === $mixed->getId()));
        self::assertCount(2, $found[0]->getOperations(), 'Фильтр по проекту урезал операции документа.');
    }

    public function testResponsibilityCenterFilterMatchesDocumentOrAnyOperation(): void
    {
        $centerId = $this->persistCenter($this->company, 'CFO_SALES');
        $this->persistDocument('DOC-CFO', responsibilityCenterId: $centerId);
        $this->persistDocument('OP-CFO', operations: [[$this->rent, '10.00', null, $centerId]]);
        $this->persistDocument('NO-CFO');

        self::assertSame(['DOC-CFO', 'OP-CFO'], $this->numbers(responsibilityCenterId: strtoupper($centerId)));
    }

    public function testDirectionFilterUsesCategoryFlowAndKeepsMixedDocuments(): void
    {
        $this->persistDocument('INCOME-ONLY', operations: [[$this->revenue, '10.00']]);
        $this->persistDocument('EXPENSE-ONLY', operations: [[$this->rent, '10.00']]);
        $this->persistDocument('MIXED', operations: [[$this->revenue, '10.00'], [$this->rent, '3.00']]);
        $this->persistDocument('UNCATEGORIZED', operations: [[null, '10.00'], [$this->neutral, '10.00']]);

        self::assertSame(['INCOME-ONLY', 'MIXED'], $this->numbers(direction: 'income'));
        self::assertSame(['EXPENSE-ONLY', 'MIXED'], $this->numbers(direction: 'expense'));
        self::assertSame(['EXPENSE-ONLY', 'INCOME-ONLY', 'MIXED', 'UNCATEGORIZED'], $this->numbers());
    }

    public function testTotalsCoverTheWholeSelectionNotThePage(): void
    {
        for ($i = 0; $i < 25; ++$i) {
            $this->persistDocument(sprintf('D-%02d', $i), operations: [[$this->revenue, '100.10'], [$this->rent, '40.05']]);
        }

        $dto = $this->dto(limit: 20);
        self::assertCount(20, iterator_to_array($this->repository->findByCompany($dto)->getCurrentPageResults()));

        $totals = $this->repository->summarizeByCompany((string) $this->company->getId(), $dto);
        self::assertSame(25, $totals->documents);
        self::assertSame(50, $totals->operations);
        self::assertSame('2502.50', $totals->income);
        self::assertSame('1001.25', $totals->expense);
        self::assertSame('1501.25', $totals->result);
    }

    public function testTotalsSkipOperationsWithoutIncomeOrExpenseCategory(): void
    {
        $this->persistDocument('MIXED', operations: [
            [$this->revenue, '91079.35'],
            [$this->rent, '1820.00'],
            [null, '500.00'],
            [$this->neutral, '700.00'],
        ]);
        $this->persistDocument('EMPTY');

        $totals = $this->repository->summarizeByCompany((string) $this->company->getId(), $this->dto());

        self::assertSame(2, $totals->documents);
        self::assertSame(4, $totals->operations);
        self::assertSame('91079.35', $totals->income);
        self::assertSame('1820.00', $totals->expense);
        self::assertSame('89259.35', $totals->result);
    }

    public function testNegativeResult(): void
    {
        $this->persistDocument('LOSS', operations: [[$this->revenue, '2600.00'], [$this->rent, '4200.00']]);

        self::assertSame('-1600.00', $this->repository->summarizeByCompany((string) $this->company->getId(), $this->dto())->result);
    }

    public function testTotalsFollowFiltersButCountEveryOperationOfFoundDocuments(): void
    {
        $this->persistDocument('MIXED', operations: [[$this->revenue, '10.00'], [$this->rent, '3.00']]);
        $this->persistDocument('EXPENSE-ONLY', operations: [[$this->rent, '7.00']]);

        $totals = $this->repository->summarizeByCompany((string) $this->company->getId(), $this->dto(direction: 'income'));

        self::assertSame(1, $totals->documents);
        self::assertSame(2, $totals->operations);
        self::assertSame('10.00', $totals->income);
        self::assertSame('3.00', $totals->expense);
    }

    public function testListAndTotalsStayInsideCompanyForEveryFilter(): void
    {
        $centerId = $this->persistCenter($this->foreignCompany, 'CFO_FOREIGN');
        $foreignProject = $this->persistProject($this->foreignCompany, 'Чужой проект');
        $foreignCategory = $this->persistCategory($this->foreignCompany, 'Чужая выручка', PLFlow::INCOME);
        $this->persistDocument(
            'FOREIGN',
            description: 'Аренда',
            company: $this->foreignCompany,
            project: $foreignProject,
            responsibilityCenterId: $centerId,
            operations: [[$foreignCategory, '999.00']],
        );

        $filters = [
            [],
            ['q' => 'аренда'],
            ['projectDirectionId' => (string) $foreignProject->getId()],
            ['responsibilityCenterId' => $centerId],
            ['direction' => 'income'],
        ];

        foreach ($filters as $filter) {
            self::assertSame([], $this->numbers(...$filter), json_encode($filter, \JSON_THROW_ON_ERROR));
            $totals = $this->repository->summarizeByCompany((string) $this->company->getId(), $this->dto(...$filter));
            self::assertSame(0, $totals->documents);
            self::assertSame('0.00', $totals->income);
            self::assertSame('0.00', $totals->result);
        }
    }

    public function testDeletedDocumentsAreExcludedFromListAndTotals(): void
    {
        $deleted = $this->persistDocument('DELETED', operations: [[$this->revenue, '50.00']]);
        $deleted->markDeleted(null, 'test');
        $this->em->flush();

        self::assertSame([], $this->numbers());
        self::assertSame(0, $this->repository->summarizeByCompany((string) $this->company->getId(), $this->dto())->documents);
    }

    /**
     * @return list<string>
     */
    private function numbers(
        ?string $q = null,
        ?string $projectDirectionId = null,
        ?string $responsibilityCenterId = null,
        ?string $direction = null,
    ): array {
        return self::sortedNumbers($this->list($q, $projectDirectionId, $responsibilityCenterId, $direction));
    }

    /**
     * @return list<Document>
     */
    private function list(
        ?string $q = null,
        ?string $projectDirectionId = null,
        ?string $responsibilityCenterId = null,
        ?string $direction = null,
    ): array {
        $this->em->clear();
        $dto = $this->dto(50, $q, $projectDirectionId, $responsibilityCenterId, $direction);

        return array_values(iterator_to_array($this->repository->findByCompany($dto)->getCurrentPageResults()));
    }

    /**
     * @param list<Document> $documents
     *
     * @return list<string>
     */
    private static function sortedNumbers(array $documents): array
    {
        $numbers = array_map(static fn (Document $document): string => (string) $document->getNumber(), $documents);
        sort($numbers);

        return $numbers;
    }

    private function dto(
        int $limit = 50,
        ?string $q = null,
        ?string $projectDirectionId = null,
        ?string $responsibilityCenterId = null,
        ?string $direction = null,
    ): DocumentListDTO {
        $company = $this->em->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);

        return new DocumentListDTO(
            $company,
            1,
            $limit,
            q: $q,
            projectDirectionId: $projectDirectionId,
            responsibilityCenterId: $responsibilityCenterId,
            direction: $direction,
        );
    }

    /**
     * @param list<array{0: PLCategory|null, 1: string, 2?: ProjectDirection|null, 3?: string|null}> $operations
     */
    private function persistDocument(
        string $number,
        ?string $description = null,
        ?Company $company = null,
        ?Counterparty $counterparty = null,
        ?ProjectDirection $project = null,
        ?string $responsibilityCenterId = null,
        array $operations = [],
    ): Document {
        $document = new Document(Uuid::uuid4()->toString(), $company ?? $this->company);
        $document->setNumber($number);
        $document->setDescription($description);
        $document->setDate(new \DateTimeImmutable('2026-10-08'));
        $document->setCounterparty($counterparty);
        $document->setProjectDirection($project);
        $document->setResponsibilityCenterId($responsibilityCenterId);

        foreach ($operations as $row) {
            $operation = new DocumentOperation();
            $operation->setCategory($row[0]);
            $operation->setAmount($row[1]);
            $operation->setProjectDirection($row[2] ?? null);
            $operation->setResponsibilityCenterId($row[3] ?? null);
            $document->addOperation($operation);
            $this->em->persist($operation);
        }

        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    private function persistCompany(int $index): Company
    {
        $owner = UserBuilder::aUser()->withIndex(9100 + $index)->build();
        $company = CompanyBuilder::aCompany()->withIndex(9100 + $index)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    private function persistCategory(Company $company, string $name, PLFlow $flow): PLCategory
    {
        $category = PLCategoryBuilder::aPLCategory()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName($name)
            ->withFlow($flow)
            ->build();
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    private function persistCenter(Company $company, string $code): string
    {
        $center = new FinancialResponsibilityCenter((string) $company->getId(), $code, $code);
        $this->em->persist($center);
        $this->em->flush();

        return $center->getId();
    }

    private function persistProject(Company $company, string $name): ProjectDirection
    {
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, $name);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    private function persistCounterparty(Company $company, string $name): Counterparty
    {
        $counterparty = CounterpartyBuilder::aCounterparty()
            ->withId(Uuid::uuid4()->toString())
            ->withCompany($company)
            ->withName($name)
            ->build();
        $this->em->persist($counterparty);
        $this->em->flush();

        return $counterparty;
    }
}
