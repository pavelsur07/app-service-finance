<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Entity\User;
use App\Finance\Entity\Document;
use App\Finance\Enum\DocumentStatus;
use App\Finance\Enum\DocumentType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Регрессия: форма фильтров на /documents/ отправляла GET-параметры,
 * которые контроллер и репозиторий не читали — список всегда был полным.
 */
final class DocumentIndexFilterTest extends WebTestCaseBase
{
    public function testFiltersByDateRange(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();

        $this->persistDocument($company, ['number' => 'IN-RANGE', 'date' => '2024-05-10']);
        $this->persistDocument($company, ['number' => 'BEFORE-RANGE', 'date' => '2024-04-30']);
        $this->persistDocument($company, ['number' => 'AFTER-RANGE', 'date' => '2024-06-02']);

        $content = $this->requestIndex($client, $user, $company, [
            'dateFrom' => '2024-05-01',
            'dateTo' => '2024-06-01',
        ]);

        self::assertStringContainsString('IN-RANGE', $content);
        self::assertStringNotContainsString('BEFORE-RANGE', $content);
        self::assertStringNotContainsString('AFTER-RANGE', $content);
    }

    public function testDateToIncludesDocumentsOnTheBoundaryDay(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();

        $this->persistDocument($company, ['number' => 'BOUNDARY-DAY', 'date' => '2024-06-01 18:30:00']);

        $content = $this->requestIndex($client, $user, $company, [
            'dateFrom' => '2024-05-01',
            'dateTo' => '2024-06-01',
        ]);

        self::assertStringContainsString('BOUNDARY-DAY', $content);
    }

    public function testFiltersByType(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();

        $this->persistDocument($company, ['number' => 'PAYROLL-DOC', 'type' => DocumentType::PAYROLL]);
        $this->persistDocument($company, ['number' => 'TAXES-DOC', 'type' => DocumentType::TAXES]);

        $content = $this->requestIndex($client, $user, $company, ['type' => 'PAYROLL']);

        self::assertStringContainsString('PAYROLL-DOC', $content);
        self::assertStringNotContainsString('TAXES-DOC', $content);
    }

    /**
     * Выпадающий список типов обязан покрывать enum целиком: тип, которого в нём нет,
     * отфильтровать через интерфейс нельзя, хотя бэкенд такое значение принимает.
     */
    public function testTypeFilterOffersEveryDocumentType(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $crawler = $client->request('GET', '/documents/');

        self::assertResponseIsSuccessful();

        $offered = $crawler->filter('#document-filter-type option')->each(
            static fn (Crawler $option): string => (string) $option->attr('value'),
        );
        $offered = array_values(array_filter($offered, static fn (string $value): bool => '' !== $value));

        $expected = array_map(static fn (DocumentType $type): string => $type->value, DocumentType::cases());

        sort($offered);
        sort($expected);
        self::assertSame($expected, $offered);

        // У типа без короткой подписи берётся собственная подпись enum, а не пустая строка.
        $marketplaceOption = $crawler->filter(
            \sprintf('#document-filter-type option[value="%s"]', DocumentType::MARKETPLACE_PL->value),
        );
        self::assertSame(DocumentType::MARKETPLACE_PL->label(), trim($marketplaceOption->text()));
    }

    public function testFiltersByMarketplaceType(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();

        $this->persistDocument($company, ['number' => 'MP-DOC', 'type' => DocumentType::MARKETPLACE_PL]);
        $this->persistDocument($company, ['number' => 'OTHER-DOC', 'type' => DocumentType::OTHER]);

        $content = $this->requestIndex($client, $user, $company, ['type' => DocumentType::MARKETPLACE_PL->value]);

        self::assertStringContainsString('MP-DOC', $content);
        self::assertStringNotContainsString('OTHER-DOC', $content);
    }

    public function testFiltersByStatus(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();

        $this->persistDocument($company, ['number' => 'DRAFT-DOC', 'status' => DocumentStatus::DRAFT]);
        $this->persistDocument($company, ['number' => 'ACTIVE-DOC', 'status' => DocumentStatus::ACTIVE]);

        $content = $this->requestIndex($client, $user, $company, ['status' => 'DRAFT']);

        self::assertStringContainsString('DRAFT-DOC', $content);
        self::assertStringNotContainsString('ACTIVE-DOC', $content);
    }

    public function testFiltersByNumberSubstringIgnoringCase(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();

        $this->persistDocument($company, ['number' => 'ACT-2024-777']);
        $this->persistDocument($company, ['number' => 'INV-2024-111']);

        $content = $this->requestIndex($client, $user, $company, ['number' => 'act-2024']);

        self::assertStringContainsString('ACT-2024-777', $content);
        self::assertStringNotContainsString('INV-2024-111', $content);
    }

    public function testNumberFilterTreatsLikeWildcardsAsLiteralCharacters(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();

        $this->persistDocument($company, ['number' => 'ACT%111']);
        $this->persistDocument($company, ['number' => 'ACT-2024-111']);

        $content = $this->requestIndex($client, $user, $company, ['number' => 'ACT%111']);

        self::assertStringContainsString('ACT%111', $content);
        self::assertStringNotContainsString('ACT-2024-111', $content);
    }

    public function testFiltersByCounterpartyName(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();

        $matching = $this->persistCounterparty($company, 'Ромашка Торг');
        $other = $this->persistCounterparty($company, 'Василёк Логистика');

        $this->persistDocument($company, ['number' => 'CP-MATCH', 'counterparty' => $matching]);
        $this->persistDocument($company, ['number' => 'CP-OTHER', 'counterparty' => $other]);

        $content = $this->requestIndex($client, $user, $company, ['counterparty' => 'ромашка']);

        self::assertStringContainsString('CP-MATCH', $content);
        self::assertStringNotContainsString('CP-OTHER', $content);
    }

    public function testCombinesSeveralFilters(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();

        $this->persistDocument($company, [
            'number' => 'BOTH-MATCH',
            'type' => DocumentType::PAYROLL,
            'date' => '2024-05-10',
        ]);
        $this->persistDocument($company, [
            'number' => 'TYPE-ONLY',
            'type' => DocumentType::PAYROLL,
            'date' => '2024-01-10',
        ]);
        $this->persistDocument($company, [
            'number' => 'DATE-ONLY',
            'type' => DocumentType::TAXES,
            'date' => '2024-05-11',
        ]);

        $content = $this->requestIndex($client, $user, $company, [
            'type' => 'PAYROLL',
            'dateFrom' => '2024-05-01',
        ]);

        self::assertStringContainsString('BOTH-MATCH', $content);
        self::assertStringNotContainsString('TYPE-ONLY', $content);
        self::assertStringNotContainsString('DATE-ONLY', $content);
    }

    public function testUnknownFilterValuesDoNotHideEverything(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();

        $this->persistDocument($company, ['number' => 'STILL-VISIBLE']);

        $content = $this->requestIndex($client, $user, $company, [
            'type' => 'NOT_A_TYPE',
            'status' => 'NOT_A_STATUS',
        ]);

        self::assertStringContainsString('STILL-VISIBLE', $content);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function nonCalendarDateProvider(): iterable
    {
        yield 'несуществующий день' => ['2024-02-30'];
        yield 'относительное выражение' => ['tomorrow'];
        yield 'частичная дата' => ['2024-05'];
        yield 'год вне диапазона PostgreSQL' => ['0000-01-01'];
        yield 'произвольный текст' => ['обычный мусор'];
    }

    #[DataProvider('nonCalendarDateProvider')]
    public function testDateFilterIgnoresValuesThatAreNotCalendarDates(string $value): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();

        $this->persistDocument($company, ['number' => 'STILL-VISIBLE', 'date' => '2024-01-15']);

        $content = $this->requestIndex($client, $user, $company, ['dateFrom' => $value]);

        self::assertStringContainsString('STILL-VISIBLE', $content);
    }

    public function testFilteredListStaysScopedToActiveCompany(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();

        $otherOwner = UserBuilder::aUser()->withIndex(random_int(1000, 9999))->asCompanyOwner()->build();
        $otherCompany = CompanyBuilder::aCompany()->withIndex(random_int(1000, 9999))->withOwner($otherOwner)->build();

        $em = $this->em();
        $em->persist($otherOwner);
        $em->persist($otherCompany);
        $em->flush();

        $this->persistDocument($company, ['number' => 'OWN-DOC', 'type' => DocumentType::PAYROLL]);
        $this->persistDocument($otherCompany, ['number' => 'FOREIGN-DOC', 'type' => DocumentType::PAYROLL]);

        $content = $this->requestIndex($client, $user, $company, ['type' => 'PAYROLL']);

        self::assertStringContainsString('OWN-DOC', $content);
        self::assertStringNotContainsString('FOREIGN-DOC', $content);
    }

    public function testChangingPageSizeKeepsActiveFilters(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();

        $this->persistDocument($company, ['number' => 'PAYROLL-DOC', 'type' => DocumentType::PAYROLL]);
        $this->persistDocument($company, ['number' => 'TAXES-DOC', 'type' => DocumentType::TAXES]);

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $crawler = $client->request('GET', '/documents/', ['type' => 'PAYROLL']);

        self::assertResponseIsSuccessful();

        $form = $crawler->filter('#document-limit-form')->form();
        $client->submit($form, ['limit' => '50']);

        self::assertResponseIsSuccessful();
        $content = self::tableText($client);
        self::assertStringContainsString('PAYROLL-DOC', $content);
        self::assertStringNotContainsString('TAXES-DOC', $content);
    }

    /**
     * «0» — непустое значение фильтра, а не отсутствие фильтра.
     */
    public function testChangingPageSizeKeepsFilterWhoseValueIsZero(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();

        $this->persistDocument($company, ['number' => '0']);
        $this->persistDocument($company, ['number' => 'INV-777']);

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $crawler = $client->request('GET', '/documents/', ['number' => '0']);

        self::assertResponseIsSuccessful();

        $form = $crawler->filter('#document-limit-form')->form();
        $client->submit($form, ['limit' => '50']);

        self::assertResponseIsSuccessful();
        $content = self::tableText($client);
        self::assertStringNotContainsString('INV-777', $content);
    }

    /**
     * @param array<string, string> $query
     */
    private function requestIndex(KernelBrowser $client, User $user, Company $company, array $query): string
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $client->request('GET', '/documents/', $query);

        self::assertResponseIsSuccessful();

        return self::tableText($client);
    }

    /**
     * Текст строк таблицы. Форма фильтров возвращает введённое значение в поле ввода,
     * поэтому проверка по всему HTML прошла бы и тогда, когда документа в списке нет.
     */
    private static function tableText(KernelBrowser $client): string
    {
        return $client->getCrawler()->filter('table tbody')->first()->text();
    }

    /**
     * @param array{number?: string, date?: string, type?: DocumentType, status?: DocumentStatus, counterparty?: Counterparty} $attributes
     */
    private function persistDocument(Company $company, array $attributes): Document
    {
        $document = new Document(Uuid::uuid4()->toString(), $company);
        $document->setNumber($attributes['number'] ?? null);
        $document->setDate(new \DateTimeImmutable($attributes['date'] ?? '2024-05-15'));
        $document->setType($attributes['type'] ?? DocumentType::OTHER);
        $document->setStatus($attributes['status'] ?? DocumentStatus::ACTIVE);
        $document->setCounterparty($attributes['counterparty'] ?? null);

        $em = $this->em();
        $em->persist($document);
        $em->flush();

        return $document;
    }

    private function persistCounterparty(Company $company, string $name): Counterparty
    {
        $counterparty = CounterpartyBuilder::aCounterparty()
            ->withId(Uuid::uuid4()->toString())
            ->withCompany($company)
            ->withName($name)
            ->build();

        $em = $this->em();
        $em->persist($counterparty);
        $em->flush();

        return $counterparty;
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function prepareCompanyContext(): array
    {
        $this->resetDb();
        $em = $this->em();

        $user = UserBuilder::aUser()
            ->withIndex(random_int(1000, 9999))
            ->asCompanyOwner()
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withIndex(random_int(1000, 9999))
            ->withOwner($user)
            ->build();

        $em->persist($user);
        $em->persist($company);
        $em->flush();

        return [$user, $company];
    }
}
