<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\User;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Страница «Операция ДДС» (DDS-OPERATION-SPEC §2): название, «Сведения» только из
 * данных операции, меню «Ещё» без нерабочих пунктов, удалённая и выписочная операции.
 */
final class CashTransactionEditPageTest extends WebTestCaseBase
{
    /** @var list<object> сущности, которые tune добавил к операции (документы ОПиУ) */
    private array $extra = [];

    public function testHeaderBreadcrumbsAndInfoComeFromTheTransaction(): void
    {
        $crawler = $this->openEditPage(8201, static fn (CashTransaction $tx) => $tx);

        self::assertSame('Операция ДДС от 18.07.2026', trim($crawler->filter('title')->text()));
        self::assertSame('Операция ДДС от 18.07.2026', $crawler->filter('h1.dds-op__title')->text());
        self::assertSame(['Деньги', 'Операции ДДС', 'Редактирование'], $crawler->filter('.breadcrumb-item')->each(static fn (Crawler $n) => trim($n->text())));
        self::assertStringNotContainsString('транзакци', mb_strtolower($crawler->filter('.dds-op')->text()));
        self::assertSame("Отток\u{a0}−1\u{a0}250,50\u{a0}₽", str_replace(' ', "\u{a0}", $crawler->filter('.dds-badge--out')->text()));

        self::assertSame(['Источник', 'Создана', 'Изменена'], $crawler->filter('.dds-info dt')->each(static fn (Crawler $n) => $n->text()));
        self::assertSame('Вручную', $crawler->filter('.dds-info dd')->first()->text());
        self::assertSame(['Удалить операцию'], $crawler->filter('.dds-op .dropdown-menu .dropdown-item')->each(static fn (Crawler $n) => trim($n->text())));
        self::assertCount(1, $crawler->filter('button[type="submit"][form="dds-op-form"]'));
        self::assertCount(0, $crawler->filter('.dds-badge--bank, .dds-op__banner'));
    }

    public function testDirectionIsASegmentedRadioWithTheFormFieldName(): void
    {
        $crawler = $this->openEditPage(8202, static fn (CashTransaction $tx) => $tx);

        $radios = $crawler->filter('.dds-seg input[type="radio"][name="cash_transaction[direction]"]');
        self::assertCount(2, $radios);
        self::assertSame(['Приток', 'Отток'], $crawler->filter('.dds-seg__text')->each(static fn (Crawler $n) => trim($n->text())));
        self::assertSame('Отток', trim($crawler->filter('.dds-seg__input:checked + .dds-seg__text')->text()));
        self::assertCount(0, $crawler->filter('select[name="cash_transaction[direction]"]'));
    }

    public function testBankStatementOperationIsMarkedButNotLocked(): void
    {
        $crawler = $this->openEditPage(8203, static fn (CashTransaction $tx) => $tx->setImportSource('alfa')->setExternalId('ext-1'));

        self::assertSame('Из выписки', $crawler->filter('.dds-badge--bank')->text());
        self::assertStringContainsString('загружена из банковской выписки', $crawler->filter('.dds-op__banner--info')->text());
        self::assertSame('Банковская выписка (Альфа-Банк)', $crawler->filter('.dds-info dd')->first()->text());
        // Сервер разрешает менять эти поля — в интерфейсе они не блокируются (SPEC §7.2 — вопрос владельцу).
        self::assertCount(0, $crawler->filter('#dds-op-form input:disabled, #dds-op-form select:disabled, #dds-op-form fieldset:disabled'));
    }

    public function testDeletedOperationIsReadOnlyAndOffersRestore(): void
    {
        $crawler = $this->openEditPage(8204, static function (CashTransaction $tx): CashTransaction {
            $tx->markDeleted(null);

            return $tx;
        });

        self::assertSame('Удалена', $crawler->filter('.dds-badge--deleted')->text());
        self::assertStringContainsString('Операция удалена', $crawler->filter('.dds-op__banner--warn')->text());
        self::assertCount(1, $crawler->filter('.dds-op__banner--warn form[action$="/restore"] input[name="_token"]'));
        self::assertCount(1, $crawler->filter('#dds-op-form fieldset.dds-op__fieldset[disabled]'));
        self::assertCount(0, $crawler->filter('[data-dds-op-save], .dds-op .dropdown-menu'));
    }

    public function testServerErrorsAreListedInTheSummaryAndCommaAmountIsSavedExactly(): void
    {
        $crawler = $this->openEditPage(8205, static fn (CashTransaction $tx) => $tx);
        $client = static::getClient();
        self::assertNotNull($client);

        $form = $crawler->filter('#dds-op-form')->form();
        $form['cash_transaction[amount]'] = '';
        $crawler = $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $crawler->filter('[data-dds-op-summary][hidden]'));
        self::assertStringStartsWith('Не удалось сохранить: 1.', $crawler->filter('[data-dds-op-summary-title]')->text());
        self::assertStringContainsString('Укажите сумму', $crawler->filter('[data-dds-op-summary-list]')->text());

        $form = $crawler->filter('#dds-op-form')->form();
        $form['cash_transaction[amount]'] = '5300,50';
        $form['cash_transaction[direction]'] = (string) $crawler->filter('.dds-seg__opt--in input')->attr('value');
        $client->submit($form);
        self::assertResponseRedirects();

        $this->em()->clear();
        $saved = $this->em()->find(CashTransaction::class, sprintf('66666666-6666-6666-8666-%012d', 8205));
        self::assertInstanceOf(CashTransaction::class, $saved);
        self::assertSame('5300.50', $saved->getAmount());
        self::assertSame(CashDirection::INFLOW, $saved->getDirection());
    }

    /**
     * @param list<string> $documentAmounts
     */
    #[DataProvider('pnlRestProvider')]
    public function testPnlDocumentsShowSignedAmountsAndTheRestState(int $index, array $documentAmounts, string $state, string $text): void
    {
        $crawler = $this->openEditPage($index, fn (CashTransaction $tx) => $this->attachDocuments($tx, $documentAmounts));

        $rest = $crawler->filter('[data-dds-op-rest]');
        self::assertSame($state, $rest->attr('data-dds-op-rest'));
        self::assertSame($text, $this->plain($rest->text()));
        self::assertCount(count($documentAmounts), $crawler->filter('.dds-docs tbody tr'));
        // Отток: суммы документов со знаком «−», ссылка «Открыть» на документ.
        $crawler->filter('.dds-docs tbody td.text-end:not(:last-child)')->each(static fn (Crawler $n) => self::assertStringStartsWith('−', trim($n->text())));
        $crawler->filter('.dds-docs__open')->each(static fn (Crawler $n) => self::assertMatchesRegularExpression('#/documents/[0-9a-f-]{36}/edit$#', (string) $n->attr('href')));
    }

    /**
     * @return iterable<string, array{int, list<string>, string, string}>
     */
    public static function pnlRestProvider(): iterable
    {
        yield 'нет документов' => [8211, [], 'none', 'Не разнесено в ОПиУ'];
        yield 'разнесено полностью' => [8212, ['1250.50'], 'full', 'Разнесено полностью'];
        yield 'разнесено частично' => [8213, ['1000.00'], 'partial', 'Не разнесено 250,50 ₽'];
        yield 'превышение' => [8214, ['1000.00', '300.00'], 'over', 'Превышение на 49,50 ₽'];
    }

    /**
     * @param list<string> $amounts
     */
    private function attachDocuments(CashTransaction $tx, array $amounts): CashTransaction
    {
        $company = $tx->getCompany();
        $category = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Аренда '.$tx->getId())->withFlow(PLFlow::EXPENSE)->build();
        $this->extra[] = $category;
        foreach ($amounts as $i => $amount) {
            $document = new Document(sprintf('77777777-7777-7777-8777-%s%02d', substr((string) $tx->getId(), -10), $i), $company);
            $document->setNumber('Д-'.($i + 1));
            $document->setDate(new \DateTimeImmutable('2026-07-18'));
            $operation = new DocumentOperation();
            $operation->setCategory($category);
            $operation->setAmount($amount);
            $document->addOperation($operation);
            $tx->addDocument($document);
            array_push($this->extra, $operation, $document);
        }

        return $tx;
    }

    private function plain(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{a0}\x{202f}]+/u', ' ', $text));
    }

    /**
     * @param callable(CashTransaction): CashTransaction $tune
     */
    private function openEditPage(int $index, callable $tune): Crawler
    {
        $client = static::createClient();
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(sprintf('33333333-3333-3333-3333-%012d', $index))
            ->forCompany($company)
            ->build();
        $tx = $tune(new CashTransaction(
            sprintf('66666666-6666-6666-8666-%012d', $index),
            $company,
            $account,
            CashDirection::OUTFLOW,
            '1250.50',
            'RUB',
            new \DateTimeImmutable('2026-07-18'),
        ));

        foreach ([$user, $company, $account, $tx, ...$this->extra] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->em()->clear();

        $this->login($client, $user, (string) $company->getId());
        $crawler = $client->request('GET', '/finance/cash-transactions/'.$tx->getId().'/edit');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function login(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, User $user, string $companyId): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);
    }
}
