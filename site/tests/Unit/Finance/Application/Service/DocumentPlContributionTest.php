<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Application\Service;

use App\Finance\Application\Service\DocumentPlContribution;
use App\Finance\Application\Service\PlNatureResolver;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\DocumentType;
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class DocumentPlContributionTest extends TestCase
{
    public function testPlainDocumentTakesModulusOfEachOperationByCategoryFlow(): void
    {
        $document = $this->document(DocumentType::OTHER, [
            ['INCOME', '91079.35'],
            ['EXPENSE', '1820.00'],
            [null, '500.00'],
        ]);

        $result = $this->service()->forDocument($document);

        self::assertSame('91079.35', $result['income']);
        self::assertSame('1820.00', $result['expense']);
        self::assertSame('89259.35', $result['result']);
        self::assertSame('RUB', $result['currency']);
        self::assertCount(2, $result['operations'], 'Операция без статьи в итог не входит.');
    }

    public function testNoneFlowCategoryFollowsAmountSign(): void
    {
        $result = $this->service()->forDocument($this->document(DocumentType::OTHER, [['NONE', '700.00'], ['NONE', '-30.00'], ['NONE', '0.00']]));

        self::assertSame('700.00', $result['income']);
        self::assertSame('30.00', $result['expense']);
        self::assertCount(2, $result['operations'], 'Нулевая операция без flow не имеет природы.');
    }

    public function testMarketplaceNetsChargeAndStornoWithinCategory(): void
    {
        $document = $this->document(DocumentType::MARKETPLACE_PL, [
            ['EXPENSE', '-100.00'],
            ['EXPENSE', '20.00'],
            ['INCOME', '500.00'],
        ]);

        $result = $this->service()->forDocument($document);

        self::assertSame('500.00', $result['income']);
        self::assertSame('80.00', $result['expense']);
        // Строки операций складываются в итог: списание 100, сторно −20.
        $expense = array_values(array_filter($result['operations'], static fn (array $op): bool => 'EXPENSE' === $op['nature']));
        self::assertSame(['100.00', '-20.00'], array_column($expense, 'amount'));
    }

    public function testMarketplaceReturnsWithPositiveSignStayPositive(): void
    {
        // REV_*_RETURNS приходят всегда «+X»: модуль итога статьи, вклад строки — как есть.
        $result = $this->service()->forDocument($this->document(DocumentType::MARKETPLACE_PL, [['EXPENSE', '40.00']]));

        self::assertSame('40.00', $result['expense']);
        self::assertSame('40.00', array_values($result['operations'])[0]['amount']);
    }

    public function testListedOperationOfPlainDocumentTakesModulus(): void
    {
        $operations = $this->document(DocumentType::OTHER, [['EXPENSE', '-30.00'], ['NONE', '-5.00'], [null, '9.00'], ['NONE', '0.00']])->getOperations()->toArray();
        $service = $this->service();

        self::assertSame(['nature' => 'EXPENSE', 'amount' => '30.00', 'currency' => 'RUB'], $service->forOperation($operations[0], []));
        self::assertSame(['nature' => 'EXPENSE', 'amount' => '5.00', 'currency' => 'RUB'], $service->forOperation($operations[1], []));
        self::assertNull($service->forOperation($operations[2], []), 'Без статьи — не в итоге.');
        self::assertNull($service->forOperation($operations[3], []), 'Нулевая операция без flow не имеет природы.');
    }

    public function testListedMarketplaceOperationTakesSignOfItsBucketSum(): void
    {
        $document = $this->document(DocumentType::MARKETPLACE_PL, [['EXPENSE', '-100.00'], ['EXPENSE', '20.00']]);
        [$charge, $storno] = $document->getOperations()->toArray();
        $key = sprintf('%s|%s|EXPENSE', $document->getId(), $charge->getPlCategory()?->getId());
        $service = $this->service();

        // Сумма статьи −80: списание входит как 100, сторно — как −20.
        self::assertSame('100.00', $service->forOperation($charge, [$key => '-80.00'])['amount'] ?? null);
        self::assertSame('-20.00', $service->forOperation($storno, [$key => '-80.00'])['amount'] ?? null);
        // Сумма статьи положительна (возвраты «+X»): как есть.
        self::assertSame('20.00', $service->forOperation($storno, [$key => '20.00'])['amount'] ?? null);
        // Суммы нет (документ вне выборки сумм) — по знаку самой операции.
        self::assertSame('100.00', $service->forOperation($charge, [])['amount'] ?? null);
    }

    /**
     * @param list<array{0: 'INCOME'|'EXPENSE'|'NONE'|null, 1: string}> $operations
     */
    private function document(DocumentType $type, array $operations): Document
    {
        $company = CompanyBuilder::aCompany()->build();
        $document = new Document(Uuid::uuid4()->toString(), $company);
        $document->setType($type);
        /** @var array<string, PLCategory> $categories */
        $categories = [];
        foreach ($operations as [$flow, $amount]) {
            $operation = new DocumentOperation();
            if (null !== $flow) {
                $categories[$flow] ??= PLCategoryBuilder::aPLCategory()
                    ->withId(Uuid::uuid4()->toString())
                    ->forCompany($company)
                    ->withName($flow)
                    ->withFlow(PLFlow::from($flow))
                    ->build();
                $operation->setCategory($categories[$flow]);
            }
            $operation->setAmount($amount);
            $document->addOperation($operation);
        }

        return $document;
    }

    private function service(): DocumentPlContribution
    {
        return new DocumentPlContribution(new PlNatureResolver());
    }
}
