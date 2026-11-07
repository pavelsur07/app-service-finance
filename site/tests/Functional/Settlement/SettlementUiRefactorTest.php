<?php

declare(strict_types=1);

namespace App\Tests\Functional\Settlement;

use App\Settlement\Application\ConfigureSettlementBookAction;
use App\Settlement\Application\CreateSettlementDocumentAction;
use App\Settlement\Application\DTO\CreateSettlementDocumentInput;
use App\Settlement\Application\PostSettlementDocumentAction;
use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Enum\SettlementDocumentKind;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class SettlementUiRefactorTest extends WebTestCaseBase
{
    /** @return array{KernelBrowser,string,string,string} */
    private function fixture(int $documents = 1, bool $readonly = false): array
    {
        $client = self::createClient();
        $owner = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $party = CounterpartyBuilder::aCounterparty()->withCompany($company)->withName('Контрагент для UI')->build();
        foreach ([$owner, $company, $party] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $companyId = (string) $company->getId();
        $actor = (string) $owner->getId();
        (self::getContainer()->get(ConfigureSettlementBookAction::class))($companyId, $actor, new \DateTimeImmutable('2026-11-01'));
        $create = self::getContainer()->get(CreateSettlementDocumentAction::class);
        $post = self::getContainer()->get(PostSettlementDocumentAction::class);
        for ($i = 0; $i < max(1, $documents); ++$i) {
            $draft = $create($companyId, $actor, new CreateSettlementDocumentInput(Uuid::uuid7()->toString(), SettlementDocumentKind::OBLIGATION, $party->getId(), SettlementBucket::PAYABLE, Money::fromString('0.30', 'RUB'), new \DateTimeImmutable('2026-11-01'), null, 'Акт UI '.$i));
            $post($companyId, $actor, $draft->documentId, $draft->version);
        }
        if ($readonly) {
            $reader = UserBuilder::aUser()->withIndex(523)->withRoles(['ROLE_COMPANY_USER'])->build();
            $role = new \App\Company\Entity\CompanyRole(Uuid::uuid7()->toString(), 'Чтение', ['finance' => 'read'], $company);
            $member = \App\Tests\Builders\Company\CompanyMemberBuilder::aMember()->withId(Uuid::uuid7()->toString())->withCompany($company)->withUser($reader)->withAccessRole($role)->build();
            foreach ([$reader, $role, $member] as $entity) {
                $this->em()->persist($entity);
            }
            $this->em()->flush();
            $client->loginUser($reader);
        } else {
            $client->loginUser($owner);
        }
        $this->setClientSessionValue($client, 'active_company_id', $companyId);

        return [$client, $companyId, $party->getId(), $draft->documentId];
    }

    public function testUsesCashTransactionsShellAndExistingNavigation(): void
    {
        [$client] = $this->fixture();
        $client->request('GET', '/settlements');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[data-ui-mode="legacy"]');
        self::assertSelectorTextContains('.settlement-ui h1', 'Взаиморасчёты');
        self::assertSelectorExists('a[href="/settlements/documents"]');
        self::assertSelectorExists('a[href="/settlements/setup"]');
    }

    public function testReportFilterKeepsExistingQueryNamesAndResetsPage(): void
    {
        [$client, , $party] = $this->fixture();
        $crawler = $client->request('GET', '/settlements?from=2026-11-01&to=2026-11-07&counterparty='.$party.'&limit=25&page=1');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[data-settlement-filter]');
        self::assertCount(1, $form);
        foreach (['from', 'to', 'limit', 'counterparty'] as $name) {
            self::assertCount(1, $form->filter('[name="'.$name.'"]'));
        }
        self::assertCount(0, $form->filter('[name="page"], [name="dateFrom"], [name="dateTo"]'));
        $reset = (string) $crawler->filter('a[data-settlement-reset]')->attr('href');
        parse_str((string) parse_url($reset, \PHP_URL_QUERY), $query);
        self::assertSame('25', $query['limit']);
        self::assertArrayNotHasKey('counterparty', $query);
        self::assertArrayNotHasKey('page', $query);
    }

    public function testCardPaginationKeepsRouteIdAndPeriod(): void
    {
        [$client, , $party] = $this->fixture(26);
        $crawler = $client->request('GET', '/settlements/counterparties/'.$party.'?id='.Uuid::uuid7()->toString().'&from=2026-11-01&to=2026-11-07&limit=25');
        self::assertResponseIsSuccessful();
        $next = (string) $crawler->filter('a[aria-label="Вперёд"]')->attr('href');
        self::assertSame('/settlements/counterparties/'.$party, parse_url($next, \PHP_URL_PATH));
        parse_str((string) parse_url($next, \PHP_URL_QUERY), $query);
        self::assertSame('2', $query['page']);
        self::assertSame('2026-11-01', $query['from']);
        self::assertSame('2026-11-07', $query['to']);
        self::assertSame('25', $query['limit']);
        self::assertSelectorNotExists('[name="counterparty"]');
        $client->request('GET', $next);
        self::assertResponseIsSuccessful();
    }

    public function testCounterpartyFieldUsesExistingPickerAndNativeFallback(): void
    {
        [$client, , $party] = $this->fixture();
        $crawler = $client->request('GET', '/settlements/documents/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-cp-picker][data-cp-browse="1"][data-cp-search-url="/api/counterparties/search"] select[data-cp-select][required]');
        self::assertCount(1, $crawler->filter('select[name="settlement_document[counterpartyId]"] option[value="'.$party.'"]'));
        self::assertSelectorExists('[data-cp-search-wrap].d-none');
    }

    public function testSummaryKeepsSixSeparateExactBalances(): void
    {
        [$client] = $this->fixture();
        $crawler = $client->request('GET', '/settlements?from=2026-11-01&to=2026-11-07');
        self::assertResponseIsSuccessful();
        self::assertCount(6, $crawler->filter('[aria-label="Остатки на конец периода"] .card'));
        self::assertSelectorTextContains('[aria-label="Остатки на конец периода"]', 'К оплате');
        self::assertSelectorTextContains('[aria-label="Остатки на конец периода"]', '0,30');
        foreach (['Нам должны', 'К оплате', 'Выданные авансы', 'Полученные авансы', 'Нераспределённые выплаты', 'Нераспределённые поступления'] as $label) {
            self::assertSelectorTextContains('[aria-label="Остатки на конец периода"]', $label);
        }
    }

    public function testDocumentKeepsReversalNavigationAndExactAmount(): void
    {
        [$client, , $party, $document] = $this->fixture();
        $crawler = $client->request('GET', '/settlements/documents/'.$document);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.settlement-ui', '0,30');
        self::assertCount(1, $crawler->filter('a[href="/settlements/documents/'.$document.'/reverse"]'));
        self::assertCount(1, $crawler->filter('.settlement-ui a[href="/settlements/counterparties/'.$party.'"]'));
        self::assertSelectorTextContains('.settlement-ui .badge', 'Проведён');
    }

    public function testFormsKeepHiddenFieldsAndInputOnFailure(): void
    {
        [$client, $companyId, $party] = $this->fixture();
        $crawler = $client->request('GET', '/settlements/documents/new');
        foreach (['_token', 'requestId'] as $field) {
            self::assertCount(1, $crawler->filter('input[name="settlement_document['.$field.']"]'));
        }
        self::assertCount(1, $crawler->filter('fieldset legend')->reduce(static fn ($node) => 'Сумма и даты' === trim($node->text())));
        $form = $crawler->filter('form[name="settlement_document"]')->form();
        $form['settlement_document[counterpartyId]'] = $party;
        $form['settlement_document[amount]'] = '0.001';
        $form['settlement_document[effectiveDate]'] = '2026-11-02';
        $form['settlement_document[reason]'] = 'Причина сохраняется';
        $crawler = $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[role="alert"]');
        self::assertSame('0.001', $crawler->filter('[name="settlement_document[amount]"]')->attr('value'));
        self::assertSelectorTextContains('textarea', 'Причина сохраняется');
        self::assertSame($party, $crawler->filter('[name="settlement_document[counterpartyId]"] option[selected]')->attr('value'));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM settlement_documents WHERE company_id=?', [$companyId]));
    }

    public function testReadonlyKeepsReadFiltersAndNoWriteActions(): void
    {
        [$client] = $this->fixture(1, true);
        $client->request('GET', '/settlements');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-settlement-filter] button[type="submit"]');
        self::assertSelectorNotExists('.settlement-ui a[href="/settlements/documents/new"]');
        foreach (['/settlements/documents/new', '/settlements/setup'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('.settlement-ui button[type="submit"]');
        }
    }
}
