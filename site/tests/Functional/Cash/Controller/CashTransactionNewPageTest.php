<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Service\Category\CashflowSystemCategoryService;
use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DomCrawler\Crawler;

/**
 * «Новая операция ДДС» (DDS-OPERATION-SPEC §2, Stage 7): те же поля, что у правки, без блоков,
 * которым до сохранения неоткуда взять данные; ключ повтора и валюта счёта — как раньше.
 */
final class CashTransactionNewPageTest extends WebTestCaseBase
{
    /** @var array<string, string> обязательные поля компании теста, кроме суммы и направления */
    private array $fill = [];
    private string $companyId = '';

    public function testPageUsesTheOperationLayoutWithoutEditOnlyBlocks(): void
    {
        $crawler = $this->openNewPage(8301);

        self::assertSame('Новая операция ДДС', trim($crawler->filter('title')->text()));
        self::assertSame('Новая операция ДДС', $crawler->filter('h1.dds-op__title')->text());
        self::assertSame(['Деньги', 'Операции ДДС', 'Новая'], $crawler->filter('.breadcrumb-item')->each(static fn (Crawler $n) => trim($n->text())));
        self::assertStringNotContainsString('транзакци', mb_strtolower($crawler->filter('.dds-op')->text()));

        self::assertSame(['Операция', 'Аналитика', 'Сведения'], $crawler->filter('.dds-card__title')->each(static fn (Crawler $n) => $n->text()));
        self::assertCount(0, $crawler->filter('.dds-docs, .dds-op .dropdown-menu, .dds-badge, select[name="cash_transaction[currency]"]'));
        self::assertTrue(Uuid::isValid((string) $crawler->filter('input[type="hidden"][name="cash_transaction[idempotencyKey]"]')->attr('value')));

        // Направление по умолчанию — первое (Приток), как у прежнего select.
        self::assertCount(2, $crawler->filter('.dds-seg input[type="radio"][name="cash_transaction[direction]"]'));
        self::assertSame('Приток', trim($crawler->filter('.dds-seg__input:checked + .dds-seg__text')->text()));

        // Валюта — из счёта (единственный счёт компании в долларах).
        self::assertSame('$', $crawler->filter('[data-dds-op-currency-symbol]')->text());
        self::assertSame('Валюта берётся из счёта: доллар США (USD)', $crawler->filter('[data-dds-op-currency-hint]')->text());

        $save = $crawler->filter('button[type="submit"][form="dds-op-form"][data-dds-op-save]');
        self::assertCount(1, $save);
        self::assertNull($save->attr('disabled'));
        self::assertSame('Операция ещё не сохранена', $crawler->filter('[data-dds-op-status]')->text());
    }

    public function testErrorsGoToTheSummaryAndCommaAmountIsSavedExactly(): void
    {
        $crawler = $this->openNewPage(8302);
        $client = static::getClient();
        self::assertNotNull($client);

        $form = $crawler->filter('#dds-op-form')->form($this->fill);
        $form['cash_transaction[amount]'] = '';
        $crawler = $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('Не удалось сохранить: 1.', $crawler->filter('[data-dds-op-summary-title]')->text());
        self::assertStringContainsString('Укажите сумму', $crawler->filter('[data-dds-op-summary-list]')->text());
        self::assertSame('$', $crawler->filter('[data-dds-op-currency-symbol]')->text());

        $form = $crawler->filter('#dds-op-form')->form($this->fill);
        $form['cash_transaction[amount]'] = '1500,50';
        $form['cash_transaction[direction]'] = (string) $crawler->filter('.dds-seg__opt--out input')->attr('value');
        $client->submit($form);
        self::assertResponseRedirects();

        $rows = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT amount, direction, currency FROM cash_transaction WHERE company_id = :company',
            ['company' => $this->companyId],
        );
        self::assertSame([['amount' => '1500.50', 'direction' => 'OUTFLOW', 'currency' => 'USD']], $rows);
    }

    private function openNewPage(int $index): Crawler
    {
        $client = static::createClient();
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(sprintf('33333333-3333-3333-3333-%012d', $index))
            ->forCompany($company)
            ->withCurrency('USD')
            ->build()
            ->setOpeningBalanceDate(new \DateTimeImmutable('2026-01-01'));
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Общий', ProjectDirection::CODE_GENERAL);
        $center = new FinancialResponsibilityCenter((string) $company->getId(), FinancialResponsibilityCenter::CODE_GENERAL, FinancialResponsibilityCenter::NAME_GENERAL);
        foreach ([$user, $company, $account, $project, $center] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->persist(new FinancialResponsibilityCenterProject((string) $company->getId(), $project, $center));
        $this->em()->flush();
        $this->ensureSystemCategories($company);
        $this->em()->clear();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', (string) $company->getId());
        $crawler = $client->request('GET', '/finance/cash-transactions/new');
        self::assertResponseIsSuccessful();
        $this->companyId = (string) $company->getId();
        $this->fill = [
            'cash_transaction[occurredAt]' => '2026-08-09',
            'cash_transaction[moneyAccount]' => (string) $account->getId(),
            'cash_transaction[projectDirection]' => (string) $project->getId(),
            'cash_transaction[responsibilityCenterId]' => (string) $center->getId(),
        ];

        return $crawler;
    }

    private function ensureSystemCategories(Company $company): void
    {
        self::getContainer()->get(CashflowSystemCategoryService::class)->ensureStructure($company);
        $this->em()->flush();
    }
}
