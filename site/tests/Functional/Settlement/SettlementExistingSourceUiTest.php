<?php

declare(strict_types=1);

namespace App\Tests\Functional\Settlement;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Company\Entity\Company;
use App\Settlement\Application\ConfigureSettlementBookAction;
use App\Settlement\Application\DTO\RegisterCashSettlementInput;
use App\Settlement\Application\RegisterCashSettlementAction;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class SettlementExistingSourceUiTest extends WebTestCaseBase
{
    /** @return array{KernelBrowser, Company, CashTransaction} */
    private function linkedPayment(): array
    {
        $client = static::createClient();
        $user = UserBuilder::aUser()->withIndex(9618)->build();
        $company = CompanyBuilder::aCompany()->withIndex(9618)->withOwner($user)->build();
        $party = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid7()->toString())->withCompany($company)->build();
        $cash = CashTransactionBuilder::aCashTransaction()->forCompany($company)->withAmount('40000.00')->build();
        $date = new \DateTimeImmutable('2026-11-01');
        $cash->setOccurredAt($date)->setCounterparty($party);
        foreach ([$user, $company, $party, $cash->getMoneyAccount(), $cash] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $companyId = $company->getId();
        $actorId = $user->getId();
        $cashId = $cash->getId();
        self::assertNotNull($companyId);
        self::assertNotNull($actorId);
        self::assertNotNull($cashId);
        (static::getContainer()->get(ConfigureSettlementBookAction::class))($companyId, $actorId, $date);
        (static::getContainer()->get(RegisterCashSettlementAction::class))($companyId, $actorId, new RegisterCashSettlementInput(Uuid::uuid7()->toString(), $cashId, false, $date));
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);

        return [$client, $company, $cash];
    }

    public function testBlockedBulkDeleteReturnsToExistingListWithExplanation(): void
    {
        [$client, $company, $cash] = $this->linkedPayment();
        $client->request('POST', '/finance/cash-transactions/bulk-delete', ['_token' => $this->csrfToken($client, 'cash_transaction_bulk_delete'), 'transaction_ids' => [$cash->getId()]]);
        self::assertResponseRedirects('/finance/cash-transactions/');
        $client->followRedirect();
        self::assertSelectorTextContains('.toast.text-bg-danger', 'Источник участвует во взаиморасчётах');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM cash_transaction WHERE company_id = ? AND deleted_at IS NOT NULL', [$company->getId()]));
    }

    public function testBlockedAmountEditPreservesExistingEditForm(): void
    {
        [$client, $company, $cash] = $this->linkedPayment();
        $crawler = $client->request('GET', '/finance/cash-transactions/'.$cash->getId().'/edit');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="cash_transaction"]')->form();
        $form['cash_transaction[amount]'] = '40001.00';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('form[name="cash_transaction"]');
        self::assertSelectorTextContains('body', 'Источник участвует во взаиморасчётах');
        self::assertSame('40000.00', $this->em()->getConnection()->fetchOne('SELECT amount FROM cash_transaction WHERE company_id = ? AND id = ?', [$company->getId(), $cash->getId()]));
    }
}
