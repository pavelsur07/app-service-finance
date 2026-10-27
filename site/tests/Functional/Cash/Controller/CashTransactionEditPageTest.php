<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\User;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Страница «Операция ДДС» (DDS-OPERATION-SPEC §2): название, «Сведения» только из
 * данных операции, меню «Ещё» без нерабочих пунктов, удалённая и выписочная операции.
 */
final class CashTransactionEditPageTest extends WebTestCaseBase
{
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

        foreach ([$user, $company, $account, $tx] as $entity) {
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
