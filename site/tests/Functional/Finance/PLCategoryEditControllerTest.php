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
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
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

    /**
     * Прод-сценарий ИП Лазарева: группу «Общепроизводственные» вынесли в корень,
     * её статьи остались на уровне 3, и отчёт ОПиУ показал новую соседнюю статью
     * уровня 2 как группу.
     */
    public function testMovingGroupToRootPersistsRecalculatedChildLevels(): void
    {
        $client = static::createClient();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $expenses = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Расходы')->build();
        $group = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Общепроизводственные')->withParent($expenses)->build();
        $rent = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Аренда офис-склад')->withParent($group)->build();

        $em = $this->em();
        foreach ([$user, $company, $expenses, $group, $rent] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        self::assertSame(3, $rent->getLevel());

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/pl-categories/'.$group->getId().'/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('#pl-category-edit-form')->form();
        $parentField = (string) $crawler->filter('select[id$="_parent"]')->attr('name');
        $form->setValues([$parentField => '']);
        $client->submit($form);

        self::assertResponseRedirects('/pl-categories/');

        $em->clear();
        $levels = $em->getConnection()->fetchAllKeyValue(
            'SELECT name, level FROM pl_categories WHERE company_id = :company_id',
            ['company_id' => $company->getId()],
        );
        self::assertEquals(['Расходы' => 1, 'Общепроизводственные' => 1, 'Аренда офис-склад' => 2], $levels);
    }

    public function testEditOffersOnlyParentsKeepingSubtreeWithinMaxDepth(): void
    {
        $client = static::createClient();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $chain = [];
        $parent = null;
        foreach (['L1', 'L2', 'L3', 'L4', 'L5'] as $name) {
            $parent = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName($name)->withParent($parent)->build();
            $chain[] = $parent;
        }
        $group = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Group')->build();
        $leaf = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Leaf')->withParent($group)->build();

        $em = $this->em();
        foreach ([$user, $company, ...$chain, $group, $leaf] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/pl-categories/'.$group->getId().'/edit');
        self::assertResponseIsSuccessful();

        $offered = $crawler->filter('select[id$="_parent"] option')->each(static fn ($node) => $node->attr('value'));

        // Ветка Group высотой 1: под L3 она встаёт на уровни 4–5, под L4 лист ушёл бы на 6.
        self::assertContains($chain[2]->getId(), $offered);
        self::assertNotContains($chain[3]->getId(), $offered);
        self::assertNotContains($chain[4]->getId(), $offered);
        self::assertNotContains($leaf->getId(), $offered);
    }

    public function testDeleteMergesDailyTotalsIntoUncategorizedBucket(): void
    {
        $client = static::createClient();

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
        self::assertSelectorTextContains('.text-bg-danger .toast-body', '1 операция документов ОПиУ');

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

    /**
     * GlitchTip #451: у статьи есть вложенные — FK pl_categories.parent_id без ON DELETE
     * давал 500. Теперь отказ с причиной, обе статьи целы.
     */
    public function testDeleteOfCategoryWithChildrenIsRefusedWithReason(): void
    {
        $client = static::createClient();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $parent = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Косвенные расходы')->build();
        $child = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Аренда')->withParent($parent)->build();

        $em = $this->em();
        foreach ([$user, $company, $parent, $child] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $parentId = (string) $parent->getId();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', (string) $company->getId());

        // Список: у родителя диалог-запрет с причиной, у свободной вложенной — обычное подтверждение.
        $crawler = $client->request('GET', '/pl-categories/');
        self::assertResponseIsSuccessful();
        $parentTrigger = $crawler->filter(sprintf('[data-confirm-action="/pl-categories/%s/delete"]', $parentId));
        self::assertSame('true', $parentTrigger->attr('data-confirm-blocked'));
        self::assertStringContainsString('1 вложенная статья', (string) $parentTrigger->attr('data-confirm-body'));
        $childTrigger = $crawler->filter(sprintf('[data-confirm-action="/pl-categories/%s/delete"]', $child->getId()));
        self::assertNull($childTrigger->attr('data-confirm-blocked'));
        // На самом списке ссылка «в список статей» не нужна.
        self::assertSame([], json_decode((string) $parentTrigger->attr('data-confirm-links'), true));
        self::assertCount(1, $crawler->filter('#pl-category-confirm'));

        $client->request('POST', '/pl-categories/'.$parentId.'/delete', [
            '_token' => $this->csrfToken($client, 'delete'.$parentId),
        ]);

        self::assertResponseRedirects('/pl-categories/');
        $client->followRedirect();
        self::assertSelectorTextContains('.text-bg-danger .toast-body', 'Статью нельзя удалить: 1 вложенная статья. Перенесите или удалите вложенные статьи, затем удалите эту.');

        $this->em()->clear();
        self::assertCount(2, $this->em()->getRepository(PLCategory::class)->findBy(['company' => $company->getId()]));
    }

    public function testDeleteOfCategoryLinkedToCashflowCategoryIsRefusedWithReason(): void
    {
        $client = static::createClient();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $category = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Аренда')->build();
        $cashflowCategory = CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->withName('Аренда офиса')->build();
        $cashflowCategory->setPlCategory($category);

        $em = $this->em();
        foreach ([$user, $company, $category, $cashflowCategory] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $categoryId = (string) $category->getId();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', (string) $company->getId());

        $crawler = $client->request('GET', '/pl-categories/'.$categoryId.'/edit');
        self::assertResponseIsSuccessful();
        $triggers = $crawler->filter('[data-confirm-blocked="true"]');
        self::assertGreaterThan(0, $triggers->count());
        self::assertStringContainsString('1 связанная статья ДДС', (string) $triggers->first()->attr('data-confirm-body'));
        self::assertStringContainsString('Переназначьте ссылки на другую статью', (string) $triggers->first()->attr('data-confirm-body'));
        self::assertCount(1, $crawler->filter('#pl-category-confirm'));
        // Выход к причине — карточка связанной статьи ДДС.
        self::assertSame(
            [['href' => '/cashflow-categories/'.$cashflowCategory->getId().'/edit', 'label' => 'Статья ДДС «Аренда офиса»']],
            json_decode((string) $triggers->first()->attr('data-confirm-links'), true),
        );

        $client->request('POST', '/pl-categories/'.$categoryId.'/delete', [
            '_token' => $this->csrfToken($client, 'delete'.$categoryId),
        ]);

        self::assertResponseRedirects('/pl-categories/');
        $this->em()->clear();
        self::assertInstanceOf(PLCategory::class, $this->em()->getRepository(PLCategory::class)->find($categoryId));
    }

    public function testExitLinksLeadToDocumentsAndDeletedDocumentsOfCategory(): void
    {
        $client = static::createClient();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $category = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Реклама')->build();
        $other = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Прочее')->build();

        $em = $this->em();
        foreach ([$user, $company, $category, $other] as $entity) {
            $em->persist($entity);
        }
        // Живой и удалённый документ на статью, удалённый — на другую статью.
        $documents = [];
        foreach ([[$category, false], [$category, true], [$other, true]] as [$cat, $deleted]) {
            $document = new Document(Uuid::uuid4()->toString(), $company);
            $operation = new DocumentOperation();
            $operation->setAmount('10.00');
            $operation->setCategory($cat);
            $document->addOperation($operation);
            if ($deleted) {
                $document->markDeleted(null);
            }
            $em->persist($document);
            $documents[] = $document;
        }
        $em->flush();

        $categoryId = (string) $category->getId();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', (string) $company->getId());

        $crawler = $client->request('GET', '/pl-categories/'.$categoryId.'/edit');
        self::assertResponseIsSuccessful();
        $links = json_decode((string) $crawler->filter('[data-confirm-blocked="true"]')->first()->attr('data-confirm-links'), true);
        self::assertSame([
            ['href' => '/documents/?category='.$categoryId, 'label' => 'Документы со статьёй'],
            ['href' => '/documents/deleted?category='.$categoryId, 'label' => 'Удалённые документы со статьёй'],
        ], $links);

        // Удалённые со статьёй — только свой документ, а не документ другой статьи.
        $crawler = $client->request('GET', '/documents/deleted?category='.$categoryId);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-deleted-category-filter]'));
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString((string) $documents[1]->getId(), $html);
        self::assertStringNotContainsString((string) $documents[2]->getId(), $html);
    }

    public function testEditPageLeadsChildrenReasonToCategoryList(): void
    {
        $client = static::createClient();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $parent = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Косвенные')->build();
        $child = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Аренда')->withParent($parent)->build();

        $em = $this->em();
        foreach ([$user, $company, $parent, $child] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', (string) $company->getId());

        $crawler = $client->request('GET', '/pl-categories/'.$parent->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertSame(
            [['href' => '/pl-categories/', 'label' => 'Вложенные статьи — в списке статей']],
            json_decode((string) $crawler->filter('[data-confirm-blocked="true"]')->first()->attr('data-confirm-links'), true),
        );
    }
}
