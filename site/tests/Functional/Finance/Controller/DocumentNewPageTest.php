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
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * «Новый документ ОПиУ» (PNL-DOCUMENT-SPEC §3, §6 п.5): та же разметка, что у правки, но с
 * текстами создания, подсказкой вместо истории и без меню «Ещё» у ручного документа.
 */
final class DocumentNewPageTest extends WebTestCaseBase
{
    public function testNewPageShowsCreationTextsHelpCardAndNoMoreMenu(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepare();

        $this->login($client, $user, $company);
        $crawler = $client->request('GET', '/documents/new');

        self::assertResponseIsSuccessful();
        self::assertSame('Новый документ ОПиУ', trim($crawler->filter('.vf-page-head h1')->text()));
        self::assertSame('Новый документ', trim($crawler->filter('.breadcrumb-item.active')->text()));
        self::assertSame('new', $crawler->filter('form[data-document-form]')->attr('data-document-mode'));
        self::assertSame('Создать документ', trim($crawler->filter('[data-document-save]')->text()));
        self::assertStringStartsWith('Документ ещё не создан', trim($crawler->filter('[data-dirty-text]')->text()));
        self::assertSame('Как это работает', trim($crawler->filter('[data-document-help] .card-title')->text()));
        self::assertCount(1, $crawler->filter('.vf-docform-grid--aside'));
        self::assertCount(0, $crawler->filter('.vf-page-head .dropdown-menu'), 'у ручного документа нет основания — пунктов «Ещё» нет');
        self::assertCount(0, $crawler->filter('[data-allocation]'));
    }

    public function testCreateRedirectsAsBeforeAndLeavesToast(): void
    {
        $client = static::createClient();
        [$user, $company, $leaf] = $this->prepare();

        $this->login($client, $user, $company);
        $crawler = $client->request('GET', '/documents/new');
        $form = $crawler->selectButton('Создать документ')->form();
        $values = $form->getPhpValues();
        $values['document']['date'] = '2026-10-08';
        $values['document']['operations'] = [['category' => (string) $leaf->getId(), 'amount' => '1500.00', 'comment' => 'Аренда офиса, октябрь']];
        $client->request('POST', (string) $form->getUri(), $values);

        self::assertResponseRedirects('/documents/resume');
        $session = $client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        self::assertSame(['Документ ОПиУ создан.'], $session->getFlashBag()->peek('success'));

        $this->em()->clear();
        $documents = $this->em()->getRepository(Document::class)->findBy(['company' => $company]);
        self::assertCount(1, $documents);
        self::assertInstanceOf(Document::class, $documents[0]);
        $operation = $documents[0]->getOperations()->first();
        self::assertInstanceOf(DocumentOperation::class, $operation);
        self::assertSame('Аренда офиса, октябрь', $operation->getComment());
    }

    public function testCopyPageIsTheCreationFormWithSourceValues(): void
    {
        $client = static::createClient();
        [$user, $company, $leaf] = $this->prepare();
        $source = new Document(Uuid::uuid4()->toString(), $company);
        $source->setNumber('COPY-1');
        $source->setDate(new \DateTimeImmutable('2026-10-08'));
        $operation = new DocumentOperation();
        $operation->setCategory($leaf);
        $operation->setAmount('250.00');
        $operation->setComment('Связь, октябрь');
        $source->addOperation($operation);
        $this->persist($source, $operation);

        $this->login($client, $user, $company);
        $crawler = $client->request('GET', sprintf('/documents/%s/copy', $source->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame('Копия документа ОПиУ', trim($crawler->filter('.vf-page-head h1')->text()));
        self::assertSame('Создать документ', trim($crawler->filter('[data-document-save]')->text()));
        self::assertSame('Связь, октябрь', $crawler->filter('[name="document[operations][0][comment]"]')->attr('value'));
        self::assertSame((string) $leaf->getId(), $crawler->filter('[name="document[operations][0][category]"] option[selected]')->attr('value'));
    }

    /**
     * Регресс: у select проекта операции не было пустого варианта — браузер выбирал первый проект,
     * и операция без проекта (или новая строка при пустой шапке) сохранялась с чужим проектом.
     */
    public function testOperationProjectHasEmptyChoiceSelectedWhenNotSet(): void
    {
        $client = static::createClient();
        [$user, $company, $leaf] = $this->prepare();
        $source = new Document(Uuid::uuid4()->toString(), $company);
        $source->setDate(new \DateTimeImmutable('2026-10-08'));
        $operation = new DocumentOperation();
        $operation->setCategory($leaf);
        $operation->setAmount('250.00');
        $source->addOperation($operation);
        $this->persist($source, $operation);

        $this->login($client, $user, $company);
        $crawler = $client->request('GET', sprintf('/documents/%s/copy', $source->getId()));

        self::assertResponseIsSuccessful();
        $select = $crawler->filter('[name="document[operations][0][projectDirection]"]');
        self::assertSame('', $select->filter('option')->first()->attr('value'));
        self::assertCount(0, $select->filter('option[selected]:not([value=""])'), 'без проекта выбран пустой вариант, а не первый проект');
        $prototype = (string) $crawler->filter('[data-collection-holder]')->attr('data-prototype');
        self::assertMatchesRegularExpression('/data-operation-project-direction="true"[^>]*>\s*<option value=""/', $prototype);
    }

    /**
     * @return array{0: User, 1: Company, 2: PLCategory}
     */
    private function prepare(): array
    {
        $user = UserBuilder::aUser()->withIndex(random_int(1000, 9999))->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(random_int(1000, 9999))->withOwner($user)->build();
        $leaf = PLCategoryBuilder::aPLCategory()->withId(Uuid::uuid4()->toString())->forCompany($company)->withName('Аренда')->withFlow(PLFlow::EXPENSE)->build();
        // Ручной документ при сохранении получает системную пару «Общий проект × Общий ЦФО».
        $systemProject = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Общий', ProjectDirection::CODE_GENERAL);
        $systemCenter = new FinancialResponsibilityCenter((string) $company->getId(), FinancialResponsibilityCenter::CODE_GENERAL, FinancialResponsibilityCenter::NAME_GENERAL);
        $this->persist($user, $company, $leaf, $systemProject, $systemCenter, new FinancialResponsibilityCenterProject((string) $company->getId(), $systemProject, $systemCenter));

        return [$user, $company, $leaf];
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
