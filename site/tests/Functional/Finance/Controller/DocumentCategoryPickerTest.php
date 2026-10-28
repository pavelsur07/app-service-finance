<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Виджет «Категория ОПиУ» на странице документа: в каждой строке и в data-prototype
 * поверх прежнего select (имя поля и value не меняются), дерево статей своей
 * компании — одно на страницу.
 */
final class DocumentCategoryPickerTest extends WebTestCaseBase
{
    public function testEveryOperationAndThePrototypeGetThePickerOverTheSelectAndOneTreeOfTheCompany(): void
    {
        $client = static::createClient();
        [$user, $company, $group, $leaf] = $this->prepare();
        $foreignCompany = CompanyBuilder::aCompany()->withIndex(random_int(1000, 9999))->withOwner($user)->build();
        $foreign = PLCategoryBuilder::aPLCategory()->withId(Uuid::uuid4()->toString())->forCompany($foreignCompany)->withName('Чужая статья')->build();
        $this->persist($foreignCompany, $foreign);
        $document = $this->persistDocument($company, $leaf, 2);

        $this->login($client, $user, $company);
        $crawler = $client->request('GET', sprintf('/documents/%s/edit', $document->getId()));

        self::assertResponseIsSuccessful();
        foreach ([0, 1] as $i) {
            $select = $crawler->filter(sprintf('[data-dds-picker][data-tree-source="#document-category-tree"][data-path-below] select.dds-picker__native[name="document[operations][%d][category]"]', $i));
            self::assertCount(1, $select, 'строка '.$i);
            self::assertSame((string) $leaf->getId(), $select->filter('option[selected]')->attr('value'));
            self::assertSame('EXPENSE', $select->filter('option[selected]')->attr('data-flow'), 'flow для типа и итогов страницы');
        }
        self::assertSame('Категория ОПиУ', trim($crawler->filter('label[for="document_operations_0_category"]')->text()));

        $prototype = (string) $crawler->filter('[data-collection-holder]')->attr('data-prototype');
        self::assertStringContainsString('data-tree-source="#document-category-tree"', $prototype);
        self::assertSame(1, substr_count($prototype, 'data-tree="'), 'дерево статей не копируется в строку: своё дерево только у плоского ЦФО');

        $treeScript = $crawler->filter('script#document-category-tree[type="application/json"]');
        self::assertCount(1, $treeScript);
        $tree = json_decode($treeScript->text(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(
            [['id' => (string) $group->getId(), 'name' => 'Расходы', 'children' => [['id' => (string) $leaf->getId(), 'name' => 'Аренда </script> & цех', 'flow' => 'out']]]],
            $tree,
        );
        self::assertStringNotContainsString((string) $foreign->getId(), $treeScript->text());
    }

    /**
     * Макет PNL-DOCUMENT-SPEC: Проект/ЦФО шапки наследуются операциями на клиенте — у строки и у
     * data-prototype есть пометка «Изменён», ЦФО — тот же виджет; описание — прежнее поле comment.
     */
    public function testRowsCarryInheritanceMarksCfoPickerAndDescription(): void
    {
        $client = static::createClient();
        [$user, $company, , $leaf] = $this->prepare();
        $document = $this->persistDocument($company, $leaf, 1);

        $this->login($client, $user, $company);
        $crawler = $client->request('GET', sprintf('/documents/%s/edit', $document->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame('edit', $crawler->filter('form[data-document-form]')->attr('data-document-mode'));
        self::assertCount(1, $crawler->filter('[data-dds-picker] select[data-document-responsibility-center]'));
        $prototype = (string) $crawler->filter('[data-collection-holder]')->attr('data-prototype');
        foreach (['data-inherit-mark="project"', 'data-inherit-mark="cfo"', 'data-operation-duplicate', 'data-operation-comment', 'data-operation-responsibility-center'] as $hook) {
            self::assertStringContainsString($hook, $prototype, $hook);
            self::assertGreaterThan(0, $crawler->filter('[data-operation-row]')->filter(sprintf('[%s]', explode('=', $hook)[0]))->count(), $hook);
        }
        self::assertSame('Описание', trim($crawler->filter('label[for="document_operations_0_comment"]')->text()));
        self::assertSame(
            ['Дублировать документ', 'Выгрузить JSON', 'Удалить документ'],
            $crawler->filter('.vf-page-head .dropdown-menu .dropdown-item')->each(static fn ($n): string => trim($n->text())),
            'без основания пункта «Открыть операцию ДДС» нет',
        );
    }

    public function testPickedLeafIsSavedThroughTheSameField(): void
    {
        $client = static::createClient();
        [$user, $company, $group, $leaf] = $this->prepare();
        $other = PLCategoryBuilder::aPLCategory()->withId(Uuid::uuid4()->toString())->forCompany($company)->withName('Связь')->withParent($group)->build();
        $this->persist($other);
        $document = $this->persistDocument($company, $leaf, 1);

        $this->login($client, $user, $company);
        $crawler = $client->request('GET', sprintf('/documents/%s/edit', $document->getId()));
        $client->submit($crawler->selectButton('Сохранить')->form(), ['document[operations][0][category]' => (string) $other->getId()]);

        self::assertResponseRedirects('/documents/resume');
        self::assertSame((string) $other->getId(), (string) $this->reloadedOperation($document)->getCategory()?->getId());
    }

    public function testCategoryOfAnotherCompanyIsRejected(): void
    {
        $client = static::createClient();
        [$user, $company, , $leaf] = $this->prepare();
        $foreignCompany = CompanyBuilder::aCompany()->withIndex(random_int(1000, 9999))->withOwner($user)->build();
        $foreign = PLCategoryBuilder::aPLCategory()->withId(Uuid::uuid4()->toString())->forCompany($foreignCompany)->withName('Чужая статья')->build();
        $this->persist($foreignCompany, $foreign);
        $document = $this->persistDocument($company, $leaf, 1);

        $this->login($client, $user, $company);
        $crawler = $client->request('GET', sprintf('/documents/%s/edit', $document->getId()));
        $form = $crawler->selectButton('Сохранить')->form();
        $values = $form->getPhpValues();
        $values['document']['operations'][0]['category'] = (string) $foreign->getId();
        $crawler = $client->request('POST', (string) $form->getUri(), $values);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('select.is-invalid[name="document[operations][0][category]"]'), 'ошибка у поля, без сохранения');
        self::assertSame((string) $leaf->getId(), (string) $this->reloadedOperation($document)->getCategory()?->getId());
    }

    /**
     * IDOR: документ, проект и ЦФО другой компании через форму не открываются и не сохраняются.
     */
    public function testForeignDocumentProjectAndCfoAreRejected(): void
    {
        $client = static::createClient();
        [$user, $company, , $leaf] = $this->prepare();
        $foreignCompany = CompanyBuilder::aCompany()->withIndex(random_int(1000, 9999))->withOwner($user)->build();
        $foreignProject = new ProjectDirection(Uuid::uuid4()->toString(), $foreignCompany, 'Чужой проект');
        $foreignCenter = new FinancialResponsibilityCenter((string) $foreignCompany->getId(), 'FOREIGN', 'Чужой ЦФО');
        $foreignLeaf = PLCategoryBuilder::aPLCategory()->withId(Uuid::uuid4()->toString())->forCompany($foreignCompany)->withName('Чужая статья')->build();
        $this->persist($foreignCompany, $foreignProject, $foreignCenter, $foreignLeaf);
        $foreignDocument = $this->persistDocument($foreignCompany, $foreignLeaf, 1);
        $document = $this->persistDocument($company, $leaf, 1);

        $this->login($client, $user, $company);
        $client->request('GET', sprintf('/documents/%s/edit', $foreignDocument->getId()));
        self::assertResponseStatusCodeSame(404);

        $crawler = $client->request('GET', sprintf('/documents/%s/edit', $document->getId()));
        $form = $crawler->selectButton('Сохранить')->form();
        $values = $form->getPhpValues();
        $values['document']['projectDirection'] = (string) $foreignProject->getId();
        $values['document']['operations'][0]['responsibilityCenterId'] = $foreignCenter->getId();
        $crawler = $client->request('POST', (string) $form->getUri(), $values);

        self::assertResponseIsSuccessful();
        $summary = $crawler->filter('[data-error-summary] li')->each(static fn ($n): string => trim($n->text()));
        self::assertNotEmpty(array_filter($summary, static fn (string $line): bool => str_starts_with($line, 'Проект:')), 'чужой проект шапки: '.implode(' | ', $summary));
        self::assertCount(1, $crawler->filter('select.is-invalid[name="document[operations][0][responsibilityCenterId]"]'), 'чужой ЦФО операции');
        $this->em()->clear();
        self::assertNull($this->em()->find(Document::class, $document->getId())?->getProjectDirection());
    }

    private function reloadedOperation(Document $document): DocumentOperation
    {
        $this->em()->clear();
        $operation = $this->em()->find(Document::class, $document->getId())?->getOperations()->first();
        self::assertInstanceOf(DocumentOperation::class, $operation);

        return $operation;
    }

    private function persistDocument(Company $company, PLCategory $category, int $operations): Document
    {
        $document = new Document(Uuid::uuid4()->toString(), $company);
        $document->setNumber('PICK-1');
        $document->setDate(new \DateTimeImmutable('2026-10-08'));
        $entities = [];
        for ($i = 0; $i < $operations; ++$i) {
            $operation = new DocumentOperation();
            $operation->setCategory($category);
            $operation->setAmount('100.00');
            $document->addOperation($operation);
            $entities[] = $operation;
        }
        $this->persist($document, ...$entities);

        return $document;
    }

    /**
     * @return array{0: User, 1: Company, 2: PLCategory, 3: PLCategory}
     */
    private function prepare(): array
    {
        $user = UserBuilder::aUser()->withIndex(random_int(1000, 9999))->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(random_int(1000, 9999))->withOwner($user)->build();
        $group = PLCategoryBuilder::aPLCategory()->withId(Uuid::uuid4()->toString())->forCompany($company)->withName('Расходы')->withFlow(PLFlow::NONE)->withType(PLCategoryType::SUBTOTAL)->build();
        $leaf = PLCategoryBuilder::aPLCategory()->withId(Uuid::uuid4()->toString())->forCompany($company)->withName('Аренда </script> & цех')->withFlow(PLFlow::EXPENSE)->withParent($group)->build();
        // Ручной документ при сохранении получает системную пару «Общий проект × Общий ЦФО».
        $systemProject = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Общий', ProjectDirection::CODE_GENERAL);
        $systemCenter = new FinancialResponsibilityCenter((string) $company->getId(), FinancialResponsibilityCenter::CODE_GENERAL, FinancialResponsibilityCenter::NAME_GENERAL);
        $this->persist($user, $company, $group, $leaf, $systemProject, $systemCenter, new FinancialResponsibilityCenterProject((string) $company->getId(), $systemProject, $systemCenter));

        return [$user, $company, $group, $leaf];
    }

    private function persist(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
    }

    private function login(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }
}
