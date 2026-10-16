<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Экран «Документы ОПиУ»: итог по всей выборке, строки операций документа,
 * пустые состояния и сохранение фильтров в ссылках.
 */
final class DocumentIndexViewTest extends WebTestCaseBase
{
    public function testTotalsStripCoversWholeSelectionAcrossPages(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();
        [$income, $expense] = $this->persistCategories($company);

        for ($i = 0; $i < 22; ++$i) {
            $this->persistDocument($company, sprintf('D-%02d', $i), [[$income, '1000.00'], [$expense, '250.50']]);
        }

        $crawler = $this->requestIndex($client, $user, $company, []);

        self::assertCount(20, $crawler->filter('tbody[data-document-row]'));
        $strip = self::normalize($crawler->filter('.vf-docs__totals')->text());
        self::assertStringContainsString('Найдено 22 документа · 44 операции', $strip);
        self::assertStringContainsString('Доходы 22 000,00', $strip);
        self::assertStringContainsString('Расходы 5 511,00', $strip);
        self::assertStringContainsString('Результат 16 489,00', $strip);
    }

    public function testNegativeResultCarriesMinusSign(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();
        [$income, $expense] = $this->persistCategories($company);
        $this->persistDocument($company, 'LOSS', [[$income, '2600.00'], [$expense, '4200.00']]);

        $crawler = $this->requestIndex($client, $user, $company, []);

        self::assertStringContainsString("Результат \u{2212}1 600,00", self::normalize($crawler->filter('.vf-docs__totals')->text()));
    }

    public function testDocumentWithSeveralOperationsListsEachOperationRow(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();
        [$income, $expense] = $this->persistCategories($company);
        $this->persistDocument($company, 'MIXED', [[$income, '61079.35'], [$income, '30000.00'], [$expense, '1820.00'], [null, '5.00']]);
        $this->persistDocument($company, 'SINGLE', [[$expense, '3508.00']]);

        $crawler = $this->requestIndex($client, $user, $company, []);

        $mixed = $crawler->filter('tbody[data-document-row]')->reduce(static fn (Crawler $n): bool => str_contains($n->text(), 'MIXED'));
        self::assertCount(1, $mixed);
        self::assertSame('4 операции', trim($mixed->filter('details.vf-doc__toggle summary')->text()));
        self::assertCount(4, $mixed->filter('tr.vf-doc__op'));
        self::assertStringContainsString('Нужно указать статью', $mixed->filter('tr.vf-doc__op')->last()->text());
        // Операция без статьи в итог строки не входит: 91 079,35 − 1 820,00.
        self::assertSame('89 259,35', self::normalize($mixed->filter('.vf-doc__total')->text()));

        $single = $crawler->filter('tbody[data-document-row]')->reduce(static fn (Crawler $n): bool => str_contains($n->text(), 'SINGLE'));
        self::assertCount(0, $single->filter('details.vf-doc__toggle'));
        self::assertCount(0, $single->filter('tr.vf-doc__op'));
    }

    public function testEmptyCompanyShowsOnboardingAndFilteredEmptyShowsReset(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();

        $crawler = $this->requestIndex($client, $user, $company, []);
        self::assertSame('Документов ОПиУ пока нет', trim($crawler->filter('.vf-empty__title')->text()));
        self::assertCount(0, $crawler->filter('.vf-docs__totals'));

        $this->persistDocument($company, 'ONLY', []);
        $crawler = $this->requestIndex($client, $user, $company, ['q' => 'нет-такого']);
        self::assertSame('Документы не найдены', trim($crawler->filter('.vf-empty__title')->text()));
        self::assertCount(1, $crawler->filter('.vf-empty a[href="/documents/"]'));
        self::assertStringContainsString('Найдено 0 документов', self::normalize($crawler->filter('.vf-docs__totals')->text()));
    }

    public function testLinksKeepNormalizedFiltersAndDropGarbage(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();
        [$income] = $this->persistCategories($company);
        for ($i = 0; $i < 21; ++$i) {
            $this->persistDocument($company, sprintf('IN-%02d', $i), [[$income, '1.00']]);
        }

        $crawler = $this->requestIndex($client, $user, $company, [
            'direction' => 'income',
            'q' => 'IN-',
            'projectDirection' => 'not-a-uuid',
        ]);

        $next = $crawler->filter('.pagination a[href*="page=2"]')->first()->attr('href');
        self::assertNotNull($next);
        parse_str((string) parse_url($next, \PHP_URL_QUERY), $query);
        self::assertSame('income', $query['direction'] ?? null);
        self::assertSame('IN-', $query['q'] ?? null);
        self::assertArrayNotHasKey('projectDirection', $query, 'Мусор из адреса не должен переходить в ссылки.');

        // Чип снимает только свой фильтр.
        $chip = $crawler->filter('.vf-chip')->reduce(static fn (Crawler $n): bool => str_contains($n->text(), 'Доходы'));
        self::assertSame('/documents/?q=IN-', $chip->filter('a')->attr('href'));
    }

    /**
     * @param array<string, string> $query
     */
    private function requestIndex(KernelBrowser $client, User $user, Company $company, array $query): Crawler
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $crawler = $client->request('GET', '/documents/', $query);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));
    }

    /**
     * @return array{0: PLCategory, 1: PLCategory}
     */
    private function persistCategories(Company $company): array
    {
        $em = $this->em();
        $result = [];
        foreach ([['Выручка', PLFlow::INCOME], ['Аренда', PLFlow::EXPENSE]] as [$name, $flow]) {
            $category = PLCategoryBuilder::aPLCategory()
                ->withId(Uuid::uuid4()->toString())
                ->forCompany($company)
                ->withName($name.' '.Uuid::uuid4()->toString())
                ->withFlow($flow)
                ->build();
            $em->persist($category);
            $result[] = $category;
        }
        $em->flush();

        return [$result[0], $result[1]];
    }

    /**
     * @param list<array{0: PLCategory|null, 1: string}> $operations
     */
    private function persistDocument(Company $company, string $number, array $operations): Document
    {
        $em = $this->em();
        $document = new Document(Uuid::uuid4()->toString(), $company);
        $document->setNumber($number);
        $document->setDate(new \DateTimeImmutable('2026-10-08'));
        foreach ($operations as [$category, $amount]) {
            $operation = new DocumentOperation();
            $operation->setCategory($category);
            $operation->setAmount($amount);
            $document->addOperation($operation);
            $em->persist($operation);
        }
        $em->persist($document);
        $em->flush();

        return $document;
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function prepareCompanyContext(): array
    {
        $em = $this->em();
        $user = UserBuilder::aUser()->withIndex(random_int(1000, 9999))->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(random_int(1000, 9999))->withOwner($user)->build();
        $em->persist($user);
        $em->persist($company);
        $em->flush();

        return [$user, $company];
    }
}
