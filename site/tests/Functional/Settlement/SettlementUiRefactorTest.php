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
    private function fixture(int $documents = 1): array
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
        $client->loginUser($owner);
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
}
