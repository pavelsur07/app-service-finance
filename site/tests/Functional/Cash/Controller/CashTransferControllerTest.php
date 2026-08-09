<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Application\DTO\CreateCashTransferCommand;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transfer\CashTransfer;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Facade\CashFacade;
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

final class CashTransferControllerTest extends WebTestCaseBase
{
    public function testCreatesShowsDeletesAndRestoresTransferPair(): void
    {
        $client = static::createClient();
        [$company, $user, $rubAccount, $usdAccount] = $this->fixtures();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/finance/cash-transfers/new');
        self::assertResponseIsSuccessful();
        self::assertSame(2, $crawler->filter('#cash_transfer_sourceAccount option:not([value=""])')->count());

        $form = $crawler->selectButton('Создать перевод')->form();
        $form['cash_transfer[occurredAt]'] = '2026-08-09';
        $form['cash_transfer[sourceAccount]'] = $rubAccount->getId();
        $form['cash_transfer[sourceAmount]'] = '9500,25';
        $form['cash_transfer[targetAccount]'] = $usdAccount->getId();
        $form['cash_transfer[targetAmount]'] = '100.00';
        $form['cash_transfer[description]'] = 'Покупка долларов';
        $values = $form->getPhpValues();
        $client->request($form->getMethod(), $form->getUri(), $values);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/finance/cash-transfers/[0-9a-f-]+$#', $location);

        $client->request($form->getMethod(), $form->getUri(), $values);
        self::assertResponseRedirects($location);
        self::assertCount(1, $this->em()->getRepository(CashTransfer::class)->findBy(['company' => $company]));

        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('9 500,25 RUB', $crawler->filter('.card-body')->text());
        self::assertStringContainsString('100,00 USD', $crawler->filter('.card-body')->text());
        self::assertStringContainsString('1 RUB = 0.010526', $crawler->filter('.card-body')->text());

        $transferId = basename($location);
        $this->em()->clear();
        $transfer = $this->em()->getRepository(CashTransfer::class)->find($transferId);
        self::assertInstanceOf(CashTransfer::class, $transfer);
        self::assertSame('9500.25', $transfer->getSourceTransaction()->getAmount());
        self::assertSame('100.00', $transfer->getTargetTransaction()->getAmount());
        $sourceTransactionId = (string) $transfer->getSourceTransaction()->getId();
        $standalone = new CashTransaction(
            Uuid::uuid4()->toString(),
            $transfer->getCompany(),
            $transfer->getSourceTransaction()->getMoneyAccount(),
            CashDirection::OUTFLOW,
            '1.00',
            'RUB',
            new \DateTimeImmutable('2026-08-09'),
        );
        $this->em()->persist($standalone);
        $this->em()->flush();

        $leg = $client->request('GET', '/finance/cash-transactions/'.$sourceTransactionId);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $leg->filter('a[href="'.$location.'"]')->count());
        self::assertSame(0, $leg->filter('a[href$="/edit"], a[href$="/splits"], form[action$="/delete"]')->count());

        $client->request('GET', '/finance/cash-transactions/'.$sourceTransactionId.'/splits');
        self::assertResponseRedirects($location);

        $list = $client->request('GET', '/finance/cash-transactions/');
        self::assertResponseIsSuccessful();
        self::assertSame(4, $list->filter('a[href="'.$location.'"]')->count());
        self::assertSame(2, $list->filter('input[type="checkbox"][value][disabled]')->count());

        $outflows = $client->request('GET', '/finance/cash-transactions/?direction=OUTFLOW');
        self::assertSame(0, $outflows->filter('#cash-transactions-select-all[disabled]')->count());
        self::assertSame(1, $outflows->filter('.js-cash-transaction-select')->count());

        $crawler = $client->request('GET', $location);
        $client->submit($crawler->selectButton('Удалить перевод')->form());
        self::assertResponseRedirects('/finance/cash-transfers/deleted');

        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('RUB source', $crawler->filter('tbody')->text());

        $deletedTransactions = $client->request('GET', '/finance/cash-transactions/deleted');
        self::assertResponseIsSuccessful();
        self::assertSame(2, $deletedTransactions->filter('a[href="'.$location.'"]')->count());
        self::assertSame(0, $deletedTransactions->filter('form[action$="/restore"]')->count());

        $crawler = $client->request('GET', '/finance/cash-transfers/deleted');

        $client->submit($crawler->selectButton('Восстановить')->form());
        self::assertResponseRedirects($location);
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Проведён', (string) $client->getResponse()->getContent());

        $this->em()->clear();
        $restored = $this->em()->getRepository(CashTransfer::class)->find($transferId);
        self::assertInstanceOf(CashTransfer::class, $restored);
        self::assertFalse($restored->isDeleted());
        self::assertFalse($restored->getSourceTransaction()->isDeleted());
        self::assertFalse($restored->getTargetTransaction()->isDeleted());

        $client->request('POST', $location.'/delete', ['_token' => 'invalid']);
        self::assertResponseRedirects($location);
        $this->em()->clear();
        $csrfProtected = $this->em()->getRepository(CashTransfer::class)->find($transferId);
        self::assertInstanceOf(CashTransfer::class, $csrfProtected);
        self::assertFalse($csrfProtected->isDeleted());
    }

    public function testOtherCompanyTransferIsNotReachable(): void
    {
        $client = static::createClient();
        [$company, $user] = $this->fixtures();
        [$foreignCompany, , $foreignSource, $foreignTarget] = $this->fixtures();
        $foreignTransfer = self::getContainer()->get(CashFacade::class)->createTransfer(
            new CreateCashTransferCommand(
                $foreignCompany->getId(),
                (string) $foreignSource->getId(),
                (string) $foreignTarget->getId(),
                '100.00',
                '100.00',
                new \DateTimeImmutable('2026-08-09'),
                'foreign-transfer-ui',
            ),
        );

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $client->request('GET', '/finance/cash-transfers/'.$foreignTransfer->transferId);

        self::assertResponseStatusCodeSame(404);
    }

    /** @return array{Company, object, MoneyAccount, MoneyAccount} */
    private function fixtures(): array
    {
        $suffix = substr(Uuid::uuid4()->toString(), 0, 8);
        $user = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail('cash-transfer-ui-'.$suffix.'@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($user)
            ->withName('Cash transfer UI '.$suffix)
            ->build();
        $rubAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('RUB source '.$suffix)
            ->withCurrency('RUB')
            ->build()
            ->setOpeningBalanceDate(new \DateTimeImmutable('2026-01-01'));
        $usdAccount = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName('USD target '.$suffix)
            ->withCurrency('USD')
            ->build()
            ->setOpeningBalanceDate(new \DateTimeImmutable('2026-01-01'));
        $project = new ProjectDirection(
            Uuid::uuid4()->toString(),
            $company,
            'Общий',
            ProjectDirection::CODE_GENERAL,
        );
        $center = new FinancialResponsibilityCenter(
            $company->getId(),
            FinancialResponsibilityCenter::CODE_GENERAL,
            FinancialResponsibilityCenter::NAME_GENERAL,
        );

        foreach ([$user, $company, $rubAccount, $usdAccount, $project, $center] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->persist(new FinancialResponsibilityCenterProject($company->getId(), $project, $center));
        $this->em()->flush();
        self::getContainer()->get(CashflowSystemCategoryService::class)->ensureStructure($company);
        $this->em()->flush();

        return [$company, $user, $rubAccount, $usdAccount];
    }
}
