<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Application;

use App\Finance\Application\DeletePLCategoryAction;
use App\Finance\Exception\PLCategoryInUseException;
use App\Finance\Infrastructure\Query\PLCategoryUsageQuery;
use App\Finance\Repository\PLDailyTotalRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class DeletePLCategoryActionTest extends TestCase
{
    public function testRejectsDeletionWithReasonWhenCategoryIsInUse(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $category = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('In use')->build();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('wrapInTransaction');
        $em->expects(self::never())->method('remove');

        $dailyTotalRepository = $this->createMock(PLDailyTotalRepository::class);
        $dailyTotalRepository->expects(self::never())->method('moveCategoryRowsToUncategorized');

        $action = new DeletePLCategoryAction($em, $dailyTotalRepository, $this->usageQuery([[
            'category_id' => (string) $category->getId(),
            'children' => 2, 'doc_ops' => 3, 'deleted_doc_ops' => 0, 'cashflow' => 0, 'loans' => 0, 'sale_mappings' => 0,
        ]]));

        $this->expectException(PLCategoryInUseException::class);
        $this->expectExceptionMessage('Статью нельзя удалить: 2 вложенные статьи, 3 операции документов ОПиУ. Перенесите или удалите вложенные статьи и переназначьте ссылки на другую статью, затем удалите эту.');

        $action($category, (string) $company->getId());
    }

    /**
     * Ссылка появилась между проверкой и удалением — гонка. FK-отказ любой таблицы обязан
     * стать тем же PLCategoryInUseException, а не долететь до контроллера как 500.
     */
    public function testTranslatesForeignKeyViolationFromFlushIntoInUseException(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $category = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Race')->build();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willThrowException($this->fkException(
            'SQLSTATE[23503]: Foreign key violation: 7 ERROR:  update or delete on table "pl_categories" '
            .'violates foreign key constraint "fk_aae71c73727aca70" on table "pl_categories"',
        ));

        $action = new DeletePLCategoryAction($em, $this->createMock(PLDailyTotalRepository::class), $this->usageQuery([]));

        $this->expectException(PLCategoryInUseException::class);
        $this->expectExceptionMessage('на неё ссылаются другие записи');

        $action($category, (string) $company->getId());
    }

    public function testRemovesCategoryAndMergesDailyTotalsWhenNotInUse(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $category = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Unused')->build();
        $companyId = (string) $company->getId();
        $categoryId = (string) $category->getId();

        $dailyTotalRepository = $this->createMock(PLDailyTotalRepository::class);
        $dailyTotalRepository->expects(self::once())
            ->method('moveCategoryRowsToUncategorized')
            ->with($companyId, $categoryId);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $fn) => $fn());
        $em->expects(self::once())->method('remove')->with($category);

        $action = new DeletePLCategoryAction($em, $dailyTotalRepository, $this->usageQuery([]));
        $action($category, $companyId);
    }

    /**
     * @param list<array<string, int|string>> $rows
     */
    private function usageQuery(array $rows): PLCategoryUsageQuery
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($rows);

        return new PLCategoryUsageQuery($connection);
    }

    private function fkException(string $message): ForeignKeyConstraintViolationException
    {
        $driverException = new class($message) extends \Exception implements \Doctrine\DBAL\Driver\Exception {
            public function getSQLState(): string
            {
                return '23503';
            }
        };

        return new ForeignKeyConstraintViolationException($driverException, null);
    }
}
