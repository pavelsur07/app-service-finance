<?php

declare(strict_types=1);

namespace App\Tests\Functional\Settlement;

use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Settlement\Application\ConfigureSettlementBookAction;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class SettlementSourceFormsTest extends WebTestCaseBase
{
    /** @return array{KernelBrowser,string,string,string,string} */
    private function fixture(): array
    {
        $client = self::createClient();
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $party = CounterpartyBuilder::aCounterparty()->withCompany($company)->build();
        $cash = CashTransactionBuilder::aCashTransaction()->forCompany($company)->withAmount('40000.00')->build();
        $cash->setCounterparty($party)->setOccurredAt(new \DateTimeImmutable('2026-11-01'));
        $finance = (new Document(Uuid::uuid7()->toString(), $company))->setDate(new \DateTimeImmutable('2026-11-01'))->setCounterparty($party);
        $operation = (new DocumentOperation())->setDocument($finance)->setAmount('100000.00');
        foreach ([$owner, $company, $party, $cash->getMoneyAccount(), $cash, $finance, $operation] as $entity) {
            $this->em()->persist($entity);
        } $this->em()->flush();
        $companyId = (string) $company->getId();
        (self::getContainer()->get(ConfigureSettlementBookAction::class))($companyId, (string) $owner->getId(), new \DateTimeImmutable('2026-11-01'));
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);

        return [$client, $companyId, $party->getId(), (string) $cash->getId(), (string) $finance->getId()];
    }

    public function testFinanceSourceNeedsAnExplicitFullAmountAndSeparatePosting(): void
    {
        [$client, $company, , , $source] = $this->fixture();
        $crawler = $client->request('GET', '/settlements/sources/finance/'.$source);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="settlement_source"]')->form();
        $amountField = $form['settlement_source[amount]'];
        self::assertInstanceOf(\Symfony\Component\DomCrawler\Field\FormField::class, $amountField);
        self::assertSame('', $amountField->getValue());
        $form['settlement_source[amount]'] = '120000.00';
        $client->submit($form);
        self::assertResponseRedirects();
        $id = (string) $this->em()->getConnection()->fetchOne('SELECT id FROM settlement_documents WHERE company_id=?', [$company]);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM settlement_movements WHERE company_id=?', [$company]));
        $client->request('POST', '/settlements/documents/'.$id.'/post', ['version' => '1', '_token' => $this->csrfToken($client, 'settlement_post'.$id)]);
        self::assertResponseRedirects();
        self::assertSame('12000000', (string) $this->em()->getConnection()->fetchOne('SELECT SUM(delta_amount_minor) FROM settlement_movements WHERE company_id=?', [$company]));
        self::assertSame('100000.00', $this->em()->getConnection()->fetchOne('SELECT amount FROM document_operations WHERE document_id=?', [$source]));
        $client->request('GET', '/settlements/sources/finance/'.$source);
        self::assertSelectorTextContains('body', 'уже участвует');
        self::assertSelectorNotExists('form[name="settlement_source"]');
        $crawler = $client->request('GET', '/settlements/documents/'.$id.'/reverse');
        $form = $crawler->filter('form[name="settlement_event"]')->form();
        $form['settlement_event[effectiveDate]'] = '2026-11-03';
        $form['settlement_event[reason]'] = 'Отмена основания';
        $client->submit($form);
        self::assertResponseRedirects();
        $crawler = $client->request('GET', '/settlements/sources/finance/'.$source);
        self::assertResponseIsSuccessful();
        self::assertSame('2026-11-03', $crawler->filter('input[name="settlement_source[effectiveDate]"]')->attr('value'));
    }

    public function testAdvanceClassificationAndOpeningCorrectionHaveSeparateForms(): void
    {
        [$client, $company, $party, $cash] = $this->fixture();
        $crawler = $client->request('GET', '/settlements/sources/cash/'.$cash);
        $form = $crawler->filter('form[name="settlement_source"]')->form();
        $advanceField = $form['settlement_source[isAdvance]'];
        self::assertInstanceOf(\Symfony\Component\DomCrawler\Field\ChoiceFormField::class, $advanceField);
        $advanceField->tick();
        $client->submit($form);
        self::assertResponseRedirects();
        self::assertSame('4000000', (string) $this->em()->getConnection()->fetchOne("SELECT SUM(delta_amount_minor) FROM settlement_movements WHERE company_id=? AND bucket='advance_given'", [$company]));
        $payment = (string) $this->em()->getConnection()->fetchOne("SELECT id FROM settlement_items WHERE company_id=? AND bucket='advance_given'", [$company]);
        $crawler = $client->request('GET', '/settlements/payments/'.$payment.'/classify');
        $form = $crawler->filter('form[name="settlement_classification"]')->form();
        $form['settlement_classification[isAdvance]'] = '0';
        $form['settlement_classification[reason]'] = 'Аванс не подтверждён';
        $client->submit($form);
        self::assertResponseRedirects();
        self::assertSame('4000000', (string) $this->em()->getConnection()->fetchOne("SELECT SUM(delta_amount_minor) FROM settlement_movements WHERE company_id=? AND bucket='unallocated_out'", [$company]));
        $crawler = $client->request('GET', '/settlements/documents/new');
        $form = $crawler->filter('form[name="settlement_document"]')->form();
        $form['settlement_document[counterpartyId]'] = $party;
        $form['settlement_document[kind]'] = 'opening';
        $form['settlement_document[bucket]'] = 'advance_given';
        $form['settlement_document[amount]'] = '5000.00';
        $form['settlement_document[effectiveDate]'] = '2026-11-01';
        $form['settlement_document[reason]'] = 'Начальный аванс';
        $client->submit($form);
        self::assertResponseRedirects();
        $opening = (string) $this->em()->getConnection()->fetchOne("SELECT id FROM settlement_documents WHERE company_id=? AND kind='opening'", [$company]);
        $client->request('POST', '/settlements/documents/'.$opening.'/post', ['version' => '1', '_token' => $this->csrfToken($client, 'settlement_post'.$opening)]);
        self::assertResponseRedirects();
        $item = (string) $this->em()->getConnection()->fetchOne('SELECT id FROM settlement_items WHERE company_id=? AND document_id=?', [$company, $opening]);
        $crawler = $client->request('GET', '/settlements/corrections/new?counterparty='.$party);
        $form = $crawler->filter('form[name="settlement_correction"]')->form();
        self::assertCount(0, $crawler->filter('select[name="settlement_correction[itemId]"] option[value="'.$payment.'"]'));
        $form['settlement_correction[itemId]'] = $item;
        $form['settlement_correction[increase]'] = '0';
        $form['settlement_correction[amount]'] = '1000.00';
        $form['settlement_correction[reason]'] = 'Уточнение начального остатка';
        $client->submit($form);
        self::assertResponseRedirects();
        self::assertSame('400000', (string) $this->em()->getConnection()->fetchOne("SELECT SUM(delta_amount_minor) FROM settlement_movements WHERE company_id=? AND bucket='advance_given'", [$company]));
        self::assertSame('40000.00', $this->em()->getConnection()->fetchOne('SELECT amount FROM cash_transaction WHERE company_id=? AND id=?', [$company, $cash]));
    }
}
