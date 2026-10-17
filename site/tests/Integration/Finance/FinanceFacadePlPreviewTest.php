<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Company\Entity\Company;
use App\Finance\Application\DTO\PlPreviewColumn;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLFlow;
use App\Finance\Enum\PlPreviewUnplacedReason;
use App\Finance\Facade\FinanceFacade;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class FinanceFacadePlPreviewTest extends IntegrationTestCase
{
    public function testCalculatesCompanyTreeWithoutWritingAndRejectsForeignCategory(): void
    {
        $company = $this->company('11111111-1111-1111-1111-000000000311', 'pl-preview-own@example.test');
        $foreign = $this->company('11111111-1111-1111-1111-000000000312', 'pl-preview-foreign@example.test');

        $revenueTotal = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Выручка')
            ->withCode('REV_TOTAL')->withType(PLCategoryType::SUBTOTAL)->withFlow(PLFlow::INCOME)->build();
        $revenue = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Выручка WB')
            ->withCode('REV_WB')->withParent($revenueTotal)->withFlow(PLFlow::INCOME)->build();
        $commission = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Комиссия')
            ->withCode('COMMISSION')->withFlow(PLFlow::EXPENSE)->build();
        $foreignLeaf = PLCategoryBuilder::aPLCategory()->forCompany($foreign)->withName('Чужая статья')
            ->withCode('REV_WB')->withFlow(PLFlow::INCOME)->build();

        foreach ([$revenueTotal, $revenue, $commission, $foreignLeaf] as $category) {
            $this->em->persist($category);
        }
        $this->em->flush();
        $this->em->clear();

        $result = self::getContainer()->get(FinanceFacade::class)->calculatePlPreview(
            (string) $company->getId(),
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            [
                new PlPreviewColumn('total', [
                    (string) $revenue->getId() => '1500.00',
                    (string) $commission->getId() => '-200.00',
                    (string) $foreignLeaf->getId() => '999.00',
                ]),
            ],
        );

        $values = [];
        foreach ($result->rows as $row) {
            $values[$row->code] = $row->values['total'];
        }
        self::assertSame(['REV_TOTAL' => 1500.0, 'REV_WB' => 1500.0, 'COMMISSION' => -200.0], $values);

        self::assertCount(1, $result->unplaced);
        self::assertSame((string) $foreignLeaf->getId(), $result->unplaced[0]->categoryId);
        self::assertSame(PlPreviewUnplacedReason::CATEGORY_NOT_FOUND, $result->unplaced[0]->reason);
        self::assertNull($result->unplaced[0]->categoryName, 'Название чужой статьи не раскрывается.');

        $connection = $this->em->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM documents'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM pl_daily_totals'));
    }

    private function company(string $id, string $email): Company
    {
        $owner = UserBuilder::aUser()->withId(str_replace('11111111', '22222222', $id))->withEmail($email)->build();
        $company = CompanyBuilder::aCompany()->withId($id)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }
}
