<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;

/**
 * Форма проверяется целиком — GET и POST.
 *
 * Тестов на Action недостаточно: маппинг поля категории живёт в форме, и ошибка в нём
 * не видна ни одному тесту, который зовёт Action напрямую.
 */
final class CashTransactionSplitsControllerTest extends WebTestCaseBase
{
    public function testFormRendersExistingSplitAndSavesNewComposition(): void
    {
        $client = static::createClient();
        [$company, $user, $transaction, $rent, $ads] = $this->fixtures();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', sprintf('/finance/cash-transactions/%s/splits', $transaction->getId()));
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Сохранить разбивку')->form();

        // Значением select обязан быть идентификатор: с объектами в choices поле не
        // смапилось бы ни на отрисовке, ни на отправке.
        $form['cash_transaction_splits[rows][0][cashflowCategoryId]'] = $rent->getId();
        $form['cash_transaction_splits[rows][0][amount]'] = '600.00';

        $values = $form->getPhpValues();
        $values['cash_transaction_splits']['rows'][1] = [
            'cashflowCategoryId' => $ads->getId(),
            'amount' => '400.00',
        ];

        $client->request($form->getMethod(), $form->getUri(), $values);
        self::assertResponseRedirects();

        $reloaded = $this->em()->getRepository(CashTransaction::class)->find($transaction->getId());
        self::assertNotNull($reloaded);
        self::assertCount(2, $reloaded->getSplits());
        self::assertSame('1000.00', $reloaded->getSplitsTotal());
    }

    public function testSumMismatchKeepsUserOnFormWithError(): void
    {
        $client = static::createClient();
        [$company, $user, $transaction, $rent, $ads] = $this->fixtures();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', sprintf('/finance/cash-transactions/%s/splits', $transaction->getId()));
        $form = $crawler->selectButton('Сохранить разбивку')->form();
        $form['cash_transaction_splits[rows][0][cashflowCategoryId]'] = $rent->getId();
        $form['cash_transaction_splits[rows][0][amount]'] = '600.00';

        $values = $form->getPhpValues();
        $values['cash_transaction_splits']['rows'][1] = [
            'cashflowCategoryId' => $ads->getId(),
            'amount' => '399.99',
        ];

        $client->request($form->getMethod(), $form->getUri(), $values);

        // Отказ агрегата — это ошибка формы, а не пятисотка: пользователю нужно поправить строки.
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('не равна сумме транзакции', (string) $client->getResponse()->getContent());

        $reloaded = $this->em()->getRepository(CashTransaction::class)->find($transaction->getId());
        self::assertCount(0, $reloaded->getSplits(), 'Неверный состав не должен сохраняться.');
    }

    public function testSparseRowIndexesSurviveFailedSubmit(): void
    {
        $client = static::createClient();
        [$company, $user, $transaction, $rent, $ads] = $this->fixtures();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', sprintf('/finance/cash-transactions/%s/splits', $transaction->getId()));
        $form = $crawler->selectButton('Сохранить разбивку')->form();
        $values = $form->getPhpValues();

        // Пользователь удалил среднюю строку: индексы приходят разреженными.
        $values['cash_transaction_splits']['rows'] = [
            0 => ['cashflowCategoryId' => $rent->getId(), 'amount' => '600.00'],
            2 => ['cashflowCategoryId' => $ads->getId(), 'amount' => '399.99'],
        ];

        $crawler = $client->request($form->getMethod(), $form->getUri(), $values);
        self::assertResponseIsSuccessful();

        // Следующий индекс обязан быть больше максимального существующего, иначе новая
        // строка займёт чужое имя и две строки схлопнутся при следующей отправке.
        $nextIndex = (int) $crawler->filter('[data-splits-rows]')->attr('data-next-index');
        self::assertGreaterThan(2, $nextIndex, 'Счётчик по длине выдал бы занятый индекс.');
    }

    public function testSplitEntryPointIsVisibleOnCardAndList(): void
    {
        $client = static::createClient();
        [$company, $user, $transaction] = $this->fixtures();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $expectedHref = sprintf('/finance/cash-transactions/%s/splits', $transaction->getId());

        // Карточка: ссылка живёт среди действий в подвале, а не мелким текстом
        // внутри строки «Статья», где её не находят.
        $card = $client->request('GET', sprintf('/finance/cash-transactions/%s', $transaction->getId()));
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $card->filter(sprintf('.card-footer a[href="%s"]', $expectedHref))->count(),
            'Вход в разбивку должен быть среди действий карточки.',
        );

        $list = $client->request('GET', '/finance/cash-transactions/');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $list->filter(sprintf('a[href="%s"]', $expectedHref))->count(),
            'Из списка тоже должен быть вход: пользователь чаще всего именно там.',
        );
    }

    public function testOtherCompanyTransactionIsNotReachable(): void
    {
        $client = static::createClient();
        [$company, $user] = $this->fixtures();
        [, , $foreignTransaction] = $this->fixtures();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $client->request('GET', sprintf('/finance/cash-transactions/%s/splits', $foreignTransaction->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return array{0: Company, 1: object, 2: CashTransaction, 3: CashflowCategory, 4: CashflowCategory}
     */
    private function fixtures(): array
    {
        $em = $this->em();
        $suffix = substr(Uuid::uuid4()->toString(), 0, 8);

        $user = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail(sprintf('splits-ui-%s@example.test', $suffix))
            ->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($user)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->build();

        $rent = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $rent->setName('Аренда '.$suffix);
        $ads = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $ads->setName('Реклама '.$suffix);

        $transaction = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            CashDirection::OUTFLOW,
            '1000.00',
            'RUB',
            new \DateTimeImmutable('2026-01-15'),
        );

        foreach ([$user, $company, $account, $rent, $ads, $transaction] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return [$company, $user, $transaction, $rent, $ads];
    }
}
