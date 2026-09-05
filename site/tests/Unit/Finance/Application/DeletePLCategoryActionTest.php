<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Application;

use App\Finance\Application\DeletePLCategoryAction;
use App\Finance\Exception\PLCategoryInUseException;
use App\Finance\Repository\DocumentOperationRepository;
use App\Finance\Repository\PLDailyTotalRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class DeletePLCategoryActionTest extends TestCase
{
    public function testRejectsDeletionWhenDocumentOperationsReferenceCategory(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $category = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('In use')->build();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('wrapInTransaction');
        $em->expects(self::never())->method('remove');

        $dailyTotalRepository = $this->createMock(PLDailyTotalRepository::class);
        $dailyTotalRepository->expects(self::never())->method('moveCategoryRowsToUncategorized');

        $documentOperationRepository = $this->createMock(DocumentOperationRepository::class);
        $documentOperationRepository->method('countByCategory')->willReturn(3);

        $action = new DeletePLCategoryAction($em, $dailyTotalRepository, $documentOperationRepository);

        $this->expectException(PLCategoryInUseException::class);

        $action($category, (string) $company->getId());
    }

    /**
     * Операция появилась между проверкой countByCategory и flush — гонка, а не обычный
     * путь. FK-исключение обязано транслироваться в тот же PLCategoryInUseException,
     * а не долетать до контроллера как 500.
     */
    public function testTranslatesForeignKeyViolationFromFlushIntoDomainException(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $category = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Race')->build();

        $fkException = $this->fkExceptionWithMessage(
            'An exception occurred while executing a query: SQLSTATE[23503]: Foreign key violation: 7 ERROR:  '
            .'update or delete on table "pl_categories" violates foreign key constraint "fk_doc_oper_category" '
            .'on table "document_operations"',
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willThrowException($fkException);

        $dailyTotalRepository = $this->createMock(PLDailyTotalRepository::class);
        $documentOperationRepository = $this->createMock(DocumentOperationRepository::class);
        $documentOperationRepository->method('countByCategory')->willReturn(0);

        $action = new DeletePLCategoryAction($em, $dailyTotalRepository, $documentOperationRepository);

        $this->expectException(PLCategoryInUseException::class);

        $action($category, (string) $company->getId());
    }

    /**
     * Находка внешнего ревью: на pl_categories ссылаются и другие таблицы без ON DELETE
     * (cashflow_categories, wildberries_report_detail_mappings, finance_loan). FK-нарушение
     * от них — не "привязаны операции документов", а отдельный дефект. Такое исключение
     * обязано пробрасываться как есть, а не маскироваться под PLCategoryInUseException.
     */
    public function testDoesNotTranslateForeignKeyViolationFromAnUnrelatedConstraint(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $category = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Unrelated FK')->build();

        $fkException = $this->fkExceptionWithMessage(
            'An exception occurred while executing a query: SQLSTATE[23503]: Foreign key violation: 7 ERROR:  '
            .'update or delete on table "pl_categories" violates foreign key constraint "fk_cashflow_category_pl_category" '
            .'on table "cashflow_categories"',
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willThrowException($fkException);

        $dailyTotalRepository = $this->createMock(PLDailyTotalRepository::class);
        $documentOperationRepository = $this->createMock(DocumentOperationRepository::class);
        $documentOperationRepository->method('countByCategory')->willReturn(0);

        $action = new DeletePLCategoryAction($em, $dailyTotalRepository, $documentOperationRepository);

        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->expectExceptionObject($fkException);

        $action($category, (string) $company->getId());
    }

    private function fkExceptionWithMessage(string $message): ForeignKeyConstraintViolationException
    {
        $driverException = new class($message) extends \Exception implements \Doctrine\DBAL\Driver\Exception {
            public function __construct(private readonly string $driverMessage)
            {
                parent::__construct($driverMessage);
            }

            public function getSQLState(): string
            {
                return '23503';
            }
        };

        return new ForeignKeyConstraintViolationException($driverException, null);
    }

    public function testRemovesCategoryAndMergesDailyTotalsWhenNotInUse(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $category = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Unused')->build();
        $companyId = (string) $company->getId();
        $categoryId = (string) $category->getId();

        $documentOperationRepository = $this->createMock(DocumentOperationRepository::class);
        $documentOperationRepository->method('countByCategory')->with($companyId, $categoryId)->willReturn(0);

        $dailyTotalRepository = $this->createMock(PLDailyTotalRepository::class);
        $dailyTotalRepository->expects(self::once())
            ->method('moveCategoryRowsToUncategorized')
            ->with($companyId, $categoryId);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $fn) => $fn());
        $em->expects(self::once())->method('remove')->with($category);

        $action = new DeletePLCategoryAction($em, $dailyTotalRepository, $documentOperationRepository);

        $action($category, $companyId);
    }
}
