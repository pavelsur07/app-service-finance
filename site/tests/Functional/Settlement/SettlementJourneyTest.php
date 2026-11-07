<?php

declare(strict_types=1);

namespace App\Tests\Functional\Settlement;

use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class SettlementJourneyTest extends WebTestCaseBase
{
    public function testManualObligationAndCashPaymentCanBeAllocatedThroughUi(): void
    {
        $client = self::createClient();
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $party = CounterpartyBuilder::aCounterparty()->withCompany($company)->build();
        $cash = CashTransactionBuilder::aCashTransaction()->forCompany($company)->withAmount('40000.00')->build();
        $cash->setCounterparty($party)->setOccurredAt(new \DateTimeImmutable('2026-11-01'));
        foreach ([$user, $company, $party, $cash->getMoneyAccount(), $cash] as $entity) {
            $this->em()->persist($entity);
        } $this->em()->flush();
        $companyId = (string) $company->getId();
        $partyId = $party->getId();
        $cashId = (string) $cash->getId();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);
        $client->request('GET', '/settlements');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Начать учёт');
        $crawler = $client->request('GET', '/settlements/setup');
        $form = $crawler->filter('form[name="settlement_book"]')->form();
        $form['settlement_book[startDate]'] = '2026-11-01';
        $client->submit($form);
        self::assertResponseRedirects('/settlements');
        $crawler = $client->request('GET', '/settlements/documents/new');
        $form = $crawler->filter('form[name="settlement_document"]')->form();
        $form['settlement_document[counterpartyId]'] = $partyId;
        $form['settlement_document[kind]'] = 'obligation';
        $form['settlement_document[bucket]'] = 'payable';
        $form['settlement_document[amount]'] = '100000.00';
        $form['settlement_document[effectiveDate]'] = '2026-11-01';
        $form['settlement_document[dueDate]'] = '2026-11-05';
        $form['settlement_document[reason]'] = 'Акт №1';
        $client->submit($form);
        self::assertResponseRedirects();
        $documentId = (string) $this->em()->getConnection()->fetchOne("SELECT id FROM settlement_documents WHERE company_id=? AND kind='obligation'", [$companyId]);
        $client->request('POST', '/settlements/documents/'.$documentId.'/post', ['version' => '1', '_token' => $this->csrfToken($client, 'settlement_post'.$documentId)]);
        self::assertResponseRedirects();
        $crawler = $client->request('GET', '/settlements/sources/cash/'.$cashId);
        $form = $crawler->filter('form[name="settlement_source"]')->form();
        $form['settlement_source[effectiveDate]'] = '2026-11-01';
        $client->submit($form);
        self::assertResponseRedirects();
        $db = $this->em()->getConnection();
        $debtId = (string) $db->fetchOne('SELECT id FROM settlement_items WHERE company_id=? AND document_id=?', [$companyId, $documentId]);
        $paymentId = (string) $db->fetchOne("SELECT id FROM settlement_items WHERE company_id=? AND bucket='unallocated_out'", [$companyId]);
        $crawler = $client->request('GET', '/settlements/allocations/new?counterparty='.$partyId);
        $form = $crawler->filter('form[name="settlement_allocation"]')->form();
        $form['settlement_allocation[obligationItemId]'] = $debtId;
        $form['settlement_allocation[paymentItemId]'] = $paymentId;
        $form['settlement_allocation[amount]'] = '40000.00';
        $form['settlement_allocation[effectiveDate]'] = '2026-11-02';
        $allocationPayload = $form->getPhpValues();
        $allocationUri = $form->getUri();
        $client->submit($form);
        self::assertResponseRedirects();
        $client->request('POST', $allocationUri, $allocationPayload);
        self::assertResponseRedirects();
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM settlement_allocations WHERE company_id=?', [$companyId]));
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'К оплате');
        $text = str_replace(["\u{00a0}", "\u{202f}"], ' ', $client->getCrawler()->filter('body')->text());
        self::assertStringContainsString('60 000,00', $text);
        self::assertSame('6000000', (string) $this->em()->getConnection()->fetchOne("SELECT SUM(delta_amount_minor) FROM settlement_movements WHERE company_id=? AND bucket='payable'", [$companyId]));
        $client->request('GET', '/settlements/documents');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Документы');
        $client->request('GET', '/settlements/documents/'.$documentId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Акт №1');
        $allocationId = (string) $this->em()->getConnection()->fetchOne('SELECT id FROM settlement_allocations WHERE company_id=?', [$companyId]);
        $crawler = $client->request('GET', '/settlements/allocations/'.$allocationId.'/reverse');
        $form = $crawler->filter('form[name="settlement_event"]')->form();
        $form['settlement_event[effectiveDate]'] = '2026-11-04';
        $form['settlement_event[reason]'] = 'Отмена погашения';
        $client->submit($form);
        self::assertResponseRedirects();
        $crawler = $client->request('GET', '/settlements/counterparties/'.$partyId.'?from=2026-11-01&to=2026-11-02');
        self::assertResponseIsSuccessful();
        $historic = str_replace(["\u{00a0}", "\u{202f}"], ' ', $crawler->filter('.kpi')->eq(1)->text());
        self::assertStringContainsString('60 000,00', $historic);
        $crawler = $client->request('GET', '/settlements/counterparties/'.$partyId.'?from=2026-11-01&to=2026-11-04');
        self::assertResponseIsSuccessful();
        $current = str_replace(["\u{00a0}", "\u{202f}"], ' ', $crawler->filter('.kpi')->eq(1)->text());
        self::assertStringContainsString('100 000,00', $current);
    }
}
