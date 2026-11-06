<?php

declare(strict_types=1);

namespace App\Tests\Functional\Settlement;

use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Settlement\Application\ConfigureSettlementBookAction;
use App\Settlement\Application\CreateSettlementDocumentAction;
use App\Settlement\Application\DTO\CreateSettlementDocumentInput;
use App\Settlement\Application\PostSettlementDocumentAction;
use App\Settlement\Entity\SettlementDocument;
use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class SettlementAccessTest extends WebTestCaseBase
{
    /** @return array{KernelBrowser,string,string,string,string,string,string} */
    private function fixture(string $level): array
    {
        $client = self::createClient();
        $owner = UserBuilder::aUser()->build();
        $memberUser = UserBuilder::aUser()->withIndex(521)->withRoles(['ROLE_COMPANY_USER'])->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $party = CounterpartyBuilder::aCounterparty()->withCompany($company)->build();
        $role = new CompanyRole(Uuid::uuid7()->toString(), 'Финансы', '' === $level ? [] : ['finance' => $level], $company);
        $member = CompanyMemberBuilder::aMember()->withId(Uuid::uuid7()->toString())->withCompany($company)->withUser($memberUser)->withRole(CompanyMember::ROLE_OPERATOR)->withAccessRole($role)->build();
        foreach ([$owner, $memberUser, $company, $party, $role, $member] as $entity) {
            $this->em()->persist($entity);
        } $this->em()->flush();
        $companyId = (string) $company->getId();
        $actor = (string) $owner->getId();
        (self::getContainer()->get(ConfigureSettlementBookAction::class))($companyId, $actor, new \DateTimeImmutable('2026-11-01'));
        $draft = (self::getContainer()->get(CreateSettlementDocumentAction::class))($companyId, $actor, new CreateSettlementDocumentInput(Uuid::uuid7()->toString(), SettlementDocumentKind::OBLIGATION, $party->getId(), SettlementBucket::PAYABLE, Money::fromString('100.00', 'RUB'), new \DateTimeImmutable('2026-11-01'), null, 'Акт'));
        (self::getContainer()->get(PostSettlementDocumentAction::class))($companyId, $actor, $draft->documentId, $draft->version);
        $otherCompany = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->withName('Другая компания')->build();
        $otherParty = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid7()->toString())->withCompany($otherCompany)->withInn(null)->build();
        $otherCompanyId = (string) $otherCompany->getId();
        $foreign = new SettlementDocument($otherCompanyId, Uuid::uuid7()->toString(), SettlementDocumentKind::OBLIGATION, $otherParty->getId(), SettlementBucket::PAYABLE, Money::fromString('1.00', 'RUB'), new \DateTimeImmutable('2026-11-01'), null, 'Чужое основание', $actor);
        $cash = CashTransactionBuilder::aCashTransaction()->forCompany($otherCompany)->build();
        $cash->setCounterparty($otherParty);
        foreach ([$otherCompany, $otherParty, $foreign, $cash->getMoneyAccount(), $cash] as $entity) {
            $this->em()->persist($entity);
        } $this->em()->flush();
        $client->loginUser($memberUser);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);

        return [$client, $companyId, $party->getId(), $draft->documentId, (string) $role->getId(), $foreign->getId(), (string) $cash->getId()];
    }

    public function testReadOnlyMemberCanViewButCannotMutate(): void
    {
        [$client, $companyId, $party, $document] = $this->fixture('read');
        foreach (['/settlements', '/settlements/documents', '/settlements/counterparties/'.$party, '/settlements/documents/'.$document] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful();
        }
        self::assertSelectorNotExists('form[action$="/post"]');
        foreach (['/settlements/setup', '/settlements/documents/new', '/settlements/allocations/new', '/settlements/corrections/new', '/settlements/documents/'.$document.'/reverse'] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(403);
        }
        $client->request('POST', '/settlements/documents/'.$document.'/post', ['version' => '1']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM settlement_movements WHERE company_id=?', [$companyId]));
    }

    public function testNoFinanceAccessDeniesReading(): void
    {
        [$client] = $this->fixture('');
        $client->request('GET', '/settlements');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/settlements/documents');
        self::assertResponseStatusCodeSame(403);
    }

    public function testForeignResourcesAreNotFoundEvenForWriter(): void
    {
        [$client, $companyId, , , , $document, $cash] = $this->fixture('write');
        $client->request('GET', '/settlements/documents/'.$document);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/settlements/sources/cash/'.$cash);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/settlements/documents/'.$document.'/post');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/settlements/counterparties/'.Uuid::uuid7()->toString());
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM settlement_movements WHERE company_id=?', [$companyId]));
    }

    public function testRevokedWritePermissionRejectsPreviouslyOpenedForm(): void
    {
        [$client, $companyId, $party, , $role] = $this->fixture('write');
        $crawler = $client->request('GET', '/settlements/documents/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="settlement_document"]')->form();
        $form['settlement_document[counterpartyId]'] = $party;
        $form['settlement_document[amount]'] = '1.00';
        $form['settlement_document[effectiveDate]'] = '2026-11-01';
        $form['settlement_document[reason]'] = 'Новый акт';
        $this->em()->getConnection()->executeStatement('UPDATE company_role SET permissions=? WHERE id=? AND company_id=?', [json_encode(['finance' => 'read'], \JSON_THROW_ON_ERROR), $role, $companyId]);
        $client->submit($form);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM settlement_documents WHERE company_id=?', [$companyId]));
    }

    public function testCardAndChoicePreviewStayBoundedForManyInvoices(): void
    {
        [$client, $companyId, $party] = $this->fixture('write');
        $owner = (string) $this->em()->getConnection()->fetchOne('SELECT user_id FROM companies WHERE id=?', [$companyId]);
        $create = self::getContainer()->get(CreateSettlementDocumentAction::class);
        $post = self::getContainer()->get(PostSettlementDocumentAction::class);
        for ($i = 0; $i < 210; ++$i) {
            $draft = $create($companyId, $owner, new CreateSettlementDocumentInput(Uuid::uuid7()->toString(), SettlementDocumentKind::OBLIGATION, $party, SettlementBucket::PAYABLE, Money::fromString('1.00', 'RUB'), new \DateTimeImmutable('2026-11-01'), null, 'Акт '.$i));
            $post($companyId, $owner, $draft->documentId, $draft->version);
        }
        $crawler = $client->request('GET', '/settlements/counterparties/'.$party.'?limit=25');
        self::assertResponseIsSuccessful();
        self::assertLessThanOrEqual(100, $crawler->filter('.app-workzone tbody tr')->count(), 'Financial pages must not render every historical item.');
        $crawler = $client->request('GET', '/settlements/allocations/new?counterparty='.$party);
        self::assertResponseIsSuccessful();
        self::assertLessThanOrEqual(50, $crawler->filter('[aria-label="Основания и свободные оплаты сейчас"] tbody tr')->count());
        self::assertSelectorTextContains('body', '211');
    }

    public function testObligationCannotOpenPaymentClassificationForm(): void
    {
        [$client, $companyId, , $document] = $this->fixture('write');
        $item = (string) $this->em()->getConnection()->fetchOne('SELECT id FROM settlement_items WHERE company_id=? AND document_id=?', [$companyId, $document]);
        $client->request('GET', '/settlements/payments/'.$item.'/classify');
        self::assertResponseStatusCodeSame(404);
    }
}
