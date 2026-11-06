<?php

declare(strict_types=1);

namespace App\Tests\Functional\Settlement;

use App\Settlement\Application\ConfigureSettlementBookAction;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class SettlementValidationTest extends WebTestCaseBase
{
    /** @return array{KernelBrowser,string,string} */
    private function fixture(): array
    {
        $client = self::createClient();
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $party = CounterpartyBuilder::aCounterparty()->withCompany($company)->build();
        foreach ([$user, $company, $party] as $entity) {
            $this->em()->persist($entity);
        } $this->em()->flush();
        $companyId = (string) $company->getId();
        (self::getContainer()->get(ConfigureSettlementBookAction::class))($companyId, (string) $user->getId(), new \DateTimeImmutable('2026-11-01'));
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);

        return [$client, $companyId, $party->getId()];
    }

    /** @return iterable<string,array{string,string}> */
    public static function invalidDocuments(): iterable
    {
        yield 'precision' => ['amount', '1.001'];
        yield 'overflow' => ['amount', '92233720368547758.08'];
        yield 'negative' => ['amount', '-1.00'];
        yield 'csrf' => ['_token', 'invalid'];
        yield 'blank kind' => ['kind', ''];
        yield 'blank bucket' => ['bucket', ''];
        yield 'blank counterparty' => ['counterpartyId', ''];
    }

    #[DataProvider('invalidDocuments')]
    public function testInvalidDocumentPreservesFormAndDoesNotWrite(string $field, string $value): void
    {
        [$client, $companyId, $party] = $this->fixture();
        $crawler = $client->request('GET', '/settlements/documents/new');
        $form = $crawler->filter('form[name="settlement_document"]')->form();
        $form['settlement_document[counterpartyId]'] = $party;
        $form['settlement_document[amount]'] = '100.00';
        $form['settlement_document[effectiveDate]'] = '2026-11-02';
        $form['settlement_document[reason]'] = 'Сохранить введённое основание';
        $payload = $form->getPhpValues();
        $payload['settlement_document'][$field] = $value;
        $client->request('POST', $form->getUri(), $payload);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('form[name="settlement_document"]');
        self::assertSelectorExists('[role="alert"]');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM settlement_documents WHERE company_id=?', [$companyId]));
    }

    public function testInvalidPeriodAndPageAreVisibleErrors(): void
    {
        [$client] = $this->fixture();
        $client->request('GET', '/settlements?from=2026-11-06&to=2026-11-01');
        self::assertResponseStatusCodeSame(422);
        $client->request('GET', '/settlements?page=invalid');
        self::assertResponseStatusCodeSame(422);
        $client->request('GET', '/settlements/documents?limit=201');
        self::assertResponseStatusCodeSame(422);
        $client->request('GET', '/settlements?from=&to=&limit=');
        self::assertResponseStatusCodeSame(422);
    }

    /** @return iterable<string,array{string}> */
    public static function rejectedPosting(): iterable
    {
        yield 'closed period' => ['closed'];
        yield 'stale version' => ['version'];
        yield 'csrf' => ['csrf'];
    }

    #[DataProvider('rejectedPosting')]
    public function testPostingGuardDoesNotCreateMovements(string $case): void
    {
        [$client, $companyId, $party] = $this->fixture();
        $crawler = $client->request('GET', '/settlements/documents/new');
        $form = $crawler->filter('form[name="settlement_document"]')->form();
        $form['settlement_document[counterpartyId]'] = $party;
        $form['settlement_document[amount]'] = '100.00';
        $form['settlement_document[effectiveDate]'] = '2026-11-01';
        $form['settlement_document[reason]'] = 'Акт';
        $client->submit($form);
        self::assertResponseRedirects();
        $id = (string) $this->em()->getConnection()->fetchOne('SELECT id FROM settlement_documents WHERE company_id=?', [$companyId]);
        if ('closed' === $case) {
            $this->em()->getConnection()->executeStatement("UPDATE companies SET finance_lock_before='2026-11-02' WHERE id=?", [$companyId]);
        }
        $client->request('POST', '/settlements/documents/'.$id.'/post', ['version' => 'version' === $case ? '2' : '1', '_token' => 'csrf' === $case ? 'invalid' : $this->csrfToken($client, 'settlement_post'.$id)]);
        if ('csrf' === $case) {
            self::assertResponseStatusCodeSame(403);
        } else {
            self::assertResponseRedirects();
        }
        self::assertSame('draft', $this->em()->getConnection()->fetchOne('SELECT status FROM settlement_documents WHERE company_id=? AND id=?', [$companyId, $id]));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM settlement_movements WHERE company_id=?', [$companyId]));
    }

    public function testSetupFormCannotConfigureAnotherCompanyAfterSwitchingTabs(): void
    {
        $client = self::createClient();
        $owner = UserBuilder::aUser()->build();
        $first = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $second = CompanyBuilder::aCompany()->withId(\Ramsey\Uuid\Uuid::uuid7()->toString())->withOwner($owner)->withName('Вторая компания')->build();
        foreach ([$owner, $first, $second] as $entity) {
            $this->em()->persist($entity);
        } $this->em()->flush();
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', (string) $first->getId());
        $crawler = $client->request('GET', '/settlements/setup');
        $form = $crawler->filter('form[name="settlement_book"]')->form();
        $form['settlement_book[startDate]'] = '2026-11-01';
        $this->setClientSessionValue($client, 'active_company_id', (string) $second->getId());
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM settlement_books WHERE company_id IN (?,?)', [$first->getId(), $second->getId()]));
    }

    public function testConfiguredBookRejectsPostsToDisabledForm(): void
    {
        [$client, $companyId] = $this->fixture();
        $client->request('POST', '/settlements/setup', ['settlement_book' => ['startDate' => '2026-11-02', '_token' => 'invalid']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('2026-11-01', $this->em()->getConnection()->fetchOne('SELECT start_date FROM settlement_books WHERE company_id=?', [$companyId]));
    }
}
