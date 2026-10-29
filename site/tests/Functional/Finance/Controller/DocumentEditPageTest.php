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
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Страница документа ОПиУ: операция, добавленная без суммы, — ошибка формы под полем
 * и в сводке, а не 500; тип операции и итоги считаются по flow статьи.
 */
final class DocumentEditPageTest extends WebTestCaseBase
{
    /**
     * Регрессия: у документа с основанием ДДС контроллер считал сумму документа до
     * валидации и падал на операции без суммы (Typed property ...::$amount).
     */
    #[DataProvider('withAndWithoutBase')]
    public function testOperationWithoutAmountIsAFormErrorNotA500(bool $withBase): void
    {
        $client = static::createClient();
        [$user, $company, $income] = $this->prepare();
        $document = $this->persistDocument($company, $income, $withBase);

        $this->login($client, $user, $company);
        $crawler = $client->request('GET', sprintf('/documents/%s/edit', $document->getId()));
        self::assertResponseIsSuccessful();

        $values = $crawler->selectButton('Сохранить')->form()->getPhpValues();
        $values['document']['operations'][5] = ['category' => (string) $income->getId(), 'amount' => ''];
        $client->request('POST', sprintf('/documents/%s/edit', $document->getId()), $values);

        self::assertResponseIsSuccessful();
        $crawler = $client->getCrawler();
        self::assertCount(1, $crawler->filter('[data-error-summary]'));
        self::assertStringContainsString('Операция 2', $crawler->filter('[data-error-summary]')->text());
        self::assertCount(1, $crawler->filter('#document_operations_5_amount.is-invalid'));

        $this->em()->clear();
        $reloaded = $this->em()->find(Document::class, $document->getId());
        self::assertInstanceOf(Document::class, $reloaded);
        self::assertCount(1, $reloaded->getOperations(), 'Невалидная операция не должна сохраниться.');
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function withAndWithoutBase(): iterable
    {
        yield 'без основания' => [false];
        yield 'с основанием ДДС' => [true];
    }

    /**
     * Очистить сумму у уже сохранённой операции: форма писала null в setAmount(string) — 500
     * до валидации. Ожидается ошибка под полем, сумма в БД прежняя.
     */
    public function testClearingAmountOfExistingOperationIsAFormError(): void
    {
        $client = static::createClient();
        [$user, $company, $income] = $this->prepare();
        $document = $this->persistDocument($company, $income, false);

        $this->login($client, $user, $company);
        $crawler = $client->request('GET', sprintf('/documents/%s/edit', $document->getId()));
        $client->submit($crawler->selectButton('Сохранить')->form(), ['document[operations][0][amount]' => '']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $client->getCrawler()->filter('#document_operations_0_amount.is-invalid'));
        $this->em()->clear();
        $operation = $this->em()->find(Document::class, $document->getId())?->getOperations()->first();
        self::assertInstanceOf(DocumentOperation::class, $operation);
        self::assertSame('100.00', $operation->getAmount());
    }

    public function testEditPageRendersFlowTypeCommentAndKeepsJsHooks(): void
    {
        $client = static::createClient();
        [$user, $company, $income] = $this->prepare();
        $document = $this->persistDocument($company, $income, true);

        $this->login($client, $user, $company);
        $crawler = $client->request('GET', sprintf('/documents/%s/edit', $document->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame('Доход', trim($crawler->filter('[data-operation-flow-label]')->text()));
        self::assertCount(1, $crawler->filter(sprintf('option[value="%s"][data-flow="INCOME"]', $income->getId())));
        self::assertSame('Возврат поставщику', $crawler->filter('#document_operations_0_comment')->attr('value'));
        foreach (['[data-collection-holder][data-prototype]', '.add_item_link', '.remove-item', '.document-operation-card', '[data-document-counterparty]', '[data-document-project-direction]', '[data-document-responsibility-center]', '[data-allocation][data-allocation-available]'] as $hook) {
            self::assertGreaterThan(0, $crawler->filter($hook)->count(), $hook);
        }
        // Контрагент один на документ — только в шапке, у строки операции поля нет.
        self::assertStringNotContainsString('data-operation-counterparty', (string) $crawler->filter('[data-collection-holder]')->attr('data-prototype'));
        self::assertCount(0, $crawler->filter('[name="document[operations][0][counterparty]"]'));
    }

    public function testCommentIsSaved(): void
    {
        $client = static::createClient();
        [$user, $company, $income] = $this->prepare();
        $document = $this->persistDocument($company, $income, false);

        $this->login($client, $user, $company);
        $crawler = $client->request('GET', sprintf('/documents/%s/edit', $document->getId()));
        $client->submit($crawler->selectButton('Сохранить')->form(), ['document[operations][0][comment]' => 'Новый комментарий']);

        self::assertResponseRedirects('/documents/resume');
        $this->em()->clear();
        $reloaded = $this->em()->find(Document::class, $document->getId());
        self::assertInstanceOf(Document::class, $reloaded);
        $operation = $reloaded->getOperations()->first();
        self::assertInstanceOf(DocumentOperation::class, $operation);
        self::assertSame('Новый комментарий', $operation->getComment());
    }

    /**
     * Новая операция получает контрагента шапки на сервере — и архивного тоже (раньше его
     * подставлял скрипт, и архивного среди вариантов строки могло не быть).
     */
    public function testNewOperationTakesArchivedCounterpartyOfTheHeader(): void
    {
        $client = static::createClient();
        [$user, $company, $income] = $this->prepare();
        $document = $this->persistDocument($company, $income, false);
        $archived = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid4()->toString())->withCompany($company)
            ->withName('Архивный поставщик')->asArchived()->build();
        $document->setCounterparty($archived);
        $this->em()->persist($archived);
        $this->em()->flush();
        $archivedId = $archived->getId();

        $this->login($client, $user, $company);
        $crawler = $client->request('GET', sprintf('/documents/%s/edit', $document->getId()));
        self::assertResponseIsSuccessful();

        $values = $crawler->selectButton('Сохранить')->form()->getPhpValues();
        $values['document']['operations'][5] = ['category' => (string) $income->getId(), 'amount' => '50.00'];
        $client->request('POST', sprintf('/documents/%s/edit', $document->getId()), $values);
        self::assertResponseRedirects('/documents/resume');

        self::assertSame([$archivedId, $archivedId], $this->operationCounterparties($document));
    }

    /**
     * Один документ — один контрагент: смена контрагента в шапке переходит во все операции.
     */
    public function testHeaderCounterpartyChangeIsCarriedToAllOperations(): void
    {
        $client = static::createClient();
        [$user, $company, $income] = $this->prepare();
        $document = $this->persistDocument($company, $income, false);
        $old = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid4()->toString())->withCompany($company)->withName('Старый')->build();
        $new = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid4()->toString())->withCompany($company)->withName('Новый')->build();
        $this->em()->persist($old);
        $this->em()->persist($new);
        $document->setCounterparty($old);
        $this->em()->flush();
        $newId = $new->getId();

        $this->login($client, $user, $company);
        $crawler = $client->request('GET', sprintf('/documents/%s/edit', $document->getId()));
        $values = $crawler->selectButton('Сохранить')->form()->getPhpValues();
        $values['document']['counterparty'] = $newId;
        $values['document']['operations'][5] = ['category' => (string) $income->getId(), 'amount' => '50.00'];
        $client->request('POST', sprintf('/documents/%s/edit', $document->getId()), $values);
        self::assertResponseRedirects('/documents/resume');

        self::assertSame([$newId, $newId], $this->operationCounterparties($document));
    }

    /**
     * Своего контрагента операции через форму не передать: поля нет, а значение со страницы,
     * открытой до выкладки, отбрасывается — операция остаётся с контрагентом шапки.
     */
    public function testOperationCounterpartyInPostIsIgnored(): void
    {
        $client = static::createClient();
        [$user, $company, $income] = $this->prepare();
        $document = $this->persistDocument($company, $income, false);
        $other = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid4()->toString())->withCompany($company)->withName('Чужой для документа')->build();
        $this->em()->persist($other);
        $this->em()->flush();

        $this->login($client, $user, $company);
        $crawler = $client->request('GET', sprintf('/documents/%s/edit', $document->getId()));
        $values = $crawler->selectButton('Сохранить')->form()->getPhpValues();
        $values['document']['operations'][0]['counterparty'] = $other->getId();
        $client->request('POST', sprintf('/documents/%s/edit', $document->getId()), $values);

        self::assertResponseRedirects('/documents/resume');
        self::assertSame([null], $this->operationCounterparties($document));
    }

    /**
     * Контрагент в шапке — окно выбора как у пилюли фильтра (browse): тип варианта
     * нужен окну для групп и иконок.
     */
    public function testCounterpartyFieldsUseBrowseWindow(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepare();
        $counterparty = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid4()->toString())->withCompany($company)->withName('ООО Ромашка')->build();
        $this->em()->persist($counterparty);
        $this->em()->flush();

        $this->login($client, $user, $company);
        $crawler = $client->request('GET', '/documents/new');
        self::assertResponseIsSuccessful();

        $header = $crawler->filter('[data-document-counterparty]')->closest('[data-cp-picker]');
        self::assertNotNull($header);
        self::assertSame('1', $header->attr('data-cp-browse'));
        self::assertSame($counterparty->getType()->value, $crawler->filter(sprintf('[data-document-counterparty] option[value="%s"]', $counterparty->getId()))->attr('data-type'));
        self::assertStringNotContainsString('data-cp-picker', (string) $crawler->filter('[data-collection-holder]')->attr('data-prototype'), 'у строки операции контрагента нет');
    }

    /**
     * @return list<string|null>
     */
    private function operationCounterparties(Document $document): array
    {
        $this->em()->clear();
        $reloaded = $this->em()->find(Document::class, $document->getId());
        self::assertInstanceOf(Document::class, $reloaded);

        return array_values(array_map(
            static fn (DocumentOperation $operation): ?string => $operation->getCounterparty()?->getId(),
            $reloaded->getOperations()->toArray(),
        ));
    }

    private function persistDocument(Company $company, PLCategory $income, bool $withBase): Document
    {
        $em = $this->em();
        $document = new Document(Uuid::uuid4()->toString(), $company);
        $document->setNumber('EDIT-1');
        $document->setDate(new \DateTimeImmutable('2026-10-08'));
        $operation = new DocumentOperation();
        $operation->setCategory($income);
        $operation->setAmount('100.00');
        $operation->setComment('Возврат поставщику');
        $document->addOperation($operation);

        if ($withBase) {
            $account = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid4()->toString())->forCompany($company)->build();
            $transaction = CashTransactionBuilder::aCashTransaction()->withId(Uuid::uuid4()->toString())->forCompany($company)->withMoneyAccount($account)->withAmount('500.00')->build();
            $em->persist($account);
            $em->persist($transaction);
            $document->setCashTransaction($transaction);
        }

        $em->persist($operation);
        $em->persist($document);
        $em->flush();

        return $document;
    }

    /**
     * @return array{0: User, 1: Company, 2: PLCategory}
     */
    private function prepare(): array
    {
        $em = $this->em();
        $user = UserBuilder::aUser()->withIndex(random_int(1000, 9999))->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(random_int(1000, 9999))->withOwner($user)->build();
        $income = PLCategoryBuilder::aPLCategory()->withId(Uuid::uuid4()->toString())->forCompany($company)->withName('Выручка')->withFlow(PLFlow::INCOME)->build();
        // Ручной документ при сохранении получает системную пару «Общий проект × Общий ЦФО».
        $systemProject = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Общий', ProjectDirection::CODE_GENERAL);
        $systemCenter = new FinancialResponsibilityCenter((string) $company->getId(), FinancialResponsibilityCenter::CODE_GENERAL, FinancialResponsibilityCenter::NAME_GENERAL);
        foreach ([$user, $company, $income, $systemProject, $systemCenter, new FinancialResponsibilityCenterProject((string) $company->getId(), $systemProject, $systemCenter)] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return [$user, $company, $income];
    }

    private function login(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }
}
