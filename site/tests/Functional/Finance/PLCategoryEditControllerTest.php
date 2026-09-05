<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Company\Entity\ProjectDirection;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLExpenseType;
use App\Finance\Enum\PLFlow;
use App\Finance\Repository\PLDailyTotalRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;

final class PLCategoryEditControllerTest extends WebTestCaseBase
{
    public function testEditPreservesFieldsExcludedFromSubmission(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $category = PLCategoryBuilder::aPLCategory()
            ->forCompany($company)
            ->withName('Variable expense')
            ->build()
            ->setExpenseType(PLExpenseType::VARIABLE)
            ->setIsVisible(false);
        $longIncomeName = str_repeat('Очень длинное наименование дохода ', 4);
        $incomeCategory = PLCategoryBuilder::aPLCategory()
            ->forCompany($company)
            ->withName($longIncomeName)
            ->withFlow(PLFlow::INCOME)
            ->build()
            ->setCode('INCOME_TOTAL_CODE')
            ->setType(PLCategoryType::SUBTOTAL);
        $expenseCategory = PLCategoryBuilder::aPLCategory()
            ->forCompany($company)
            ->withName('Expense subtotal')
            ->withFlow(PLFlow::EXPENSE)
            ->build()
            ->setCode('EXPENSE_TOTAL_CODE')
            ->setType(PLCategoryType::SUBTOTAL);
        $kpiCategory = PLCategoryBuilder::aPLCategory()
            ->forCompany($company)
            ->withName('Calculated KPI')
            ->withFlow(PLFlow::NONE)
            ->build()
            ->setCode('CALCULATED_KPI_CODE')
            ->setType(PLCategoryType::KPI);
        $subtotalCategory = PLCategoryBuilder::aPLCategory()
            ->forCompany($company)
            ->withName('Neutral subtotal')
            ->withFlow(PLFlow::NONE)
            ->build()
            ->setCode('NEUTRAL_SUBTOTAL_CODE')
            ->setType(PLCategoryType::SUBTOTAL);

        $em = $this->em();
        $em->persist($user);
        $em->persist($company);
        $em->persist($category);
        $em->persist($incomeCategory);
        $em->persist($expenseCategory);
        $em->persist($kpiCategory);
        $em->persist($subtotalCategory);
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/pl-categories/'.$category->getId().'/edit');

        self::assertResponseIsSuccessful();
        $expenseTypeField = $crawler->filter('select[id$="_expenseType"]');
        self::assertCount(1, $expenseTypeField);
        self::assertCount(1, $crawler->filter('select[id$="_flow"]'));
        self::assertCount(0, $crawler->filter('input[name$="[flow]"]'));
        self::assertCount(1, $crawler->filter('select[id$="_format"]'));
        self::assertCount(0, $crawler->filter('input[name$="[format]"]'));
        self::assertCount(0, $crawler->filter('input[id$="_isVisible"]'));
        self::assertSame(['INCOME_TOTAL_CODE'], $crawler->filter('#tab-income .pl-category-variable')->each(static fn ($node) => $node->attr('data-insert-code')));
        self::assertSame(['EXPENSE_TOTAL_CODE'], $crawler->filter('#tab-expense .pl-category-variable')->each(static fn ($node) => $node->attr('data-insert-code')));
        self::assertSame(['CALCULATED_KPI_CODE'], $crawler->filter('#tab-calculated .pl-category-variable')->each(static fn ($node) => $node->attr('data-insert-code')));

        $incomeVariableName = $crawler->filter('#tab-income .pl-category-variable-name');
        self::assertCount(1, $incomeVariableName->filter('.text-truncate'));
        self::assertSame($longIncomeName, $incomeVariableName->attr('title'));
        self::assertSame('INCOME_TOTAL_CODE', trim($crawler->filter('#tab-income .pl-category-variable-code')->text()));
        self::assertCount(1, $crawler->filter('#tab-income .pl-category-variable-code.flex-shrink-0.text-nowrap'));

        $form = $crawler->filter('#pl-category-edit-form')->form();
        $client->submit($form);

        self::assertResponseRedirects('/pl-categories/');

        $em->clear();
        $updatedCategory = $this->em()->getRepository(PLCategory::class)->find($category->getId());

        self::assertInstanceOf(PLCategory::class, $updatedCategory);
        self::assertSame(PLExpenseType::VARIABLE, $updatedCategory->getExpenseType());
        self::assertFalse($updatedCategory->isVisible());
    }

    public function testDeleteMergesDailyTotalsIntoUncategorizedBucket(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $project = new ProjectDirection('55555555-5555-5555-5555-000000077703', $company, 'Main');
        $category = PLCategoryBuilder::aPLCategory()
            ->forCompany($company)
            ->withId('33333333-3333-3333-3333-000000077703')
            ->withName('Delete me')
            ->withFlow(PLFlow::EXPENSE)
            ->build();

        $em = $this->em();
        foreach ([$user, $company, $project, $category] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        /** @var PLDailyTotalRepository $repository */
        $repository = self::getContainer()->get(PLDailyTotalRepository::class);
        $companyId = (string) $company->getId();
        $projectId = (string) $project->getId();
        $categoryId = (string) $category->getId();
        $date = new \DateTimeImmutable('2026-07-18');

        $repository->upsert($companyId, null, $date, $projectId, '10.00', '1.00', false);
        $repository->upsert($companyId, $categoryId, $date, $projectId, '20.00', '2.00', false);

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);
        $client->request('POST', '/pl-categories/'.$categoryId.'/delete', [
            '_token' => $this->csrfToken($client, 'delete'.$categoryId),
        ]);

        self::assertResponseRedirects('/pl-categories/');
        self::assertNull($this->em()->getRepository(PLCategory::class)->find($categoryId));

        $row = $this->em()->getConnection()->fetchAssociative(
            <<<'SQL'
                SELECT amount_income, amount_expense
                FROM pl_daily_totals
                WHERE company_id = :company_id
                  AND pl_category_id IS NULL
                  AND date = :date
                  AND project_direction_id = :project_id
                SQL,
            [
                'company_id' => $companyId,
                'date' => $date->format('Y-m-d'),
                'project_id' => $projectId,
            ],
        );

        self::assertIsArray($row);
        self::assertSame('30.00', $row['amount_income']);
        self::assertSame('3.00', $row['amount_expense']);
    }

    /**
     * Прод-сценарий issue 287: категория привязана к операции документа ОПиУ.
     * FK document_operations.category_id -> pl_categories не имеет ON DELETE, и до
     * этой правки исключение из БД долетало до пользователя как 500 вместо понятного
     * сообщения. Статья обязана уцелеть, а не просто "не упасть".
     */
    public function testDeleteFailsGracefullyWhenDocumentOperationsReferenceCategory(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $category = PLCategoryBuilder::aPLCategory()
            ->forCompany($company)
            ->withId('33333333-3333-3333-3333-000000077704')
            ->withName('In use')
            ->withFlow(PLFlow::EXPENSE)
            ->build();

        $document = new Document(Uuid::uuid4()->toString(), $company);
        $operation = new DocumentOperation();
        $operation->setAmount('42.00');
        $operation->setCategory($category);
        $document->addOperation($operation);

        $em = $this->em();
        foreach ([$user, $company, $category, $document] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $companyId = (string) $company->getId();
        $categoryId = (string) $category->getId();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);
        $client->request('POST', '/pl-categories/'.$categoryId.'/delete', [
            '_token' => $this->csrfToken($client, 'delete'.$categoryId),
        ]);

        self::assertResponseRedirects('/pl-categories/');

        $client->followRedirect();
        self::assertSelectorTextContains('.text-bg-danger .toast-body', 'привязаны операции');

        $this->em()->clear();
        self::assertInstanceOf(
            PLCategory::class,
            $this->em()->getRepository(PLCategory::class)->find($categoryId),
            'Категория с операциями обязана пережить попытку удаления.',
        );

        $reloadedOperation = $this->em()->getRepository(DocumentOperation::class)->find($operation->getId());
        self::assertInstanceOf(DocumentOperation::class, $reloadedOperation);
        $reloadedCategory = $reloadedOperation->getCategory();
        self::assertNotNull($reloadedCategory, 'Операция не должна лишиться категории при отклонённом удалении.');
        self::assertSame($categoryId, (string) $reloadedCategory->getId());
    }
}
